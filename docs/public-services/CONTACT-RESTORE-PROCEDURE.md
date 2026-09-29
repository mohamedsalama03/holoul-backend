# Contact retention and recovery procedure

The owner has not approved a 180-day duration. Automatic content redaction is hard-disabled, and neither the delivery reconciliation schedule nor portfolio byte cleanup deletes contact content. The email recipient is info@holoul.ly. This document does not authorize production deployment, backup expiry, or a new retention period.

Contact name, email, phone, company and message are encrypted before PostgreSQL persistence using the mounted application key. Queue envelopes contain identifiers only. Immutable audit records and delivery-attempt history contain no message body or sender contact details. Manual redaction leaves the receipt identity/reference/time, safe history and deduplication receipt usable.

A manual redaction does not rewrite historical backup media. Existing operational backups must remain encrypted with separately controlled keys; their retention/expiry and exceptions need the owner's operational policy before production acceptance. Do not advertise immediate deletion from every backup or an unapproved 180-day guarantee.

Before restoring an older snapshot:

1. Quiesce web writes, the scheduler and all workers. Preserve the current encrypted backup and its separately held key. Never overwrite the only current copy to attempt recovery.
2. Export a protected reconciliation ledger of redacted receipt UUIDs and event timestamps from the current contact_messages redacted rows and append-only `contact.redacted` audit events. Include post-snapshot delivery attempt IDs, states and fences. No message content or email addresses are needed in the ledger. Retain the ledger separately from the older backup being restored.
3. Restore into an isolated environment, with outbound email disabled and no public ingress. Verify backup authentication, schema history and application-key availability first.
4. Reapply each later redaction through the authorized Contact action/API, using fresh record ETags and the required recent password confirmation. Already-redacted records are verified and skipped; a repeated DELETE intentionally returns 409. Record a fresh audit event for each reapplied change. Do not alter receipt identity, immutable history, or other modules' ownership.
5. Reconcile delivery history before enabling workers. An older snapshot can show pending/sending even after an external server accepted a message. Treat any unresolved send as uncertain; do not replay or infer non-delivery from restored queue state. Stable Message-ID is not an exactly-once guarantee from SMTP.
6. Check that all five protected content fields are null on every required redacted record, that the receipt remains available for valid idempotent replay, that unauthorized/stale sessions still fail, and that no restored notification includes content. Compare ledger counts and record unresolved IDs before cutover.
7. Obtain the separate integration/operational acceptance, then re-enable the approved local/production processes as appropriate. If the post-snapshot ledger is unavailable, keep the restored environment isolated until redaction and delivery reconciliation can be reviewed.

This batch verifies receipt preservation, irreversible manual redaction, ambiguous delivery, lease expiry and notification gates in PostgreSQL tests. It does not claim a production recovery drill on the target VPS; the inherited B8 evidence and remaining VPS certification are unchanged.
