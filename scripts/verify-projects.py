#!/usr/bin/env python3
"""B6 production-image conversion and delivery acceptance over real HTTPS."""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import secrets
import ssl
import sys
import time

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
        {"name": "B6 Synthetic", "slug": prefix + "-category", "active": True, "display_order": 900000})
    child, _ = author.record("POST", API + "/admin/categories/" + category["id"] + "/subcategories", 201,
        {"name": "B6 Synthetic", "slug": prefix + "-subcategory", "active": True, "display_order": 900000})
    record, tag = a.record("POST", API + "/project-requests", 201,
        {"category_id": category["id"], "subcategory_id": child["id"], "project_name": prefix + " delivery",
         "project_description": "Synthetic B6 verification; no customer data.", "budget_unknown": True})
    path = API + "/project-requests/" + record["id"]
    staff = path.replace(API + "/", API + "/admin/", 1)
    pdf = documents.pdf_bytes()
    filename = "b6-baseline-" + fixture["run_id"] + ".pdf"
    document, tag, _, _ = documents.reserve(a, path, tag, filename, pdf)
    doc_path = path + "/documents/" + document["id"]
    a.binary("PUT", doc_path + "/content", 200, pdf, etag=tag)
    documents.await_state(a, doc_path, "available")
    _, tag = a.record("POST", path + "/submissions", 201, {}, etag=tag, key=secrets.token_hex(24))
    _, tag = author.record("POST", staff + "/assignments", 200, {"assignee_id": accounts["admin"]["id"]}, etag=tag)
    _, tag = author.record("POST", staff + "/reviews", 200, {}, etag=tag)
    _, tag = author.record("POST", staff + "/discovery-handoffs", 200, {}, etag=tag)

    def commercial(browser, endpoint, body=None, expected=200, method="POST"):
        nonlocal tag
        response, headers = browser.exchange(method, endpoint, expected, {} if body is None else body,
                                             etag=tag, key=secrets.token_hex(24))
        tag = headers.get("ETag")
        require(isinstance(tag, str) and tag.startswith('"' + record["id"] + ':'), "commercial_etag_parent_invalid")
        return data(response)

    STAGE = "accepted_immutable_commercial_baseline"
    discovery = commercial(author, staff + "/discovery",
                           {"summary": "Synthetic delivery scope.", "internal_notes": "Internal discovery note."}, 201)
    discovery_path = staff + "/discovery/" + discovery["id"]
    commercial(author, discovery_path + "/requirements", {"requirements": [
        {"title": "Portal", "description": "Synthetic bounded requirement.", "category": "functional",
         "priority": "must", "notes": "Internal requirement note.", "status": "confirmed"}]}, method="PUT")
    commercial(author, discovery_path + "/starts")
    commercial(author, discovery_path + "/completions")
    terms = {"discovery_revision_id": discovery["id"], "scope_summary": "Synthetic immutable accepted delivery scope.",
             "timeline": "Six weeks after agreed start.", "commercial_notes": "Synthetic commercial conditions.",
             "pricing_mode": "items", "amount": "30.369", "currency": "LYD",
             "valid_until": (datetime.now(timezone.utc) + timedelta(days=7)).strftime("%Y-%m-%dT%H:%M:%SZ"),
             "items": [{"title": "Implementation", "description": "Synthetic line.", "quantity": 3, "unit_price": "10.123"}],
             "deliverables": ["Portal", "Handover"]}
    proposal = commercial(author, staff + "/proposals", terms, 201)
    proposal_staff = staff + "/proposals/" + proposal["id"]
    proposal_own = path + "/proposals/" + proposal["id"]
    commercial(author, proposal_staff + "/documents", {"document_id": document["id"]})
    _, tag = author.record("POST", staff + "/assignments", 200, {"assignee_id": accounts["manager"]["id"]}, etag=tag)
    commercial(approver, proposal_staff + "/approvals")
    commercial(author, proposal_staff + "/issuances")
    commercial(a, proposal_own + "/acceptances")
    accepted = data(a.exchange("GET", proposal_own, 200)[0])
    require(accepted["state"] == "accepted" and accepted["amount"] == "30.369", "accepted_baseline_invalid")
    CHECKS.append("real_b3_handoff_b4_pdf_b5_separate_approval_and_acceptance")

    STAGE = "atomic_conversion_and_idempotency"
    conversion = staff + "/conversions"
    a.exchange("POST", conversion, 403, {}, etag=tag, key=secrets.token_hex(24))
    conversion_key, request_tag = secrets.token_hex(24), tag
    response, headers = author.exchange("POST", conversion, 201, {}, etag=request_tag, key=conversion_key)
    replay, replay_headers = author.exchange("POST", conversion, 201, {}, etag=request_tag, key=conversion_key)
    require(response == replay and headers.get("ETag") == replay_headers.get("ETag"), "conversion_replay_mismatch")
    project = data(response)
    project_id, tag = project["id"], headers.get("ETag")
    require(project["state"] == "planning", "conversion_result_invalid")
    require(re.fullmatch(r"PRJ-[0-9]{4}-[0-9]{5,}", project["reference"]) is not None, "project_reference_invalid")
    project_staff, project_own = API + "/admin/projects/" + project_id, API + "/projects/" + project_id
    require(isinstance(tag, str) and tag.startswith('"' + project_id + ':'), "project_etag_invalid")
    require(headers.get("Location", "").endswith(project_staff), "conversion_location_invalid")
    project_detail = data(a.exchange("GET", project_own, 200)[0])
    require(project_detail["source_request_id"] == record["id"] and isinstance(project_detail.get("accepted_baseline"), dict),
            "project_baseline_link_invalid")
    project_baseline = project_detail["accepted_baseline"]
    require(project_baseline["number"] == accepted["number"] and project_baseline["revision_number"] == accepted["revision_number"]
            and project_baseline["scope_summary"] == terms["scope_summary"] and project_baseline["timeline"] == terms["timeline"]
            and project_baseline["amount"] == "30.369" and project_baseline["currency"] == "LYD"
            and project_baseline["deliverables"] == ["Portal", "Handover"], "project_accepted_baseline_mismatch")
    current, converted_tag = a.record("GET", path, 200)
    require(current["state"] == "converted", "request_not_converted")
    author.exchange("POST", conversion, 409, {}, etag=converted_tag, key=secrets.token_hex(24))
    a.exchange("POST", proposal_own + "/rescissions", 409, {"reason": "Synthetic forbidden rescission after conversion."},
               etag=converted_tag, key=secrets.token_hex(24))
    CHECKS.append("one_project_conversion_exact_replay_immutable_reference_and_source")

    def command(endpoint, body=None, *, browser=author, method="POST", expected=200):
        nonlocal tag
        response, headers = browser.exchange(method, endpoint, expected, {} if body is None else body,
                                             etag=tag, key=secrets.token_hex(24))
        next_tag = headers.get("ETag")
        require(isinstance(next_tag, str) and next_tag.startswith('"' + project_id + ':'), "project_command_etag_invalid")
        tag = next_tag
        return data(response)

    def evidence(kind):
        return command(project_staff + "/evidence", {"kind": kind, "summary": "Synthetic recorded " + kind + " evidence."})

    def advance(expected):
        command(project_staff + "/advances")
        view = data(a.exchange("GET", project_own, 200)[0])
        require(view["state"] == expected, "project_phase_invalid_" + expected)

    STAGE = "customer_isolation_and_project_team"
    b.exchange("GET", project_own, 404)
    foreign_list = b.exchange("GET", API + "/projects", 200)[0]
    require(project_id not in json.dumps(foreign_list), "foreign_project_in_customer_list")
    a.exchange("POST", project_staff + "/advances", 403, {}, etag=tag, key=secrets.token_hex(24))
    approver.exchange("GET", project_staff, 404)
    member = command(project_staff + "/team-members", {"staff_id": accounts["manager"]["id"], "role": "project_manager"}, expected=201)
    approver.exchange("GET", project_staff, 200)
    CHECKS.append("customer_ab_isolation_staff_membership_and_customer_mutation_denial")

    STAGE = "milestones_and_customer_visible_updates"
    for position, visible in ((1, True), (2, False)):
        milestone = command(project_staff + "/milestones",
                            {"name": "Synthetic public handover" if visible else "Synthetic internal review",
                             "description": "Synthetic bounded delivery milestone.", "display_order": position,
                             "responsible_member_id": member["id"], "customer_visible": visible}, expected=201)
        milestone_path = project_staff + "/milestones/" + milestone["id"]
        command(milestone_path + "/starts")
        if visible:
            command(milestone_path + "/delays", {"reason": "Synthetic short delay."})
            command(milestone_path + "/starts")
        command(milestone_path + "/completions")
    milestones = json.dumps(a.exchange("GET", project_own + "/milestones", 200)[0])
    require("Synthetic public handover" in milestones and "Synthetic internal review" not in milestones,
            "milestone_customer_visibility_invalid")
    b.exchange("GET", project_own + "/milestones", 404)
    public_update = "Synthetic customer-visible delivery update."
    command(project_staff + "/updates", {"content": public_update}, expected=201)
    updates = json.dumps(a.exchange("GET", project_own + "/updates", 200)[0])
    require(public_update in updates and "Internal discovery note." not in updates, "customer_update_projection_invalid")
    b.exchange("GET", project_own + "/updates", 404)
    CHECKS.append("milestone_commands_visibility_scoped_responsibility_and_published_updates")

    STAGE = "private_project_documents"
    project_documents = []
    for visibility in ("customer", "internal"):
        name = "b6-" + visibility + "-" + fixture["run_id"] + ".pdf"
        reservation = command(project_staff + "/documents",
                              {"filename": name, "bytes": len(pdf), "sha256": hashlib.sha256(pdf).hexdigest(),
                               "visibility": visibility}, expected=201)
        private_path = project_staff + "/documents/" + reservation["id"]
        _, upload_headers = author.binary("PUT", private_path + "/content", 200, pdf, etag=tag)
        tag = upload_headers.get("ETag")
        require(isinstance(tag, str) and tag.startswith('"' + project_id + ':'), "project_upload_etag_invalid")
        deadline = time.monotonic() + 150
        while True:
            status = data(author.exchange("GET", private_path, 200)[0])
            if status["state"] == "available":
                break
            require(status["state"] == "quarantined" and time.monotonic() < deadline, "project_document_scan_failed")
            time.sleep(1)
        public_path = project_own + "/documents/" + reservation["id"]
        b.exchange("GET", public_path, 404)
        b.exchange("GET", public_path + "/download", 404)
        if visibility == "customer":
            a.binary("GET", public_path + "/download", 200, download=pdf, mime="application/pdf", filename=name)
        else:
            a.exchange("GET", public_path, 404)
            a.exchange("GET", public_path + "/download", 404)
            author.binary("GET", private_path + "/download", 200, download=pdf, mime="application/pdf", filename=name)
        project_documents.append(reservation["id"])
    visible_documents = json.dumps(a.exchange("GET", project_own + "/documents", 200)[0])
    require(project_documents[0] in visible_documents and project_documents[1] not in visible_documents,
            "customer_project_document_list_visibility_invalid")
    CHECKS.append("b4_real_project_scans_exact_private_bytes_explicit_visibility_and_isolation")

    STAGE = "guarded_lifecycle_hold_resume_and_failures"
    author.exchange("POST", project_staff + "/advances", 409, {}, etag=tag, key=secrets.token_hex(24))
    evidence("plan_approved")
    advance("design")
    evidence("design_approved")
    advance("development")
    command(project_staff + "/holds", {"reason": "Synthetic pause.", "customer_communication": "Synthetic pause shared."})
    require(data(a.exchange("GET", project_own, 200)[0])["state"] == "on_hold", "project_not_held")
    command(project_staff + "/resumptions", {"reason": "Synthetic restart.", "conditions": "Synthetic blocker resolved."})
    require(data(a.exchange("GET", project_own, 200)[0])["state"] == "development", "resume_did_not_restore_phase")
    evidence("delivery_candidate")
    advance("testing")
    command(project_staff + "/failures", {"reason": "Synthetic QA failure.", "customer_communication": "Synthetic QA retry shared."})
    require(data(a.exchange("GET", project_own, 200)[0])["state"] == "development", "qa_failure_did_not_return_to_development")
    evidence("delivery_candidate")
    advance("testing")
    evidence("qa_passed")
    advance("deployment")
    command(project_staff + "/failures", {"reason": "Synthetic deployment rollback.", "customer_communication": "Synthetic rollback shared."})
    require(data(a.exchange("GET", project_own, 200)[0])["state"] == "testing", "deployment_failure_did_not_return_to_testing")
    evidence("qa_passed")
    advance("deployment")
    CHECKS.append("phase_evidence_hold_resume_testing_failure_and_deployment_rollback")

    STAGE = "customer_confirmed_completion_and_baseline_preservation"
    author.exchange("POST", project_staff + "/advances", 409, {}, etag=tag, key=secrets.token_hex(24))
    evidence("deployment_succeeded")
    author.exchange("POST", project_staff + "/advances", 409, {}, etag=tag, key=secrets.token_hex(24))
    b.exchange("POST", project_own + "/completion-confirmations", 404, {}, etag=tag, key=secrets.token_hex(24))
    # The B4 scans can outlive the recent-authentication window on a slow host.
    a.exchange("POST", API + "/auth/password/confirm", 200, {"password": accounts["customer_a"]["password"]})
    command(project_own + "/completion-confirmations", browser=a)
    advance("completed")
    author.exchange("POST", project_staff + "/holds", 409,
                    {"reason": "Forbidden terminal reopen.", "customer_communication": "Synthetic."}, etag=tag, key=secrets.token_hex(24))
    preserved = data(a.exchange("GET", proposal_own, 200)[0])
    require(preserved == accepted, "accepted_commercial_baseline_changed")
    require(data(a.exchange("GET", project_own, 200)[0])["accepted_baseline"] == project_baseline,
            "completed_project_accepted_baseline_changed")
    a.binary("GET", proposal_own + "/documents/" + document["id"] + "/download", 200,
             download=pdf, mime="application/pdf", filename=filename)
    CHECKS.append("deployment_and_exact_customer_confirmation_required_terminal_completed_immutable_baseline")
    print(json.dumps({"ok": True, "checks": CHECKS, "request_state": "converted", "project_state": "completed",
                      "accepted_proposal_state": "accepted", "project_private_documents": len(project_documents)}, separators=(",", ":")))


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
