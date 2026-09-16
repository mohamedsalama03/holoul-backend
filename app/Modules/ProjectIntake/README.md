# ProjectIntake

B3 owns customer request drafts, immutable submission revisions and contact snapshots, references, assignments, clarification records, state history and passive notification intent. Ownership and workflow decisions are protected by scoped queries, Policies, ETag preconditions and PostgreSQL locks/constraints.

The outer application workflow derives the trusted actor from B2 session authentication and holds current identity authority through commit. Intake consumes Customers and Categories contracts, without importing Identity models or writing their tables. Money remains a technical exact-integer facility. Every mutation records append-only audit without private descriptions or contact content.

Discovery is only a persisted intake state boundary. No Discovery records, documents, AI, proposals or projects are implemented. See [the B3 contract](../../../docs/B3-IMPLEMENTATION.md).
