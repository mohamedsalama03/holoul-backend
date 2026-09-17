#!/usr/bin/env python3
"""B5 production-image commercial acceptance through real HTTPS and B4 storage."""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import importlib.util
import json
from pathlib import Path
import secrets
import ssl
import sys

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("documents_smoke", Path(__file__).with_name("verify-documents.py"))
if spec is None or spec.loader is None:
    raise SystemExit(1)
documents = importlib.util.module_from_spec(spec)
spec.loader.exec_module(documents)
intake, identity = documents.intake, documents.identity
data, require, API = identity.data, identity.require, identity.API
CHECKS: list[str] = []
STAGE = "configuration"


def run(args):
    global STAGE
    origin = identity.local_origin(args.origin, https=True)
    context = ssl.create_default_context(cafile=str(args.ca_cert))
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    mail = identity.SandboxMail(identity.local_origin(args.mailpit_url, https=False))
    fixture = intake.fixture(args.fixtures)
    accounts = fixture["accounts"]
    clients = {}
    for label, account in accounts.items():
        STAGE = label + "_login_verification_mfa"
        session = intake.authenticate(account, not label.startswith("customer_"), origin, context, mail)
        clients[label] = documents.Browser(origin, context, session.jar)
    a, b, author, approver = (clients[x] for x in ("customer_a", "customer_b", "admin", "manager"))
    CHECKS.append("real_customer_email_and_staff_mfa")
    prefix = "b3-smoke-" + fixture["run_id"]
    STAGE = "intake_and_private_document"
    category, _ = author.record("POST", API + "/admin/categories", 201,
        {"name": "B5 Synthetic", "slug": prefix + "-category", "active": True, "display_order": 900000})
    child, _ = author.record("POST", API + "/admin/categories/" + category["id"] + "/subcategories", 201,
        {"name": "B5 Synthetic", "slug": prefix + "-subcategory", "active": True, "display_order": 900000})
    record, tag = a.record("POST", API + "/project-requests", 201,
        {"category_id": category["id"], "subcategory_id": child["id"], "project_name": prefix + " commercial",
         "project_description": "Synthetic B5 verification; no customer data.", "budget_unknown": True})
    path = API + "/project-requests/" + record["id"]
    staff = path.replace(API + "/", API + "/admin/", 1)
    pdf = documents.pdf_bytes()
    filename = "b5-smoke-" + fixture["run_id"] + ".pdf"
    document, tag, _, _ = documents.reserve(a, path, tag, filename, pdf)
    doc_path = path + "/documents/" + document["id"]
    a.binary("PUT", doc_path + "/content", 200, pdf, etag=tag)
    documents.await_state(a, doc_path, "available")
    _, tag = a.record("POST", path + "/submissions", 201, {}, etag=tag, key=secrets.token_hex(24))
    _, tag = author.record("POST", staff + "/assignments", 200, {"assignee_id": accounts["admin"]["id"]}, etag=tag)
    _, tag = author.record("POST", staff + "/reviews", 200, {}, etag=tag)
    _, tag = author.record("POST", staff + "/discovery-handoffs", 200, {}, etag=tag)
    CHECKS.append("approved_intake_boundary_real_scanned_pdf")

    def command(browser, method, endpoint, expected=200, body=None, key=None):
        nonlocal tag
        response, headers = browser.exchange(method, endpoint, expected, {} if body is None else body,
                                             etag=tag, key=key or secrets.token_hex(24))
        value = data(response)
        next_tag = headers.get("ETag")
        require(isinstance(next_tag, str) and next_tag.startswith('"' + record["id"] + ':'), "commercial_etag_parent_invalid")
        tag = next_tag
        return value

    def discovery(revision):
        result = command(author, "POST", staff + "/discovery", 201,
                         {"summary": "Synthetic discovery revision " + str(revision), "internal_notes": "Staff-only synthetic notes."})
        root = staff + "/discovery/" + result["id"]
        command(author, "PUT", root + "/requirements", body={"requirements": [
            {"title": "Portal", "description": "Synthetic bounded requirement.", "category": "functional",
             "priority": "must", "notes": "Private synthetic note.", "status": "confirmed"}]})
        command(author, "POST", root + "/starts")
        command(author, "POST", root + "/completions")
        return result["id"]

    def draft(baseline, revision):
        terms = {"discovery_revision_id": baseline, "scope_summary": "Synthetic scope revision " + str(revision),
                 "timeline": "Six weeks after agreed start.", "commercial_notes": "Synthetic terms, no contract.",
                 "pricing_mode": "items", "amount": "30.369", "currency": "LYD",
                 "valid_until": (datetime.now(timezone.utc) + timedelta(days=7)).strftime("%Y-%m-%dT%H:%M:%SZ"),
                 "items": [{"title": "Implementation", "description": "Synthetic line.", "quantity": 3, "unit_price": "10.123"}],
                 "deliverables": ["Portal", "Handover"]}
        result = command(author, "POST", staff + "/proposals", 201, terms)
        root = staff + "/proposals/" + result["id"]
        command(author, "POST", root + "/documents", body={"document_id": document["id"]})
        author.exchange("POST", root + "/approvals", 403, {}, etag=tag, key=secrets.token_hex(24))
        return result["id"], root, terms

    STAGE = "discovery_approval_separation_and_issue"
    baseline = discovery(1)
    first_id, first, _ = draft(baseline, 1)
    a.exchange("GET", path + "/proposals/" + first_id, 404)
    approver.exchange("GET", first, 404)
    _, tag = author.record("POST", staff + "/assignments", 200, {"assignee_id": accounts["manager"]["id"]}, etag=tag)
    command(approver, "POST", first + "/approvals")
    command(author, "POST", first + "/issuances")
    first_terms = data(a.exchange("GET", path + "/proposals/" + first_id, 200)[0])
    require(first_terms["amount"] == "30.369" and first_terms["items"][0]["line_total"] == "30.369", "exact_lyd_total_invalid")
    b.exchange("GET", path + "/proposals/" + first_id, 404)
    CHECKS.append("signed_discovery_exact_pricing_separate_approval_issue_isolation")

    STAGE = "supersession_and_preserved_discovery_history"
    command(author, "POST", first + "/supersessions", body={"reason": "Synthetic changed scope."})
    second_baseline = discovery(2)
    second_id, second, terms = draft(second_baseline, 2)
    command(approver, "POST", second + "/approvals")
    terms["timeline"] = "Eight weeks after agreed start."
    command(author, "PUT", second, body=terms)
    author.exchange("POST", second + "/issuances", 409, {}, etag=tag, key=secrets.token_hex(24))
    command(approver, "POST", second + "/approvals")
    command(author, "POST", second + "/issuances")
    preserved = data(a.exchange("GET", path + "/proposals/" + first_id, 200)[0])
    require(preserved["state"] == "superseded" and preserved["scope_summary"] == first_terms["scope_summary"]
            and preserved["number"] == first_terms["number"], "superseded_terms_changed")
    CHECKS.append("supersession_new_revision_and_edit_invalidates_approval")

    STAGE = "private_proposal_pdf_and_customer_acceptance"
    own = path + "/proposals/" + second_id
    for endpoint in (own, own + "/documents/" + document["id"], own + "/documents/" + document["id"] + "/download"):
        b.exchange("GET", endpoint, 404)
    a.binary("GET", own + "/documents/" + document["id"] + "/download", 200,
             download=pdf, mime="application/pdf", filename=filename)
    key, old_tag = secrets.token_hex(24), tag
    first_result, headers = a.exchange("POST", own + "/acceptances", 200, {}, etag=old_tag, key=key)
    replay, replay_headers = a.exchange("POST", own + "/acceptances", 200, {}, etag=old_tag, key=key)
    require(first_result == replay and headers.get("ETag") == replay_headers.get("ETag"), "acceptance_replay_mismatch")
    require(data(first_result)["state"] == "accepted", "proposal_not_accepted")
    current, _ = a.record("GET", path, 200)
    require(current["state"] == "approved", "request_not_approved")
    author.exchange("PUT", second, 409, terms, etag=headers.get("ETag"), key=secrets.token_hex(24))
    CHECKS.append("private_proposal_pdf_exact_bytes_customer_acceptance_and_replay")
    print(json.dumps({"ok": True, "checks": CHECKS, "proposal_states": ["superseded", "accepted"],
                      "discovery_revisions": 2, "request_state": "approved", "proposal_document_references": 2}, separators=(",", ":")))


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
