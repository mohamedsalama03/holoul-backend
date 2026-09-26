# B8 — Bounded project document metadata reads

## Measurement and change

The same authenticated customer HTTP list was measured against one, three and 25 real project attachments, created through the existing reservation/finalization actions and cleared by the deterministic scanner double. Every list includes the existing session, identity, project ownership and visibility checks. This is a query-count regression measurement, not a production load or latency claim.

| Available attachments | Before SELECTs | After SELECTs |
| --- | ---: | ---: |
| 1 | 16 | 16 |
| 3 | 18 | 16 |
| 25 | 40 | 16 |

Before: `ProjectDocuments::listing` invoked `DocumentService::metadata` for every attachment. After: the Documents-owned `metadataMany` contract reads up to 100 distinct identifiers in one statement under shared document locks, ordered by document ID. It requires the existing outer authorization transaction and checks the exact parent, customer and owning customer user for every document. A mixed authorized/unauthorized request fails as a whole with 404. It returns bounded metadata DTOs and never storage identifiers or bytes.

Quarantined documents need retry eligibility from durable operation state. The bulk implementation uses at most one additional bounded read of failed scan-operation identifiers. It preserves pending, exhausted and manual-retry behavior without a second per-document query loop. The Projects module keeps the existing staff/customer attachment visibility filter and uses only the Documents contract. Pagination remains 25 by default and at most 100; out-of-range input is rejected. Empty pages issue no document metadata query.

No migration, speculative index, cache or public API representation was added by this optimization.

## Evidence

- `artifacts/b8-document-list-baseline.json`, `.log`, `.xml`: measured pre-change behavior (one test, 43 assertions).
- `artifacts/b8-document-list-optimized.json`: repeat measurement of the same fixtures after the change.
- `artifacts/b8-quality/b8-document-list-optimized.json`: measurement preserved from the final complete gate, paired with `holoul-unit-feature.xml` in the same directory. The release collector checks its freshness and the corresponding successful query-count test; an older preflight file cannot substitute for this evidence.
- `tests/Feature/Projects/ProjectDocumentListingQueryTest.php`: regression on constant list-query count plus maximum-page validation.
- `tests/Feature/Documents/DocumentBulkMetadataTest.php`: exact owner scoping, all-or-nothing rejection, transaction/batch bounds and mixed scan-retry eligibility in at most two queries.
- Existing `ProjectDocumentsTest` verifies customer isolation, internal/pending visibility, current permission revocation, retained documents and fresh authorization around storage I/O.

The aggregate B8 report records the final verification outcome. These focused measurements do not substitute for that gate.
