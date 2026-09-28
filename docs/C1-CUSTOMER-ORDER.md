# C1 — Customer registration ordering

C1 response: **done — use sort=newest** on adminCustomerList. This follow-up is isolated from the accepted staff local checkpoint 0ac65486c3f4379786cd206358e2ce98f8be17a5. It implements the remaining Customers/Categories memo item only.

## Dashboard usage

```http
GET /api/v1/admin/customers?sort=newest&limit=25
GET /api/v1/admin/customers?sort=newest&limit=25&after=<meta.next_after>
```

Pass the returned UUID cursor unchanged, with the same sort and filters on every page. Clear the cursor and load the first page whenever sort, search, status or email-verification filters change. A new registration appears on a refreshed newest-first page; it is not inserted into the continuation of an already-started list. A null next_after means there is no next page at the time of that request.

Registration order uses server-generated customer UUIDv7, descending for newest and ascending for oldest. The existing after cursor remains an exclusive UUID position; comparison follows the selected direction. There is no offset, total count or additional index/migration. PostgreSQL can traverse the existing primary-key index in either direction.

Requests without sort keep their existing oldest-first behavior. Explicit sort=oldest is equivalent. Only the customer directory list accepts this parameter; related project/request lists and staff pickers keep their existing pagination. Values other than oldest/newest, arrays and unknown query fields return 422.

The existing permission and visibility rules still apply on every page. Administrator and Support do not gain directory access; their catalogue and invitation grants remain unchanged. Reads remain private/no-store and do not reissue unchanged authenticated session cookies. Contact fields and response schemas remain unchanged.

## Contract delta

- Reviewed previous API: 1.2.0-staff-candidate.
- New candidate API: 1.3.0-directory-sort-candidate; OpenAPI 3.1.1.
- Previous SHA-256: af3c96ac459717fb3ab4c86ac68513d4516e843e410de36820af2e188eded43d.
- New SHA-256: fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e.
- 182 operations remain; zero added/removed operations, schemas or security schemes.
- Only adminCustomerList changes: optional sort enum oldest/newest (default oldest), with ordering/cursor documentation. Existing responses, security, required parameters and defaults are preserved.
- All other operation definitions and the complete components object were compared structurally and are identical to the prior contract.

The authoritative contract is docs/openapi.json in this checkpoint. The frontend contract copy is not modified. The dashboard should adopt this candidate in its own contract commit, then use sort=newest for Customers and rerun its acceptance suite. Earlier af3c96ac… staff evidence remains historical evidence for that exact baseline, not a claim that the new contract hash is identical.

## Local service profile

The C1 runtime is selected consistently for web, all workers and scheduler by compose.customer-order-local.yaml. Use it with the original topology:

```bash
docker compose -p holoul \
  -f /home/mohamed/projects/customers/holoul/compose.yaml \
  -f /home/mohamed/projects/customers/holoul-customer-directory-order/compose.customer-order-local.yaml ps
```

This requires existing migration 31. No database migration is introduced or applied by C1. The five-account private E2E fixture from the staff local handoff remains valid; the existing staff worktree reset can continue to be used with --image holoul-app:customer-order-development. Reset invalidates those exact fixture sessions and rotates their MFA secrets; serialize it with E2E runs. Invitation and taxonomy history cleanup decisions from the staff handoff remain in effect.

This is a local integration candidate. The frozen G1 VPS branch, frontend implementation and contract copy are outside this change. No VPS deployment or production performance certification is included.

## Verified result — 28 September 2026

C1 is **BACKEND READY FOR DASHBOARD CONTRACT ADOPTION**. It is live on https://localhost:8443. The response to C1 is **done — use sort=newest**. Existing requests without this parameter preserve their prior order.

Runtime image: holoul-app:customer-order-runtime, ID sha256:d9ecddaab6437d96f69942cb278a0db7567f05bf5e2b609dc6ef9de5fe824ba9. All six PHP services were switched together and verified healthy with the matching contract label. No migration or database rollback was needed; shared holoul still has migration 31. Web and queue traffic were resumed after the new services became healthy.

| Verification | Result |
| --- | --- |
| Unit tests | 144 tests / 358 assertions passed |
| Focused integration/security/API/concurrency tests | 69 tests / 3,223 assertions passed in one run |
| Architecture guards | 9 tests / 44,995 assertions passed |
| Pint | 589 files passed |
| PHPStan/Larastan | Level 10, zero errors |
| Composer | Strict validation, platform checks and audit passed |
| OpenAPI | Structural validation, generated-source check and 182/182 route/spec match passed |
| Contract compatibility | Only adminCustomerList optional sort and its cursor documentation changed; all components and other operations unchanged |
| PostgreSQL | Fresh/repeat migration checks passed on holoul_test; shared holoul retained 31 migrations |
| Docker | Development/runtime builds, non-root/FPM/fixture-exclusion checks passed |
| Source security | Vulnerability/secret scan passed with cached scanner database, including development dependencies |
| Live same-origin API | Real MFA; descending order and exclusive cursor; disjoint pages; default unchanged; invalid sort rejected; existing Administrator denial and no Set-Cookie on reads preserved |
| Frontend regression | F1 6/6 and Overview 1/1 passed, no test edits, retries or timeout changes |
| Final fixture reset | Five owned identities restored, private exports refreshed after the suite |

The new PostgreSQL regression tests check both legacy/default and explicit oldest order, descending continuation without duplicates, later registrations between pages, active/disabled filters, invalid input, visibility restrictions and query counts independent of page size. Existing session lifecycle, guest-session isolation and staff concurrency tests were kept unchanged and passed. No failed attempts occurred in this C1 gate; earlier staff/disk-outage evidence remains in its original checkpoint.

Detailed sanitized results are in C1-CUSTOMER-ORDER-VERIFICATION.json. The full inherited backend suite was not rerun for this bounded ordering change. All 291 recorded frontend source files and its G1 contract hash remain unchanged. The previous staff checkpoint is clean at 0ac6548, and the frozen VPS checkpoint remains clean at b6d3f27; the original backend checkout also remains unchanged.

C1 does not change L2/C2 fixtures, L3 Mailpit access, role grants, or L4/C3 retained-history and exact-ID deactivation decisions. The team can now adopt the new authoritative contract and send sort=newest in its own frontend change. This task does not implement that frontend change or certify production performance.
