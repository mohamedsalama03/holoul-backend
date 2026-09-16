#!/usr/bin/env python3
"""Exercise B3 through verified HTTPS, real cookies, TOTP and sandbox email."""

from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
import re
import secrets
import ssl
import stat
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


# Reuse the bounded B2 transport, cookie, TOTP and sandbox-mail contracts.
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("identity_smoke", Path(__file__).with_name("verify-identity-http.py"))
if spec is None or spec.loader is None:
    raise SystemExit(1)
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)
require = identity.require
data = identity.data
identifier = identity.identifier
API = identity.API
CHECKS: list[str] = []
STAGE = "configuration"
NEXT_REQUEST = 0.0


def pace() -> None:
    # Stay below the real edge limit; a 429 must never stand in for a policy test.
    global NEXT_REQUEST
    time.sleep(max(0.0, NEXT_REQUEST - time.monotonic()))
    NEXT_REQUEST = time.monotonic() + 0.14


class Browser(identity.Browser):
    def call(self, *args, **kwargs):
        pace()
        return super().call(*args, **kwargs)

    def exchange(self, method: str, path: str, expected: int, body=None, *, etag=None, key=None):
        pace()
        require(path.startswith("/") and not path.startswith("//"), "invalid_relative_path")
        headers = {"Accept": "application/json", "Origin": self.origin,
                   "Referer": self.origin + "/", "Sec-Fetch-Site": "same-origin"}
        if method not in ("GET", "HEAD"):
            headers["X-XSRF-TOKEN"] = urllib.parse.unquote(self.cookie("XSRF-TOKEN").value)
        if etag is not None:
            headers["If-Match"] = etag
        if key is not None:
            headers["Idempotency-Key"] = key
        encoded = None if body is None else json.dumps(body).encode("utf-8")
        if encoded is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(self.origin + path, data=encoded, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        except (OSError, urllib.error.URLError, TimeoutError):
            raise identity.SmokeFailure("https_transport_failed") from None
        with response:
            status, document, response_headers = identity.decode_response(response)
        require(status == expected, "unexpected_http_status_" + str(status) + "_expected_" + str(expected))
        require("no-store" in response_headers.get("Cache-Control", "").lower(), "response_cache_policy_missing")
        return document, response_headers

    def record(self, method: str, path: str, expected: int, body=None, *, etag=None, key=None):
        document, headers = self.exchange(method, path, expected, body, etag=etag, key=key)
        record = data(document)
        record_id = identifier(record.get("id", record.get("request_id")))
        version = record.get("version", record.get("lock_version"))
        require(isinstance(version, int) and not isinstance(version, bool) and version > 0, "record_version_invalid")
        tag = '"' + record_id + ":" + str(version) + '"'
        require(headers.get("ETag") == tag, "record_etag_mismatch")
        if expected == 201:
            location = headers.get("Location")
            require(isinstance(location, str) and location.startswith(API + "/"), "mutation_location_missing")
        else:
            require(headers.get("Location") is None, "ordinary_response_has_redirect_location")
        return record, tag


def complete(name: str) -> None:
    CHECKS.append(name)


def fixture(path: Path) -> dict:
    require(stat.S_IMODE(path.stat().st_mode) == 0o600, "fixture_permissions_invalid")
    with path.open("rb") as stream:
        raw = stream.read(16385)
    require(len(raw) <= 16384, "fixture_too_large")
    result = json.loads(raw)
    require(isinstance(result, dict) and set(result) == {"run_id", "accounts"}, "fixture_schema_invalid")
    run_id = result["run_id"]
    require(isinstance(run_id, str) and re.fullmatch(r"[a-f0-9]{24}", run_id) is not None, "fixture_run_invalid")
    accounts = result["accounts"]
    require(isinstance(accounts, dict) and set(accounts) == {"customer_a", "customer_b", "admin", "manager"}, "fixture_accounts_invalid")
    for label, account in accounts.items():
        require(isinstance(account, dict) and set(account) == {"id", "email", "password", "customer_id"}, "fixture_account_invalid")
        identifier(account["id"])
        require(account["email"] == "b3-smoke-" + run_id + "-" + label.replace("_", "-") + "@example.test", "fixture_email_invalid")
        require(isinstance(account["password"], str) and 24 <= len(account["password"]) <= 128, "fixture_password_invalid")
        if label.startswith("customer_"):
            identifier(account["customer_id"])
        else:
            require(account["customer_id"] is None, "fixture_persona_invalid")
    return result


def authenticate(account: dict, staff: bool, origin: str, context: ssl.SSLContext, mail) -> Browser:
    browser = Browser(origin, context)
    browser.csrf()
    credentials = {"email": account["email"], "password": account["password"]}
    login = data(browser.call("POST", API + "/auth/login", 202 if staff else 200, credentials))
    if staff:
        require(login.get("next_step") == "mfa_enrollment", "staff_login_skipped_enrollment")
        # Enrollment rotates auth_version and correctly invalidates the fixture's
        # earlier email token. Redeem it while login is still MFA-pending. A /me
        # probe here would invalidate that pending session, so inspect it only
        # after the real second factor has completed.
        token = mail.token(account["email"], "Verify your HOLOUL email address", origin, "/verify-email")
        browser.call("POST", API + "/auth/email/verify", 200, {"token": token})
        enrollment = data(browser.call("POST", API + "/auth/mfa/enrollment", 200, {}))
        secret = enrollment.get("secret")
        require(isinstance(secret, str), "staff_totp_secret_missing")
        confirmation = data(browser.call("POST", API + "/auth/mfa/enrollment/confirm", 200, {"code": identity.totp(secret)}))
        require(isinstance(confirmation.get("recovery_codes"), list) and len(confirmation["recovery_codes"]) == 10, "staff_recovery_codes_missing")
    else:
        require(login.get("next_step") == "authenticated", "customer_login_failed")
    current = data(browser.call("GET", API + "/identity/me", 200))
    require(current.get("id") == account["id"] and current.get("kind") == ("staff" if staff else "customer"), "fixture_identity_mismatch")
    identity.validate_cookie_flags(browser)
    if staff:
        require(current.get("email_verified") is True, "staff_email_verification_not_preserved")
    else:
        require(current.get("email_verified") is False, "fixture_email_preverified")
        token = mail.token(account["email"], "Verify your HOLOUL email address", origin, "/verify-email")
        browser.call("POST", API + "/auth/email/verify", 200, {"token": token})
    require(data(browser.call("GET", API + "/identity/me", 200)).get("email_verified") is True, "email_verification_failed")
    return browser


def run(arguments) -> None:
    global STAGE
    origin = identity.local_origin(arguments.origin, https=True)
    mail = identity.SandboxMail(identity.local_origin(arguments.mailpit_url, https=False))
    context = ssl.create_default_context(cafile=str(arguments.ca_cert))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    fixtures = fixture(arguments.fixtures)
    accounts = fixtures["accounts"]
    prefix = "b3-smoke-" + fixtures["run_id"]
    clients = {}
    for label, account in accounts.items():
        STAGE = label + "_real_login_and_email_verification"
        clients[label] = authenticate(account, not label.startswith("customer_"), origin, context, mail)
        complete(STAGE)
    a, b, admin, manager = (clients[label] for label in ("customer_a", "customer_b", "admin", "manager"))

    STAGE = "taxonomy_authority_creation_and_versions"
    a.exchange("GET", API + "/admin/categories", 403)
    manager.exchange("GET", API + "/admin/categories", 403)
    original_category = "B3 Synthetic Category"
    original_subcategory = "B3 Synthetic Subcategory"
    category, category_tag = admin.record("POST", API + "/admin/categories", 201, {
        "name": original_category, "slug": prefix + "-category", "active": True, "display_order": 900000})
    category_path = API + "/admin/categories/" + category["id"]
    subcategory, subcategory_tag = admin.record("POST", category_path + "/subcategories", 201, {
        "name": original_subcategory, "slug": prefix + "-subcategory", "active": True, "display_order": 900000})
    subcategory_path = API + "/admin/subcategories/" + subcategory["id"]
    admin.exchange("PATCH", category_path, 428, {"display_order": 900001})
    old_category_tag = category_tag
    category, category_tag = admin.record("PATCH", category_path, 200, {"display_order": 900001}, etag=category_tag)
    admin.exchange("PATCH", category_path, 412, {"display_order": 900002}, etag=old_category_tag)
    admin.exchange("PATCH", category_path, 422, {"slug": prefix + "-forged"}, etag=category_tag)
    visible = data(a.exchange("GET", API + "/categories/" + category["id"] + "/subcategories", 200)[0], list)
    require(any(row.get("id") == subcategory["id"] for row in visible), "active_taxonomy_missing")
    complete(STAGE)

    requests = []
    receipts = []
    initial_tags = []
    submission_keys = []
    for index, browser in enumerate((a, b)):
        STAGE = "customer_" + str(index + 1) + "_draft_preconditions_and_exact_submission"
        draft_input = {"category_id": category["id"], "subcategory_id": subcategory["id"],
                       "project_name": prefix + " project " + str(index + 1),
                       "project_description": "Synthetic intake verification without customer or business data.",
                       "budget_unknown": False, "estimated_budget": "1234.50" if index == 0 else "1234.567",
                       "currency": "USD" if index == 0 else "LYD"}
        draft, tag = browser.record("POST", API + "/project-requests", 201, draft_input)
        require(draft.get("state") == "draft" and draft.get("reference") is None, "draft_has_official_reference")
        path = API + "/project-requests/" + draft["id"]
        browser.exchange("PATCH", path + "/draft", 428, {"project_description": "Synthetic changed description."})
        stale_tag = tag
        draft, tag = browser.record("PATCH", path + "/draft", 200, {"project_description": "Synthetic changed description."}, etag=tag)
        browser.exchange("PATCH", path + "/draft", 412, {"project_description": "Stale synthetic description."}, etag=stale_tag)
        browser.exchange("PATCH", path + "/draft", 422, {"customer_id": accounts["customer_b" if index == 0 else "customer_a"]["customer_id"]}, etag=tag)
        key = "b3-submit-" + secrets.token_hex(16)
        browser.exchange("POST", path + "/submissions", 428, {}, key=key)
        browser.exchange("POST", path + "/submissions", 412, {}, key=key, etag=stale_tag)
        receipt, receipt_tag = browser.record("POST", path + "/submissions", 201, {}, key=key, etag=tag)
        require(receipt.get("state") == "submitted" and receipt.get("revision_number") == 1, "initial_submission_invalid")
        require(isinstance(receipt.get("reference"), str) and re.fullmatch(r"REQ-[0-9]{4}-[0-9]{5,19}", receipt["reference"]), "official_reference_invalid")
        replay, replay_tag = browser.record("POST", path + "/submissions", 201, {}, key=key, etag=tag)
        require(replay == receipt and replay_tag == receipt_tag, "idempotency_replay_changed_receipt")
        revision = data(browser.exchange("GET", path + "/revisions/" + receipt["revision_id"], 200)[0])
        require(revision.get("estimated_budget") == draft_input["estimated_budget"] and revision.get("currency") == draft_input["currency"], "money_snapshot_not_exact")
        require(revision.get("category_label") == original_category and revision.get("subcategory_label") == original_subcategory, "taxonomy_snapshot_invalid")
        require(revision.get("provenance") == "customer_submission" and revision.get("email") == accounts["customer_a" if index == 0 else "customer_b"]["email"], "submission_provenance_invalid")
        requests.append(draft["id"])
        receipts.append(receipt)
        initial_tags.append(tag)
        submission_keys.append(key)
        complete(STAGE)

    STAGE = "customer_direct_reference_revision_nested_and_search_isolation"
    for index, browser in enumerate((a, b)):
        own, other = requests[index], requests[1 - index]
        own_customer = accounts["customer_a" if index == 0 else "customer_b"]["customer_id"]
        foreign_customer = accounts["customer_b" if index == 0 else "customer_a"]["customer_id"]
        foreign_revision = receipts[1 - index]["revision_id"]
        for path in ("/project-requests/" + other,
                     "/project-requests/by-reference/" + receipts[1 - index]["reference"],
                     "/project-requests/" + other + "/revisions",
                     "/project-requests/" + other + "/revisions/" + foreign_revision,
                     "/project-requests/" + own + "/revisions/" + foreign_revision,
                     "/project-requests/" + other + "/information-requests",
                     "/project-requests/" + other + "/history",
                     "/customers/" + foreign_customer + "/project-requests/" + own,
                     "/customers/" + own_customer + "/project-requests/" + other,
                     "/project-requests/not-a-uuid"):
            browser.exchange("GET", API + path, 404)
        own_nested = data(browser.exchange("GET", API + "/customers/" + own_customer + "/project-requests/" + own, 200)[0])
        require(own_nested.get("id") == own, "own_nested_record_missing")
        for query in ({"limit": 1}, {"q": prefix}, {"reference": receipts[1 - index]["reference"]}):
            rows = data(browser.exchange("GET", API + "/project-requests?" + urllib.parse.urlencode(query), 200)[0], list)
            require(all(row.get("id") == own for row in rows), "customer_list_or_search_leaked_record")
            if "reference" in query:
                require(rows == [], "foreign_reference_filter_leaked_record")
        browser.exchange("POST", API + "/project-requests/" + other + "/withdrawals", 404, {}, etag='"' + other + ':3"')
    complete(STAGE)

    STAGE = "immutable_snapshots_and_customer_authored_amendment"
    category, category_tag = admin.record("PATCH", category_path, 200, {"name": "B3 Renamed Category"}, etag=category_tag)
    subcategory, subcategory_tag = admin.record("PATCH", subcategory_path, 200, {"name": "B3 Renamed Subcategory"}, etag=subcategory_tag)
    a.call("PATCH", API + "/identity/me", 200, {"full_name": "B3 Renamed Synthetic Customer"})
    a_path = API + "/project-requests/" + requests[0]
    current, tag = a.record("GET", a_path, 200)
    require(current["latest_revision"]["category_label"] == original_category, "live_taxonomy_rewrote_revision")
    require(current["latest_revision"]["full_name"] != "B3 Renamed Synthetic Customer", "live_identity_rewrote_revision")
    current, tag = a.record("POST", a_path + "/amendments", 200, {}, etag=tag)
    current, tag = a.record("PATCH", a_path + "/draft", 200, {"estimated_budget": "9876.543", "currency": "LYD"}, etag=tag)
    amended, tag = a.record("POST", a_path + "/submissions", 201, {}, etag=tag, key="b3-amend-" + secrets.token_hex(16))
    require(amended.get("revision_number") == 2 and amended.get("reference") == receipts[0]["reference"], "amendment_replaced_request_identity")
    versions = data(a.exchange("GET", a_path + "/revisions", 200)[0], list)
    require(len(versions) == 2 and versions[0]["estimated_budget"] == "1234.50" and versions[0]["currency"] == "USD", "first_revision_mutated")
    require(versions[1]["estimated_budget"] == "9876.543" and versions[1]["currency"] == "LYD"
            and versions[1]["category_label"] == "B3 Renamed Category"
            and versions[1]["subcategory_label"] == "B3 Renamed Subcategory"
            and versions[1]["full_name"] == "B3 Renamed Synthetic Customer"
            and versions[1]["provenance"] == "customer_amendment", "amendment_snapshot_invalid")
    replay, _ = a.record("POST", a_path + "/submissions", 201, {}, etag=initial_tags[0], key=submission_keys[0])
    require(replay == receipts[0], "later_state_changed_replayed_receipt")
    a.exchange("POST", a_path + "/submissions", 409, {}, etag=tag, key=submission_keys[0])
    complete(STAGE)

    STAGE = "assigned_staff_visibility_and_review_authority"
    a_admin_path = API + "/admin/project-requests/" + requests[0]
    b_admin_path = API + "/admin/project-requests/" + requests[1]
    manager.exchange("POST", a_admin_path + "/reviews", 403, {}, etag=tag)
    current, tag = admin.record("POST", a_admin_path + "/assignments", 200, {"assignee_id": accounts["manager"]["id"]}, etag=tag)
    b_current, b_tag = admin.record("GET", b_admin_path, 200)
    b_current, b_tag = admin.record("POST", b_admin_path + "/assignments", 200, {"assignee_id": accounts["admin"]["id"]}, etag=b_tag)
    for path in (b_admin_path, API + "/admin/project-requests/by-reference/" + receipts[1]["reference"],
                 b_admin_path + "/revisions", b_admin_path + "/revisions/" + receipts[1]["revision_id"],
                 b_admin_path + "/history", b_admin_path + "/information-requests"):
        manager.exchange("GET", path, 404)
    scoped = data(manager.exchange("GET", API + "/admin/project-requests?" + urllib.parse.urlencode({"q": prefix}), 200)[0], list)
    require([row.get("id") for row in scoped] == [requests[0]], "staff_search_not_assignment_scoped")
    current, tag = manager.record("POST", a_admin_path + "/reviews", 200, {}, etag=tag)
    require(current.get("state") == "under_review", "assigned_review_not_started")
    manager.exchange("PATCH", a_path + "/draft", 403, {"project_description": "Staff must not author customer revisions."}, etag=tag)
    complete(STAGE)

    STAGE = "information_request_response_acknowledgement_and_discovery_boundary"
    current, tag = manager.record("POST", a_admin_path + "/information-requests", 200, {"message": "Synthetic clarification requested."}, etag=tag)
    require(current.get("state") == "information_required", "information_state_missing")
    information = data(a.exchange("GET", a_path + "/information-requests", 200)[0], list)
    require(len(information) == 1 and information[0].get("response") is None, "information_request_invalid")
    information_id = identifier(information[0].get("id"))
    b.exchange("POST", a_path + "/information-requests/" + information_id + "/responses", 404, {"message": "Foreign response."}, etag=tag)
    b.exchange("POST", API + "/project-requests/" + requests[1] + "/information-requests/" + information_id + "/responses", 404, {"message": "Mismatched parent."}, etag=b_tag)
    manager.exchange("POST", a_admin_path + "/information-requests/" + information_id + "/acknowledgements", 409, {}, etag=tag)
    current, tag = a.record("POST", a_path + "/information-requests/" + information_id + "/responses", 200, {"message": "Synthetic clarification response."}, etag=tag)
    require(current.get("state") == "information_required", "response_skipped_staff_acknowledgement")
    current, tag = manager.record("POST", a_admin_path + "/information-requests/" + information_id + "/acknowledgements", 200, {}, etag=tag)
    require(current.get("state") == "under_review", "acknowledgement_wrong_origin_state")
    current, tag = manager.record("POST", a_admin_path + "/discovery-handoffs", 200, {}, etag=tag)
    require(current.get("state") == "discovery" and current.get("latest_revision_number") == 2, "discovery_boundary_changed_revision")
    a.exchange("POST", a_path + "/amendments", 409, {}, etag=tag)
    a.exchange("GET", API + "/projects", 404)
    information = data(a.exchange("GET", a_path + "/information-requests", 200)[0], list)
    require(information[0].get("response") == "Synthetic clarification response." and information[0].get("resolution") == "acknowledged", "information_history_missing")
    current, tag = a.record("POST", a_path + "/withdrawals", 200, {"message": "Synthetic runtime verification completed."}, etag=tag)
    require(current.get("state") == "withdrawn", "customer_withdrawal_failed")
    history = data(a.exchange("GET", a_path + "/history", 200)[0], list)
    require([row.get("to_state") for row in history] == ["submitted", "under_review", "information_required", "under_review", "discovery", "withdrawn"], "workflow_history_invalid")
    complete(STAGE)

    STAGE = "explicit_rejection_terminal_guard"
    b_current, b_tag = admin.record("POST", b_admin_path + "/reviews", 200, {}, etag=b_tag)
    admin.exchange("POST", b_admin_path + "/rejections", 422, {}, etag=b_tag)
    b_current, b_tag = admin.record("POST", b_admin_path + "/rejections", 200, {"message": "Synthetic verification request rejected."}, etag=b_tag)
    require(b_current.get("state") == "rejected", "rejection_not_terminal")
    b.exchange("POST", API + "/project-requests/" + requests[1] + "/amendments", 409, {}, etag=b_tag)
    complete(STAGE)

    STAGE = "taxonomy_deactivation_preserves_historical_snapshots"
    subcategory, subcategory_tag = admin.record("PATCH", subcategory_path, 200, {"active": False}, etag=subcategory_tag)
    require(subcategory.get("active") is False, "subcategory_not_deactivated")
    visible = data(a.exchange("GET", API + "/categories/" + category["id"] + "/subcategories", 200)[0], list)
    require(visible == [], "inactive_subcategory_still_selectable")
    category, category_tag = admin.record("PATCH", category_path, 200, {"active": False}, etag=category_tag)
    a.exchange("GET", API + "/categories/" + category["id"] + "/subcategories", 404)
    current, _ = a.record("GET", a_path, 200)
    require(current["latest_revision"]["category_label"] == "B3 Renamed Category" and current["latest_revision"]["estimated_budget"] == "9876.543", "deactivation_changed_historical_revision")
    complete(STAGE)
    print(json.dumps({"ok": True, "checks": CHECKS, "synthetic_requests": 2, "immutable_revisions": 3}, separators=(",", ":")))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--ca-cert", type=Path, required=True)
    parser.add_argument("--mailpit-url", required=True)
    parser.add_argument("--fixtures", type=Path, required=True)
    arguments = parser.parse_args()
    try:
        run(arguments)
    except identity.SmokeFailure as error:
        print(json.dumps({"ok": False, "stage": STAGE, "error": str(error), "checks": CHECKS}, separators=(",", ":")), file=sys.stderr)
        return 1
    except Exception:
        # Exceptions may include credentials, response content or filesystem paths.
        print(json.dumps({"ok": False, "stage": STAGE, "error": "unexpected_smoke_failure", "checks": CHECKS}, separators=(",", ":")), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
