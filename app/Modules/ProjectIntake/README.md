# ProjectIntake

B3 owns customer request drafts, immutable submission revisions and contact snapshots, references, assignments, clarification records, state history and passive notification intent. Ownership and workflow decisions are protected by scoped queries, Policies, ETag preconditions and PostgreSQL locks/constraints.

The outer application workflow derives the trusted actor from B2 session authentication and holds current identity authority through commit. Intake consumes Customers and Categories contracts, without importing Identity models or writing their tables. Money remains a technical exact-integer facility. Every mutation records append-only audit without private descriptions or contact content.

B5 builds Discovery records after the approved handoff and composes explicit
commercial request transitions through `CommercialIntake`. Intake still owns
request writes. Information requests may now return to their persisted Discovery
origin; authorized assignment continues through Proposal/Approved. B4 owns private
documents and typed intake attachments. Projects and AI remain unimplemented.
See [B3](../../../docs/B3-IMPLEMENTATION.md) and [B5](../../../docs/B5-IMPLEMENTATION.md).
