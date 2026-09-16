#!/usr/bin/env python3
"""Exercise B2 over verified local HTTPS without exposing authentication secrets.

Mailpit's API contract: https://mailpit.axllent.org/docs/api-v1/
The cleanup manifest contains only generated test-account identifiers. The caller
owns cleanup and must supply a new manifest path for each run.
"""

from __future__ import annotations

import argparse
import base64
import copy
import hashlib
import hmac
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import ssl
import stat
import struct
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


API = "/api/v1"
SESSION_COOKIE = "__Host-holoul_session"
MAX_RESPONSE = 1_048_576
CHECKS: list[str] = []
STAGE = "configuration"


class SmokeFailure(Exception):
    """Only fixed, non-secret error codes may be placed in this exception."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def require(condition: bool, code: str) -> None:
    if not condition:
        raise SmokeFailure(code)


def completed(name: str) -> None:
    CHECKS.append(name)


def decode_response(response) -> tuple[int, object, object]:
    raw = response.read(MAX_RESPONSE + 1)
    require(len(raw) <= MAX_RESPONSE, "response_too_large")
    status = response.code
    if status == 204 and not raw:
        return status, None, response.headers
    try:
        document = json.loads(raw)
    except (UnicodeError, ValueError):
        raise SmokeFailure("response_not_json") from None
    return status, document, response.headers


class Browser:
    def __init__(self, origin: str, context: ssl.SSLContext, jar=None):
        self.origin = origin
        self.context = context
        self.jar = jar if jar is not None else http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPSHandler(context=context),
            urllib.request.HTTPCookieProcessor(self.jar),
            NoRedirect(),
        )

    def snapshot(self) -> Browser:
        jar = http.cookiejar.CookieJar()
        for cookie in self.jar:
            jar.set_cookie(copy.copy(cookie))
        return Browser(self.origin, self.context, jar)

    def cookie(self, name: str):
        cookies = [cookie for cookie in self.jar if cookie.name == name]
        require(len(cookies) == 1, "required_cookie_missing_or_ambiguous")
        return cookies[0]

    def call(self, method: str, path: str, expected: int, body=None, *, csrf=True, origin=None):
        require(path.startswith("/") and not path.startswith("//"), "invalid_relative_path")
        headers = {
            "Accept": "application/json",
            "Origin": self.origin if origin is None else origin,
            "Referer": self.origin + "/",
            "Sec-Fetch-Site": "same-origin",
        }
        if method not in ("GET", "HEAD") and csrf:
            headers["X-XSRF-TOKEN"] = urllib.parse.unquote(self.cookie("XSRF-TOKEN").value)
        encoded = None if body is None else json.dumps(body).encode("utf-8")
        if encoded is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(self.origin + path, data=encoded, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        except (OSError, urllib.error.URLError, TimeoutError):
            raise SmokeFailure("https_transport_failed") from None
        with response:
            status, document, response_headers = decode_response(response)
        require(status == expected, "unexpected_http_status_" + str(status) + "_expected_" + str(expected))
        require("no-store" in response_headers.get("Cache-Control", "").lower(), "response_cache_policy_missing")
        return document

    def csrf(self) -> None:
        self.call("GET", "/sanctum/csrf-cookie", 204)


def validate_cookie_flags(browser: Browser) -> None:
    session = browser.cookie(SESSION_COOKIE)
    xsrf = browser.cookie("XSRF-TOKEN")
    for cookie in (session, xsrf):
        attributes = {key.lower(): value for key, value in cookie._rest.items()}
        require(cookie.secure and cookie.path == "/" and not cookie.domain_specified, "unsafe_cookie_scope")
        require(str(attributes.get("samesite", "")).lower() == "lax", "unsafe_cookie_samesite")
    require(any(key.lower() == "httponly" for key in session._rest), "session_cookie_not_httponly")
    require(not any(key.lower() == "httponly" for key in xsrf._rest), "xsrf_cookie_not_readable")


def data(document, expected_type=dict):
    require(isinstance(document, dict) and isinstance(document.get("data"), expected_type), "invalid_api_envelope")
    return document["data"]


def identifier(value) -> str:
    require(isinstance(value, str), "identity_id_missing")
    try:
        parsed = uuid.UUID(value)
    except (ValueError, AttributeError):
        raise SmokeFailure("identity_id_invalid") from None
    require(parsed.version == 7, "identity_id_not_uuid7")
    return value


class Manifest:
    def __init__(self, path: Path):
        self.path = path.resolve()
        self.users: list[dict[str, str]] = []
        self.path.parent.mkdir(parents=True, exist_ok=True)
        try:
            fd = os.open(self.path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        except FileExistsError:
            raise SmokeFailure("cleanup_manifest_already_exists") from None
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            os.fchmod(stream.fileno(), 0o600)
            json.dump({"users": self.users}, stream)
            stream.flush()
            os.fsync(stream.fileno())

    def save(self) -> None:
        fd, temporary = tempfile.mkstemp(prefix=".identity-cleanup-", dir=self.path.parent)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as stream:
                os.fchmod(stream.fileno(), 0o600)
                json.dump({"users": self.users}, stream, separators=(",", ":"))
                stream.write("\n")
                stream.flush()
                os.fsync(stream.fileno())
            os.replace(temporary, self.path)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)


class SandboxMail:
    def __init__(self, origin: str):
        self.origin = origin
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def get(self, path: str, deadline: float):
        remaining = deadline - time.monotonic()
        require(remaining > 0, "sandbox_mail_timeout")
        request = urllib.request.Request(self.origin + path, headers={"Accept": "application/json"})
        try:
            with self.opener.open(request, timeout=min(5, remaining)) as response:
                status, document, _ = decode_response(response)
        except (OSError, urllib.error.URLError, TimeoutError):
            raise SmokeFailure("sandbox_mail_transport_failed") from None
        require(status == 200, "sandbox_mail_http_failure")
        return document

    @staticmethod
    def recipient_matches(message: dict, recipient: str) -> bool:
        addresses = message.get("To", [])
        return isinstance(addresses, list) and any(
            isinstance(address, dict)
            and str(address.get("Address", address.get("Email", ""))).lower() == recipient
            for address in addresses
        )

    def token(self, recipient: str, subject: str, app_origin: str, purpose_path: str) -> str:
        deadline = time.monotonic() + 45
        query = urllib.parse.urlencode({"query": "to:" + recipient, "limit": 50})
        while time.monotonic() < deadline:
            result = self.get("/api/v1/search?" + query, deadline)
            require(isinstance(result, dict) and isinstance(result.get("messages"), list), "sandbox_mail_schema_invalid")
            for summary in result["messages"]:
                if not isinstance(summary, dict) or summary.get("Subject") != subject or not self.recipient_matches(summary, recipient):
                    continue
                message_id = summary.get("ID")
                require(isinstance(message_id, str) and re.fullmatch(r"[A-Za-z0-9_-]{1,128}", message_id) is not None, "sandbox_message_id_invalid")
                message = self.get("/api/v1/message/" + message_id, deadline)
                require(isinstance(message, dict) and self.recipient_matches(message, recipient), "sandbox_recipient_mismatch")
                text = message.get("Text", message.get("Plain"))
                require(isinstance(text, str), "sandbox_mail_text_missing")
                expression = re.escape(app_origin + purpose_path) + r"#token=([a-f0-9]{64})(?=\s|$)"
                tokens = re.findall(expression, text)
                require(len(tokens) == 1, "sandbox_token_fragment_invalid")
                return tokens[0]
            time.sleep(min(1, max(0, deadline - time.monotonic())))
        raise SmokeFailure("sandbox_mail_timeout")


def local_origin(value: str, *, https: bool) -> str:
    parsed = urllib.parse.urlsplit(value)
    require(parsed.scheme == ("https" if https else "http"), "invalid_local_origin_scheme")
    require(parsed.hostname == "localhost" and parsed.port is not None, "local_origin_must_use_localhost_port")
    require(not parsed.username and not parsed.password and parsed.path in ("", "/") and not parsed.query and not parsed.fragment, "invalid_local_origin")
    return value.rstrip("/")


def totp(secret: str, offset: int = 0) -> str:
    require(re.fullmatch(r"[A-Z2-7]{32}", secret) is not None, "staff_totp_secret_invalid")
    key = base64.b32decode(secret)
    step = int(time.time()) // 30 + offset
    digest = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    index = digest[-1] & 15
    number = struct.unpack(">I", digest[index:index + 4])[0] & 0x7FFFFFFF
    return str(number % 1_000_000).zfill(6)


def staff_smoke(path: Path, origin: str, context: ssl.SSLContext, manifest: Manifest, customer: dict) -> None:
    global STAGE
    STAGE = "staff_fixture"
    require(stat.S_IMODE(path.stat().st_mode) == 0o600, "staff_fixture_permissions_invalid")
    with path.open("rb") as stream:
        raw = stream.read(4097)
    require(len(raw) <= 4096, "staff_fixture_too_large")
    fixture = json.loads(raw)
    require(isinstance(fixture, dict) and set(fixture) == {"id", "email", "password"}, "staff_fixture_schema_invalid")
    staff_id = identifier(fixture["id"])
    email = fixture["email"]
    password = fixture["password"]
    require(isinstance(email, str) and re.fullmatch(r"b2-smoke-[a-f0-9]{24}@example\.test", email) is not None, "staff_fixture_email_invalid")
    require(isinstance(password, str) and 12 <= len(password) <= 128, "staff_fixture_password_invalid")
    manifest.users.append({"email": email, "user_id": staff_id})
    manifest.save()
    credentials = {"email": email, "password": password}
    browser = Browser(origin, context)
    browser.csrf()

    STAGE = "staff_password_only_session_denied"
    pending = data(browser.call("POST", API + "/auth/login", 202, credentials))
    require(pending.get("next_step") == "mfa_enrollment", "staff_fixture_not_unenrolled")
    browser.call("GET", API + "/identity/me", 401)
    completed(STAGE)

    # The denied full-session request invalidates its limited pending session;
    # obtain a new first factor before enrollment. This is login attempt two.
    browser.csrf()
    browser.call("POST", API + "/auth/login", 202, credentials)
    STAGE = "staff_totp_enrollment_confirmed"
    enrollment = data(browser.call("POST", API + "/auth/mfa/enrollment", 200, {}))
    secret = enrollment.get("secret")
    require(isinstance(secret, str), "staff_totp_secret_missing")
    confirmation = data(browser.call("POST", API + "/auth/mfa/enrollment/confirm", 200, {"code": totp(secret)}))
    recovery_codes = confirmation.get("recovery_codes")
    require(isinstance(recovery_codes, list) and len(recovery_codes) == 10, "staff_recovery_codes_missing")
    require(all(isinstance(code, str) and re.fullmatch(r"[a-f0-9]{8}(?:-[a-f0-9]{8}){3}", code) for code in recovery_codes), "staff_recovery_codes_invalid")
    require(len(set(recovery_codes)) == 10, "staff_recovery_codes_not_unique")
    identity = data(browser.call("GET", API + "/identity/me", 200))
    require(identity.get("id") == staff_id and identity.get("kind") == "staff", "staff_identity_mismatch")
    validate_cookie_flags(browser)
    completed(STAGE)

    STAGE = "staff_customer_access_denied"
    browser.call("GET", API + "/customers", 404)
    browser.call("GET", API + "/customers/" + customer["customer_id"], 404)
    browser.call("GET", API + "/identities/" + customer["user_id"] + "/customers/" + customer["customer_id"], 404)
    browser.call("PATCH", API + "/customers/" + customer["customer_id"], 404, {"phone": "+12025550124"})
    completed(STAGE)

    STAGE = "staff_totp_challenge"
    browser.call("POST", API + "/auth/logout", 200, {})
    pending = data(browser.call("POST", API + "/auth/login", 202, credentials))
    require(pending.get("next_step") == "mfa_challenge", "staff_login_skipped_mfa")
    # Confirmation consumed the current step. The adjacent future step is
    # inside the documented +/-1 window and avoids a wall-clock sleep.
    browser.call("POST", API + "/auth/mfa/challenge", 200, {"code": totp(secret, 1)})
    require(data(browser.call("GET", API + "/identity/me", 200)).get("id") == staff_id, "staff_challenge_not_authenticated")
    completed(STAGE)

    STAGE = "staff_recovery_code_consumption"
    browser.call("POST", API + "/auth/logout", 200, {})
    pending = data(browser.call("POST", API + "/auth/login", 202, credentials))
    require(pending.get("next_step") == "mfa_challenge", "staff_recovery_skipped_mfa")
    browser.call("POST", API + "/auth/mfa/recovery", 200, {"code": recovery_codes[0]})
    require(data(browser.call("GET", API + "/identity/me", 200)).get("id") == staff_id, "staff_recovery_not_authenticated")
    browser.call("POST", API + "/auth/logout", 200, {})
    browser.call("POST", API + "/auth/login", 202, credentials)
    # Enrollment, confirmation, challenge, recovery and replay total exactly
    # five MFA attempts, so a 429 cannot substitute for the required 422.
    browser.call("POST", API + "/auth/mfa/recovery", 422, {"code": recovery_codes[0]})
    browser.call("GET", API + "/identity/me", 401)
    completed(STAGE)


def run(arguments) -> None:
    global STAGE
    origin = local_origin(arguments.origin, https=True)
    mailpit_origin = local_origin(arguments.mailpit_url, https=False)
    context = ssl.create_default_context(cafile=str(arguments.ca_cert))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    manifest = Manifest(arguments.cleanup_manifest)
    mail = SandboxMail(mailpit_origin)
    browsers = [Browser(origin, context), Browser(origin, context)]
    accounts = []

    STAGE = "csrf_cookie_flags"
    browsers[0].csrf()
    validate_cookie_flags(browsers[0])
    completed(STAGE)
    STAGE = "missing_csrf"
    browsers[0].call("POST", API + "/auth/login", 403, {"email": "nobody@example.test", "password": "invalid"}, csrf=False)
    completed(STAGE)

    for index, browser in enumerate(browsers):
        STAGE = "customer_" + str(index + 1) + "_registration_login"
        browser.csrf()
        email = "b2-smoke-" + secrets.token_hex(12) + "@example.test"
        password = "B2!aA9-" + secrets.token_urlsafe(24)
        account = {"email": email, "password": password}
        accounts.append(account)
        manifest.users.append({"email": email})
        manifest.save()
        browser.call("POST", API + "/auth/register", 202, {
            "full_name": "Runtime Smoke Customer " + str(index + 1), "email": email,
            "password": password, "password_confirmation": password, "phone": "+12025550123",
        })
        anonymous = browser.snapshot()
        result = data(browser.call("POST", API + "/auth/login", 200, {"email": email, "password": password}))
        require(result.get("next_step") == "authenticated", "customer_login_unexpected_mfa")
        validate_cookie_flags(browser)
        identity = data(browser.call("GET", API + "/identity/me", 200))
        account["user_id"] = identifier(identity.get("id"))
        require(identity.get("email") == email and identity.get("kind") == "customer", "wrong_current_identity")
        require(identity.get("email_verified") is False, "new_customer_already_verified")
        profiles = data(browser.call("GET", API + "/customers", 200), list)
        require(len(profiles) == 1 and isinstance(profiles[0], dict), "customer_list_not_scoped")
        account["customer_id"] = identifier(profiles[0].get("id"))
        require(profiles[0].get("user_id") == account["user_id"], "customer_owner_mismatch")
        manifest.users[index].update({key: account[key] for key in ("user_id", "customer_id")})
        manifest.save()
        anonymous.call("GET", API + "/identity/me", 401)
        completed(STAGE)

    for index, browser in enumerate(browsers):
        STAGE = "customer_" + str(index + 1) + "_isolation"
        own = accounts[index]
        other = accounts[1 - index]
        direct = API + "/customers/" + other["customer_id"]
        nested = API + "/identities/" + other["user_id"] + "/customers/" + other["customer_id"]
        forged_parent = API + "/identities/" + other["user_id"] + "/customers/" + own["customer_id"]
        for path in (direct, nested, forged_parent):
            browser.call("GET", path, 404)
            browser.call("PATCH", path, 404, {"phone": "+12025550124"})
        browser.call("PATCH", API + "/customers/" + own["customer_id"], 422, {"phone": "+12025550124", "user_id": other["user_id"]})
        browser.call("PATCH", API + "/identity/me", 422, {"full_name": "Changed Name", "kind": "staff"})
        completed(STAGE)

    STAGE = "sandbox_email_verification_and_replay"
    token = mail.token(accounts[0]["email"], "Verify your HOLOUL email address", origin, "/verify-email")
    browsers[0].call("POST", API + "/auth/email/verify", 200, {"token": token})
    browsers[0].call("POST", API + "/auth/email/verify", 200, {"token": token})
    require(data(browsers[0].call("GET", API + "/identity/me", 200)).get("email_verified") is True, "email_verification_not_persisted")
    completed(STAGE)

    STAGE = "generic_password_recovery_request"
    recovery = Browser(origin, context)
    recovery.csrf()
    existing = recovery.call("POST", API + "/auth/password/forgot", 202, {"email": accounts[0]["email"]})
    absent = recovery.call("POST", API + "/auth/password/forgot", 202, {"email": "b2-absent-" + secrets.token_hex(12) + "@example.test"})
    require(data(existing) == data(absent), "password_recovery_reveals_account_existence")
    completed(STAGE)

    STAGE = "sandbox_password_reset_single_use_and_session_revocation"
    reset_token = mail.token(accounts[0]["email"], "Reset your HOLOUL password", origin, "/reset-password")
    replacement = "B2!aA9-" + secrets.token_urlsafe(24)
    reset_body = {"token": reset_token, "password": replacement, "password_confirmation": replacement}
    old_session = browsers[0].snapshot()
    recovery.call("POST", API + "/auth/password/reset", 200, reset_body)
    recovery.call("POST", API + "/auth/password/reset", 422, reset_body)
    old_session.call("GET", API + "/identity/me", 401)
    browsers[0].call("GET", API + "/identity/me", 401)
    require(data(browsers[1].call("GET", API + "/identity/me", 200)).get("id") == accounts[1]["user_id"], "reset_revoked_other_customer")
    completed(STAGE)

    STAGE = "replacement_password_login_and_logout_cookie_replay"
    browsers[0].csrf()
    browsers[0].call("POST", API + "/auth/login", 401, {"email": accounts[0]["email"], "password": accounts[0]["password"]})
    browsers[0].call("POST", API + "/auth/login", 200, {"email": accounts[0]["email"], "password": replacement})
    require(data(browsers[0].call("GET", API + "/identity/me", 200)).get("id") == accounts[0]["user_id"], "replacement_login_wrong_identity")
    logged_in_cookie = browsers[0].snapshot()
    browsers[0].call("POST", API + "/auth/logout", 200, {})
    logged_in_cookie.call("GET", API + "/identity/me", 401)
    completed(STAGE)

    STAGE = "wrong_origin"
    browsers[1].call("GET", API + "/identity/me", 403, origin="https://wrong-origin.example.test")
    completed(STAGE)
    if arguments.staff_fixture is not None:
        staff_smoke(arguments.staff_fixture, origin, context, manifest, accounts[0])
    print(json.dumps({"ok": True, "checks": CHECKS}, separators=(",", ":")))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--ca-cert", type=Path, required=True)
    parser.add_argument("--mailpit-url", required=True)
    parser.add_argument("--cleanup-manifest", type=Path, required=True)
    parser.add_argument("--staff-fixture", type=Path)
    arguments = parser.parse_args()
    try:
        run(arguments)
    except SmokeFailure as error:
        print(json.dumps({"ok": False, "stage": STAGE, "error": str(error), "checks": CHECKS}, separators=(",", ":")), file=sys.stderr)
        return 1
    except Exception:
        # Never serialize exception details: network/library errors can contain
        # message content, request headers, credentials, or local secret paths.
        print(json.dumps({"ok": False, "stage": STAGE, "error": "unexpected_smoke_failure", "checks": CHECKS}, separators=(",", ":")), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
