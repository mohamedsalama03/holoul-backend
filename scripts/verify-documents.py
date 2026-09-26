#!/usr/bin/env python3
"""Verify B4 with real HTTPS cookies, private storage, scanner and parser workers."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import secrets
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("intake_smoke", Path(__file__).with_name("verify-intake-http.py"))
if spec is None or spec.loader is None:
    raise SystemExit(1)
intake = importlib.util.module_from_spec(spec)
spec.loader.exec_module(intake)
identity = intake.identity
require, data, identifier, API = identity.require, identity.data, identity.identifier, identity.API
CHECKS: list[str] = []
STAGE = "configuration"


class Browser(intake.Browser):
    def binary(self, method: str, path: str, expected: int, content: bytes | None = None, *, etag=None,
               download: bytes | None = None, mime=None, filename=None):
        intake.pace()
        require(path.startswith("/") and not path.startswith("//"), "invalid_relative_path")
        headers = {"Accept": "application/json", "Origin": self.origin,
                   "Referer": self.origin + "/", "Sec-Fetch-Site": "same-origin"}
        if method != "GET":
            headers["X-XSRF-TOKEN"] = urllib.parse.unquote(self.cookie("XSRF-TOKEN").value)
            headers["Content-Type"] = "application/octet-stream"
        if etag is not None:
            headers["If-Match"] = etag
        request = urllib.request.Request(self.origin + path, data=content, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        except (OSError, urllib.error.URLError, TimeoutError):
            raise identity.SmokeFailure("https_transport_failed") from None
        with response:
            status, response_headers = response.code, response.headers
            require(status == expected, "unexpected_http_status_" + str(status) + "_expected_" + str(expected))
            require("no-store" in response_headers.get("Cache-Control", "").lower(), "response_cache_policy_missing")
            require(response_headers.get("Location") is None, "private_bytes_redirected")
            if download is None:
                _, document, _ = identity.decode_response(response)
                return document, response_headers
            raw = response.read(len(download) + 1)
        require(len(raw) == len(download) and hashlib.sha256(raw).digest() == hashlib.sha256(download).digest(),
                "private_download_hash_mismatch")
        require(response_headers.get("Content-Type") == mime, "private_download_mime_mismatch")
        require(response_headers.get("Content-Length") == str(len(download)), "private_download_length_mismatch")
        disposition = response_headers.get("Content-Disposition", "")
        require(disposition.startswith("attachment;") and filename in disposition, "private_download_disposition_invalid")
        require(response_headers.get("X-Content-Type-Options") == "nosniff", "private_download_nosniff_missing")
        require("private" in response_headers.get("Cache-Control", "").lower(), "private_download_cache_invalid")
        require("sandbox" in response_headers.get("Content-Security-Policy", ""), "private_download_sandbox_missing")
        return None, response_headers


def pdf_bytes() -> bytes:
    """A real one-page PDF with exact xref offsets; no external fixture downloads."""
    stream = b"BT /F1 12 Tf 40 100 Td (Synthetic B4 private document.) Tj ET\n"
    objects = [b"<< /Type /Catalog /Pages 2 0 R >>", b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
               b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 150] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
               b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
               b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"endstream"]
    result = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for index, value in enumerate(objects, 1):
        offsets.append(len(result))
        result.extend(str(index).encode() + b" 0 obj\n" + value + b"\nendobj\n")
    xref = len(result)
    result.extend(b"xref\n0 6\n0000000000 65535 f \n")
    for offset in offsets[1:]:
        result.extend(f"{offset:010d} 00000 n \n".encode())
    result.extend(b"trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" + str(xref).encode() + b"\n%%EOF\n")
    return bytes(result)


def docx_bytes() -> bytes:
    parts = {
        "[Content_Types].xml": '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        "_rels/.rels": '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        "word/document.xml": '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Synthetic B4 private document.</w:t></w:r></w:p><w:sectPr/></w:body></w:document>',
    }
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, text in parts.items():
            entry = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o600 << 16
            archive.writestr(entry, text.encode())
    return output.getvalue()


def document_view(document) -> dict:
    view = data(document)
    require(set(view) == {"id", "filename", "format", "mime", "bytes", "state", "version", "retryable"},
            "private_metadata_fields_invalid")
    identifier(view.get("id"))
    require(type(view.get("version")) is int and view["version"] > 0, "document_version_invalid")
    return view


def reserve(browser, path, etag, filename, content, *, key=None):
    key = key or "b4-reserve-" + secrets.token_hex(16)
    body = {"filename": filename, "bytes": len(content), "sha256": hashlib.sha256(content).hexdigest()}
    document, headers = browser.exchange("POST", path + "/documents", 201, body, etag=etag, key=key)
    view = document_view(document)
    require(view["state"] == "uploading" and view["filename"] == filename, "reservation_invalid")
    tag = headers.get("ETag")
    require(tag == '"' + path.rsplit("/", 1)[-1] + ":" + str(int(etag.rsplit(":", 1)[1][:-1]) + 1) + '"',
            "reservation_parent_version_invalid")
    return view, tag, body, key


def await_state(browser, path, expected):
    deadline = time.monotonic() + 150
    while time.monotonic() < deadline:
        view = document_view(browser.exchange("GET", path, 200)[0])
        if view["state"] == expected:
            return view
        require(view["state"] == "quarantined" and view["retryable"] is False, "document_processing_failed")
        time.sleep(1)
    raise identity.SmokeFailure("document_processing_timeout")


def run(arguments):
    global STAGE
    origin = identity.local_origin(arguments.origin, https=True)
    mail = identity.SandboxMail(identity.local_origin(arguments.mailpit_url, https=False))
    context = ssl.create_default_context(cafile=str(arguments.ca_cert))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    fixtures = intake.fixture(arguments.fixtures)
    accounts = fixtures["accounts"]
    clients = {}
    for label, account in accounts.items():
        STAGE = label + "_real_login_email_and_mfa"
        signed_in = intake.authenticate(account, not label.startswith("customer_"), origin, context, mail)
        clients[label] = Browser(origin, context, signed_in.jar)
        CHECKS.append(STAGE)
    a, b, admin, manager = (clients[label] for label in ("customer_a", "customer_b", "admin", "manager"))
    # Reuse the strict B3 fixture and terminal/account cleanup contract.
    prefix = "b3-smoke-" + fixtures["run_id"]
    file_prefix = "b4-smoke-" + fixtures["run_id"]
    STAGE = "private_document_synthetic_parent_setup"
    category, _ = admin.record("POST", API + "/admin/categories", 201,
                               {"name": "B4 Synthetic Category", "slug": prefix + "-category", "active": True, "display_order": 900000})
    child, _ = admin.record("POST", API + "/admin/categories/" + category["id"] + "/subcategories", 201,
                            {"name": "B4 Synthetic Subcategory", "slug": prefix + "-subcategory", "active": True, "display_order": 900000})
    parents = []
    for index, browser in enumerate((a, b)):
        record, tag = browser.record("POST", API + "/project-requests", 201,
                                     {"category_id": category["id"], "subcategory_id": child["id"],
                                      "project_name": prefix + " document " + str(index),
                                      "project_description": "Synthetic B4 verification; no customer data.", "budget_unknown": True})
        parents.append((API + "/project-requests/" + record["id"], tag))
    a_path, a_tag = parents[0]
    b_path, b_tag = parents[1]
    pdf, docx = pdf_bytes(), docx_bytes()
    CHECKS.append(STAGE)

    STAGE = "reservation_preconditions_allowlist_and_idempotency"
    body = {"filename": file_prefix + "-safe.pdf", "bytes": len(pdf), "sha256": hashlib.sha256(pdf).hexdigest()}
    key = "b4-reserve-" + secrets.token_hex(16)
    a.exchange("POST", a_path + "/documents", 428, body, key=key)
    a.exchange("POST", a_path + "/documents", 422, {**body, "customer_id": accounts["customer_b"]["customer_id"]}, etag=a_tag, key=key)
    old_tag = a_tag
    pdf_view, a_tag, body, key = reserve(a, a_path, a_tag, body["filename"], pdf, key=key)
    pdf_path = a_path + "/documents/" + pdf_view["id"]
    replay, headers = a.exchange("POST", a_path + "/documents", 201, body, etag=old_tag, key=key)
    require(document_view(replay) == pdf_view and headers.get("ETag") == a_tag, "reservation_replay_changed")
    a.exchange("POST", a_path + "/documents", 412, body, etag=old_tag, key="b4-stale-" + secrets.token_hex(16))
    a.exchange("GET", pdf_path + "/download", 409)
    a.binary("PUT", pdf_path + "/content", 412, pdf, etag=old_tag)
    CHECKS.append(STAGE)

    STAGE = "customer_parent_staff_draft_and_malformed_id_isolation"
    for browser, path in ((b, pdf_path), (a, b_path + "/documents/" + pdf_view["id"])):
        browser.exchange("GET", path, 404)
        browser.exchange("GET", path + "/download", 404)
    b.binary("PUT", pdf_path + "/content", 404, pdf, etag=a_tag)
    b.exchange("DELETE", pdf_path, 404, {}, etag=a_tag)
    for suffix in ("", "/download"):
        a.exchange("GET", a_path + "/documents/not-a-uuid" + suffix, 404)
        admin.exchange("GET", pdf_path.replace(API + "/", API + "/admin/", 1) + suffix, 404)
    a.exchange("GET", pdf_path.replace(API + "/", API + "/admin/", 1), 403)
    CHECKS.append(STAGE)

    STAGE = "real_pdf_upload_scan_and_exact_private_download"
    uploaded, headers = a.binary("PUT", pdf_path + "/content", 200, pdf, etag=a_tag)
    require(document_view(uploaded)["state"] == "quarantined" and headers.get("ETag") == a_tag, "upload_did_not_enter_quarantine")
    pdf_view = await_state(a, pdf_path, "available")
    a.binary("GET", pdf_path + "/download", 200, download=pdf, mime="application/pdf", filename=body["filename"])
    a.binary("PUT", pdf_path + "/content", 200, pdf, etag=a_tag)
    receipt, a_tag = a.record("POST", a_path + "/submissions", 201, {}, etag=a_tag, key="b4-submit-" + secrets.token_hex(16))
    revision_one = receipt["revision_id"]
    require(data(a.exchange("GET", a_path + "/revisions/" + revision_one, 200)[0])["document_id"] == pdf_view["id"], "pdf_revision_attachment_missing")
    CHECKS.append(STAGE)

    STAGE = "real_docx_amendment_scan_and_immutable_pdf_history"
    _, a_tag = a.record("POST", a_path + "/amendments", 200, {}, etag=a_tag)
    docx_view, a_tag, _, _ = reserve(a, a_path, a_tag, file_prefix + "-safe.docx", docx)
    docx_path = a_path + "/documents/" + docx_view["id"]
    admin.exchange("GET", docx_path.replace(API + "/", API + "/admin/", 1), 404)
    a.binary("PUT", docx_path + "/content", 200, docx, etag=a_tag)
    # Quarantined attachments are eligible for submission. Scanning may finish
    # before this HTTP request; unit/integration tests control that interleaving.
    receipt, a_tag = a.record("POST", a_path + "/submissions", 201, {}, etag=a_tag, key="b4-amend-" + secrets.token_hex(16))
    docx_view = await_state(a, docx_path, "available")
    a.binary("GET", docx_path + "/download", 200, download=docx,
             mime="application/vnd.openxmlformats-officedocument.wordprocessingml.document", filename=file_prefix + "-safe.docx")
    require(data(a.exchange("GET", a_path + "/revisions/" + revision_one, 200)[0])["document_id"] == pdf_view["id"], "historical_pdf_attachment_changed")
    require(data(a.exchange("GET", a_path + "/revisions/" + receipt["revision_id"], 200)[0])["document_id"] == docx_view["id"], "docx_revision_attachment_missing")
    a.exchange("DELETE", docx_path, 409, {}, etag=a_tag)
    CHECKS.append(STAGE)

    STAGE = "staff_parent_assignment_and_historical_download_authority"
    admin.call("POST", API + "/auth/password/confirm", 200, {"password": accounts["admin"]["password"]})
    admin_path = a_path.replace(API + "/", API + "/admin/", 1)
    _, a_tag = admin.record("POST", admin_path + "/assignments", 200, {"assignee_id": accounts["admin"]["id"]}, etag=a_tag)
    staff_pdf = admin_path + "/documents/" + pdf_view["id"]
    manager.exchange("GET", staff_pdf, 404)
    manager.exchange("GET", staff_pdf + "/download", 404)
    admin.binary("GET", staff_pdf + "/download", 200, download=pdf, mime="application/pdf", filename=body["filename"])
    _, a_tag = admin.record("POST", admin_path + "/assignments", 200, {"assignee_id": accounts["manager"]["id"]}, etag=a_tag)
    require(document_view(manager.exchange("GET", staff_pdf, 200)[0])["id"] == pdf_view["id"], "assigned_staff_metadata_missing")
    manager.binary("GET", staff_pdf + "/download", 200, download=pdf, mime="application/pdf", filename=body["filename"])
    manager.exchange("DELETE", pdf_path, 403, {}, etag=a_tag)
    CHECKS.append(STAGE)

    STAGE = "real_malware_scanner_rejects_eicar_and_withholds_bytes"
    # EICAR is the standard harmless antivirus test signature. Keep its exact
    # bytes in a ZIP entry so real archive scanning detects it before inspection.
    infected_buffer = io.BytesIO(docx_bytes())
    with zipfile.ZipFile(infected_buffer, "a", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("word/eicar.txt", b"X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*")
    infected = infected_buffer.getvalue()
    rejected, b_tag, _, _ = reserve(b, b_path, b_tag, file_prefix + "-eicar.docx", infected)
    rejected_path = b_path + "/documents/" + rejected["id"]
    b.binary("PUT", rejected_path + "/content", 200, infected, etag=b_tag)
    await_state(b, rejected_path, "rejected")
    b.exchange("GET", rejected_path + "/download", 409)
    b.exchange("POST", b_path + "/submissions", 409, {}, etag=b_tag, key="b4-reject-" + secrets.token_hex(16))
    a.exchange("GET", rejected_path, 404)
    CHECKS.append(STAGE)
    print(json.dumps({"ok": True, "checks": CHECKS, "synthetic_documents": 3,
                      "retained_revision_attachments": 2}, separators=(",", ":")))


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
        print(json.dumps({"ok": False, "stage": STAGE, "error": "unexpected_smoke_failure", "checks": CHECKS}, separators=(",", ":")), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
