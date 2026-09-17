# B4 private documents

Only B4 is implemented. The B0 architecture, B1 durable ledger, B2 persisted identity authorization and B3 immutable intake model remain authoritative. Final execution evidence is recorded in B4-VERIFICATION.md.

## API

All routes use the existing same-origin cookie session, CSRF rules, safe errors, request IDs and `/api/v1` prefix. UUIDs are server generated. Request/customer ownership, storage paths and lifecycle fields are never accepted from clients.

| Method and path | Behavior |
| --- | --- |
| `POST project-requests/{request}/documents` | Reserve and attach a new draft document. JSON: `filename`, integer `bytes`, lowercase hexadecimal `sha256`. Requires verified email, `If-Match` on the parent and a 16–128 character `Idempotency-Key`. |
| `PUT project-requests/{request}/documents/{document}/content` | Stream raw `application/octet-stream` bytes. Requires the reservation's parent ETag. No multipart metadata, client path or URL is accepted. |
| `GET project-requests/{request}/documents/{document}` | Read safe metadata and processing status. |
| `GET project-requests/{request}/documents/{document}/download` | Download an Available attachment through authenticated application streaming. |
| `DELETE project-requests/{request}/documents/{document}` | Remove the editable draft slot with `If-Match`; queue deletion only when no retained reference exists. |
| `POST project-requests/{request}/documents/{document}/scan-retries` | Audited retry of an exhausted transient scan failure, with current owner authorization and `If-Match`. At most two manual retries per document. |
| `GET admin/project-requests/{request}/documents/{document}` | Staff metadata for a submitted attachment with explicit `documents.read` and current Intake scope. |
| `GET admin/project-requests/{request}/documents/{document}/download` | Staff download with explicit `documents.download` and current Intake scope. |

Reservations return 201; other successful metadata mutations return 200. Metadata contains `id`, sanitized `filename`, `format`, `mime`, `bytes`, lowercase lifecycle `state`, document `version` and `retryable`. ETag headers refer to the parent request. File status can change asynchronously without changing the parent ETag. Responses contain no object keys, provider version IDs, URLs, checksums, scanner diagnostics or file bytes. Intake detail/revision responses add only `document_id`; list responses contain no document contents.

The two new permissions are granted to Super Admin, Project Manager, Business Analyst and Reviewer. Their existing assignment/read scopes still apply. Administrator, Sales and Support receive neither permission. Staff cannot read an unsubmitted draft attachment or edit customer attachments. Every parent and document lookup is checked again against current persisted access, including after storage I/O.

## Upload, history and download

`DocumentPolicy` centralizes the approved 10 MiB PDF/DOCX policy, two outstanding Uploading reservations, 1 GiB reserved/live bytes per customer, 1,000 live metadata records, a ten-minute upload window and 24-hour cleanup grace. A locked customer quota row serializes simultaneous reservations. The count cap bounds quota/reconciliation work; retained history consumes quota until a separately approved retention process releases it.

Reservation creates metadata plus the single typed draft slot in one transaction. Replacement creates a new generated key and object; an idempotency replay cannot resurrect a removed document. Bytes are bounded, MIME/signature/size/SHA-256 checked and streamed into immutable private storage outside the authorization transaction. Finalization reacquires current identity/session, parent version, editable slot and document locks. A changed parent, revoked account or stale session cannot finalize. Upload success followed by failed finalization remains unavailable; a freshly authorized exact-byte retry may finalize within the upload window.

Only Quarantined or Available documents can be snapshotted into a submitted revision. Pending scanning does not block text submission. An incomplete or rejected attachment can be removed before submitting text. Revisions preserve one exact document reference; later amendments never overwrite it. The database constrains parent/customer/document ownership, prevents historical attachment insertion after its creation transaction (including nested savepoints), and prohibits update/delete/truncate of retained attachment history. A retained document cannot transition to deletion, even through direct SQL.

Uploads become Available only after the actual antivirus and bounded offline structural inspector both accept the exact stored version and SHA-256. Malicious/unsupported structures become Rejected; infrastructure/scanner failures stay Quarantined and unavailable. A later rejection preserves the original revision reference and denies bytes.

Downloads obtain an internal actor/version-bound 60-second grant, verify the exact private object into bounded temporary storage, then reacquire current authorization and consume the original grant before streaming any byte. Grant issuance and download start have separate durable audit events. No claim is made that the client received all bytes. Responses use attachment disposition, private/no-store caching, nosniff, and a restrictive content security policy. There is no public sharing or presigned URL route.

## Durable processing and cleanup

Three document operation kinds use the existing PostgreSQL ledger: `documents.scan`, `documents.delete`, `documents.delete_orphan`. Redis serializes operation identifiers only. The separate `documents` connection/queue has 120-second job timeout, 150-second PostgreSQL lease, 180-second Redis retry window, three attempts and 30/120-second backoff plus jitter. Default B1/B2 operation policy stays unchanged. Fenced database completion cannot publish stale scan approval or overwrite a newer deletion state; external exact-version deletion is idempotent after ambiguous success.

Every five minutes `documents:expire-uploads --limit=20` releases abandoned Uploading draft slots only after the 24-hour grace and upload expiry. It locks parent then document, rechecks state and historical references, advances the parent ETag, audits as a service action and queues deletion. Closed abandoned amendment drafts use the same narrow expired-upload rule; submitted revision attachments are untouched. Persistent cursors prevent old retained rows from starving later cleanup.

`documents:reconcile --limit=20` handles unreferenced metadata and aged object versions. It never automatically authorizes an interrupted upload. Known referenced versions are preserved; missing-row/late-upload objects acquire durable orphan deletion intent. Failures remain recorded. Ordinary retries are bounded; after exhaustion, operators can run `documents:retry-deletions --limit=20` to issue an explicit audited retry against the exact failed document/orphan operation. Neither scheduler nor reconciler resets exhausted attempts forever.

## Storage and inspection

See [B4 storage and inspection](B4-STORAGE-INSPECTION.md) for pinned components, real-provider behavior and parser limits. Technical protocols remain behind Documents-owned contracts and provider adapters; the scanners/parsers receive no database/storage credentials or outbound network. Bounded private temporary files are permitted by B0 and removed after each operation; persistent customer bytes live only in private object storage.

This conservative format profile intentionally rejects complex active, encrypted, externally linked or unsupported PDF/DOCX features. It is not a promise that every otherwise valid office document is accepted, nor a guarantee of safety in every third-party viewer. Production region, retention, backup expiry, independently controlled audit archive and recovery objectives still require the previously documented operational decisions before real customer data is enabled. No AI, Discovery, Proposals, Projects or Reporting implementation is included.
