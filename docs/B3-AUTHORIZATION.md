# B3 authorization and contact contracts

Identity supplies server-created immutable access contexts to application workflows. Intake does not import Identity models or services. `WithAuthorizedIdentity` locks the current user and persisted identity session, revalidates the B2 session/MFA/authentication generation, reads current permissions, and retains both row locks until its outer transaction commits. Account disablement, credential changes and role mutations take the same user lock; logout cannot delete the active session until the workflow finishes. The wrapper makes at most two attempts on a database deadlock. Its callback must contain retry-safe database work; any external effects must be represented by durable intent.

The B3 migration adds only these explicit permission grants. Existing B2 permissions and grants remain unchanged.

| Permission | Super Admin | Administrator | Project Manager | Business Analyst | Reviewer | Sales | Support | Customer |
|---|---|---|---|---|---|---|---|---|
| taxonomy.manage | Yes | Yes | — | — | — | — | — | — |
| intake.read | Yes | — | Yes | Yes | Yes | — | — | — |
| intake.read_all | Yes | — | — | — | — | — | — | — |
| intake.assign | Yes | — | Yes | — | — | — | — | — |
| intake.review | Yes | — | Yes | — | Yes | — | — | — |
| intake.information | Yes | — | Yes | Yes | Yes | — | — | — |
| intake.discovery | Yes | — | Yes | — | Yes | — | — | — |
| intake.reject | Yes | — | Yes | — | — | — | — | — |

These permissions do not bypass Intake policies. Staff reads cover non-draft requests assigned to that staff member. `intake.assign` additionally permits the unassigned queue so the first assignment can be made. `intake.read_all` is the explicit Super Admin permission for cross-assignment visibility. Project Managers may assign or reassign their own and unassigned requests; other assignees remain private. Staff detail, revision, information and history reads require audit. These reads hold a shared lock on the scoped request through the child queries and audit commit, so concurrent reassignment cannot invalidate the access decision during the read. List summaries use a scoped database snapshot. State, ownership, assignment and optimistic-concurrency checks remain Intake responsibilities.

Amendments are allowed only while Submitted, Under Review or Information Required. Information requests originate only from Under Review, and acknowledgement returns to that stored phase after a customer response. B0 also permits an assigned Project Manager, or Super Admin with explicit authority, to reject during Information Required with a reason. Rejection and withdrawal close outstanding information while preserving its question, response and resolution history.

`AuthorizedStaffReader::forIntakeAssignment()` requires an active transaction, locks the candidate user, and returns a context only for enabled staff with `intake.read` and at least one actionable intake permission. Customer, unknown, disabled and ineligible staff IDs all return null. Staff eligibility is rechecked during each assignment; assigning a request never grants additional permissions. A candidate's later role change can make them ineligible for subsequent actions.

`CustomerContactReader::currentForIdentity()` accepts the trusted authenticated actor ID and returns only the immutable contact DTO needed for submission. It resolves Identity details through `IdentityReader::contact()`, returns null for missing, disabled or staff identities, and never exposes a model or credential. With `lock=true`, an active transaction is mandatory and the user is locked before the customer profile. Intake can then preserve the current name, verified email and E.164 phone as the submitted revision snapshot. The returned DTO remains unchanged when future profile edits occur.

Reciprocal assignment can take actor and candidate user locks in opposite order. PostgreSQL deadlock detection and the wrapper's bounded whole-transaction retry handle this without allowing a stale authorization commit. Exhausted contention fails safely; it is not a permission bypass.
