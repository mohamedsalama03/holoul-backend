#!/usr/bin/env python3
"""B7 acceptance with real HTTPS, queued local AI, private parsing and sandbox SMTP."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import secrets
import ssl
import sys
import time
import urllib.parse

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("projects_smoke", Path(__file__).with_name("verify-projects.py"))
if spec is None or spec.loader is None:
    raise SystemExit(1)
projects = importlib.util.module_from_spec(spec)
spec.loader.exec_module(projects)
documents, intake, identity = projects.documents, projects.intake, projects.identity
data, require, API = identity.data, identity.require, identity.API
CHECKS: list[str] = []
STAGE = "configuration"


def private_projection(document):
    encoded = json.dumps(document)
    for forbidden in ("storage_key", "storage_version", "document_object_version", "quarantine/", "X-Amz-", "http://", "https://"):
        require(forbidden not in encoded, "private_ai_projection_leaked_storage_details")


def await_run(browser, run_id):
    deadline = time.monotonic() + 150
    while time.monotonic() < deadline:
        run, tag = browser.record("GET", API + "/ai-runs/" + run_id, 200)
        private_projection(run)
        if run["state"] == "succeeded":
            require(run["provider"] == "sandbox" and run["model"] == "sandbox-v1", "unexpected_ai_provider")
            require(isinstance(run.get("suggestion"), dict) and run["suggestion"]["state"] == "pending", "ai_suggestion_missing")
            return run, tag
        require(run["state"] in ("pending", "processing"), "queued_ai_run_failed")
        time.sleep(1)
    raise identity.SmokeFailure("queued_ai_run_timeout")


def await_sandbox_notice(mail, recipient):
    deadline = time.monotonic() + 60
    query = urllib.parse.urlencode({"query": "to:" + recipient, "limit": 50})
    while time.monotonic() < deadline:
        result = mail.get("/api/v1/search?" + query, deadline)
        require(isinstance(result, dict) and isinstance(result.get("messages"), list), "sandbox_mail_schema_invalid")
        for summary in result["messages"]:
            if not isinstance(summary, dict) or summary.get("Subject") != "Your AI suggestion is ready" or not mail.recipient_matches(summary, recipient):
                continue
            message_id = summary.get("ID")
            require(isinstance(message_id, str) and re.fullmatch(r"[A-Za-z0-9_-]{1,128}", message_id) is not None, "sandbox_message_id_invalid")
            message = mail.get("/api/v1/message/" + message_id, deadline)
            require(isinstance(message, dict) and mail.recipient_matches(message, recipient), "sandbox_recipient_mismatch")
            text = message.get("Text", message.get("Plain"))
            require(isinstance(text, str) and "Sign in to HOLOUL to review this update." in text, "sandbox_notice_body_invalid")
            require("Synthetic" not in text and "quarantine/" not in text and "X-Amz-" not in text, "sandbox_notice_exposed_source")
            return
        time.sleep(1)
    raise identity.SmokeFailure("sandbox_workflow_mail_timeout")


def run(args):
    global STAGE
    origin = identity.local_origin(args.origin, https=True)
    context = ssl.create_default_context(cafile=str(args.ca_cert))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    mail = identity.SandboxMail(identity.local_origin(args.mailpit_url, https=False))
    fixture = intake.fixture(args.fixtures)
    accounts, clients = fixture["accounts"], {}
    for label, account in accounts.items():
        STAGE = label + "_real_login_verification_mfa"
        session = intake.authenticate(account, not label.startswith("customer_"), origin, context, mail)
        clients[label] = documents.Browser(origin, context, session.jar)
    a, b, admin = (clients[label] for label in ("customer_a", "customer_b", "admin"))
    CHECKS.append("real_customer_email_and_staff_mfa")
    prefix = "b3-smoke-" + fixture["run_id"]
    STAGE = "mutable_customer_draft"
    category, _ = admin.record("POST", API + "/admin/categories", 201,
        {"name": "B7 Synthetic", "slug": prefix + "-category", "active": True, "display_order": 900000})
    child, _ = admin.record("POST", API + "/admin/categories/" + category["id"] + "/subcategories", 201,
        {"name": "B7 Synthetic", "slug": prefix + "-subcategory", "active": True, "display_order": 900000})
    original = "Synthetic B7 customer portal description awaiting an explicit human decision."
    record, tag = a.record("POST", API + "/project-requests", 201,
        {"category_id": category["id"], "subcategory_id": child["id"], "project_name": prefix + " assistance",
         "project_description": original, "budget_unknown": True})
    path = API + "/project-requests/" + record["id"]
    body = {"parent_type": "request", "parent_id": record["id"], "purpose": "improve_description", "consent": True}
    ai_path = API + "/ai-runs"
    STAGE = "consent_preconditions_and_queued_idempotent_run"
    a.exchange("POST", ai_path, 422, {**body, "consent": False}, etag=tag, key=secrets.token_hex(24))
    a.exchange("POST", ai_path, 428, body, key=secrets.token_hex(24))
    a.exchange("POST", ai_path, 422, body, etag=tag)
    b.exchange("POST", ai_path, 404, body, etag=tag, key=secrets.token_hex(24))
    key = secrets.token_hex(24)
    created, _ = a.record("POST", ai_path, 202, body, etag=tag, key=key)
    repeated, _ = a.record("POST", ai_path, 202, body, etag=tag, key=key)
    require(created["id"] == repeated["id"], "ai_idempotency_created_second_run")
    run, run_tag = await_run(a, created["id"])
    run_path = ai_path + "/" + run["id"]
    current, current_tag = a.record("GET", path, 200)
    require(current_tag == tag and current["draft"]["project_description"] == original, "ai_automatically_modified_customer_draft")
    require(run["suggestion"]["output"]["description"] != original, "ai_suggestion_not_separate")
    b.exchange("GET", run_path, 404)
    b.exchange("POST", run_path + "/applications", 404, {}, etag=run_tag, key=secrets.token_hex(24))
    b.exchange("GET", ai_path + "?" + urllib.parse.urlencode({"parent_type": "request", "parent_id": record["id"]}), 404)
    CHECKS.append("consent_etag_queue_idempotency_separate_suggestion_and_customer_ab_isolation")

    STAGE = "in_app_smtp_and_notification_ownership"
    notices = data(a.exchange("GET", API + "/notifications", 200)[0], list)
    matches = [notice for notice in notices if notice["resource_id"] == run["id"] and notice["type"] == "ai.suggestion_ready"]
    require(len(matches) == 1, "suggestion_notice_not_exactly_once")
    notice_id = matches[0]["id"]
    notice, notice_headers = a.exchange("GET", API + "/notifications/" + notice_id, 200)
    require(data(notice)["read_at"] is None, "new_notice_already_read")
    b.exchange("GET", API + "/notifications/" + notice_id, 404)
    b.exchange("POST", API + "/notifications/" + notice_id + "/read", 404, {}, etag=notice_headers.get("ETag"), key=secrets.token_hex(24))
    require(data(b.exchange("GET", API + "/notifications", 200)[0], list) == [], "foreign_notifications_visible")
    unread = data(a.exchange("GET", API + "/notifications/unread-count", 200)[0])["unread"]
    require(unread == 1, "unread_notice_count_invalid")
    read_key = secrets.token_hex(24)
    read, _ = a.exchange("POST", API + "/notifications/" + notice_id + "/read", 200, {}, etag=notice_headers.get("ETag"), key=read_key)
    replay, _ = a.exchange("POST", API + "/notifications/" + notice_id + "/read", 200, {}, etag=notice_headers.get("ETag"), key=read_key)
    require(read == replay and data(read)["read_at"] is not None, "notification_read_replay_invalid")
    require(data(a.exchange("GET", API + "/notifications/unread-count", 200)[0])["unread"] == 0, "read_notice_count_invalid")
    await_sandbox_notice(mail, accounts["customer_a"]["email"])
    CHECKS.append("exactly_once_in_app_notice_real_smtp_safe_body_read_replay_and_owner_scope")

    STAGE = "explicit_human_apply_and_source_staleness"
    apply_key = secrets.token_hex(24)
    applied, _ = a.exchange("POST", run_path + "/applications", 200, {}, etag=run_tag, key=apply_key)
    applied_replay, _ = a.exchange("POST", run_path + "/applications", 200, {}, etag=run_tag, key=apply_key)
    require(applied == applied_replay, "ai_apply_replay_invalid")
    current, tag = a.record("GET", path, 200)
    require(current["draft"]["project_description"] == run["suggestion"]["output"]["description"], "explicit_ai_apply_did_not_update_draft")
    preference, preference_headers = a.exchange("GET", API + "/notifications/preferences", 200)
    require(data(preference) == {"workflow_email": True, "security_messages_required": True}, "default_notice_preferences_invalid")
    disabled, _ = a.exchange("PATCH", API + "/notifications/preferences", 200, {"workflow_email": False},
                             etag=preference_headers.get("ETag"), key=secrets.token_hex(24))
    require(data(disabled) == {"workflow_email": False, "security_messages_required": True}, "security_mail_opt_out_allowed")
    stale, _ = a.record("POST", ai_path, 202, body, etag=tag, key=secrets.token_hex(24))
    stale, stale_tag = await_run(a, stale["id"])
    manual = "Synthetic B7 later human edit supersedes the older AI source."
    _, tag = a.record("PATCH", path + "/draft", 200, {"project_description": manual}, etag=tag)
    a.exchange("POST", ai_path + "/" + stale["id"] + "/applications", 409, {}, etag=stale_tag, key=secrets.token_hex(24))
    current, _ = a.record("GET", path, 200)
    require(current["draft"]["project_description"] == manual, "stale_ai_result_overwrote_newer_draft")
    CHECKS.append("explicit_idempotent_human_apply_stale_source_rejection_and_optional_workflow_email")

    STAGE = "real_available_pdf_docx_queued_extraction"
    parent_ids = [record["id"]]
    for extension, content in (("pdf", documents.pdf_bytes()), ("docx", documents.docx_bytes())):
        document_path, document_tag, parent_id = path, tag, record["id"]
        if extension == "docx":
            # B4 permits one current draft attachment. A second owned draft
            # retains both real source formats without replacing the first.
            second, document_tag = a.record("POST", API + "/project-requests", 201,
                {"category_id": category["id"], "subcategory_id": child["id"], "project_name": prefix + " assistance-docx",
                 "project_description": manual, "budget_unknown": True})
            parent_id = second["id"]
            parent_ids.append(parent_id)
            document_path = API + "/project-requests/" + parent_id
        doc, document_tag, _, _ = documents.reserve(a, document_path, document_tag, "b7-" + fixture["run_id"] + "." + extension, content)
        source = {**body, "parent_id": parent_id, "purpose": "analyze_document", "document_id": doc["id"]}
        a.exchange("POST", ai_path, 409, source, etag=document_tag, key=secrets.token_hex(24))
        private_path = document_path + "/documents/" + doc["id"]
        a.binary("PUT", private_path + "/content", 200, content, etag=document_tag)
        documents.await_state(a, private_path, "available")
        extracted, _ = a.record("POST", ai_path, 202, source, etag=document_tag, key=secrets.token_hex(24))
        extracted, _ = await_run(a, extracted["id"])
        require(extracted["document_checksum"] == hashlib.sha256(content).hexdigest(), "document_ai_checksum_mismatch")
        require("Synthetic B4 private document." in extracted["suggestion"]["output"]["summary"], "document_text_not_extracted_by_queue")
        b.exchange("GET", ai_path + "/" + extracted["id"], 404)
        current, _ = a.record("GET", document_path, 200)
        require(current["draft"]["project_description"] == manual, "document_analysis_changed_draft")
    CHECKS.append("available_only_exact_checksum_pdf_docx_offline_extraction_no_storage_urls_no_automatic_changes")

    STAGE = "read_all_and_final_scope"
    inbox, inbox_headers = a.exchange("GET", API + "/notifications/unread-count", 200)
    require(data(inbox)["unread"] == 3, "subsequent_in_app_notices_suppressed_by_email_preference")
    all_read, _ = a.exchange("POST", API + "/notifications/read-all", 200, {}, etag=inbox_headers.get("ETag"), key=secrets.token_hex(24))
    require(data(all_read)["marked_read"] == 3, "read_all_count_invalid")
    require(data(a.exchange("GET", API + "/notifications/unread-count", 200)[0])["unread"] == 0, "read_all_left_unread_notices")
    runs = []
    for parent_id in parent_ids:
        runs.extend(data(a.exchange("GET", ai_path + "?" + urllib.parse.urlencode({"parent_type": "request", "parent_id": parent_id}), 200)[0], list))
    require(len(runs) == 4 and all(item["state"] == "succeeded" for item in runs), "ai_run_count_or_state_invalid")
    private_projection(runs)
    CHECKS.append("four_durable_runs_email_preference_preserves_in_app_and_read_all")
    print(json.dumps({"ok": True, "checks": CHECKS, "queued_ai_runs": 4, "available_document_formats": ["pdf", "docx"],
                      "provider": "sandbox", "paid_provider_calls": 0}, separators=(",", ":")))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--ca-cert", type=Path, required=True)
    parser.add_argument("--mailpit-url", required=True)
    parser.add_argument("--fixtures", type=Path, required=True)
    try:
        run(parser.parse_args())
    except identity.SmokeFailure as error:
        print(json.dumps({"ok": False, "stage": STAGE, "error": str(error), "checks": CHECKS}), file=sys.stderr)
        return 1
    except Exception:
        print(json.dumps({"ok": False, "stage": STAGE, "error": "unexpected_smoke_failure", "checks": CHECKS}), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
