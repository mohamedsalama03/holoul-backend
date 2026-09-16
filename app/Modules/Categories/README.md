# Categories

B3 owns category/subcategory identity, labels, active state, ordering and versions.
The module depends only on Audit. The outer authenticated workflow constructs
`TaxonomyActor`; clients never supply its permission flag or actor identifier.

Slugs are explicit, stable machine keys for integrations and fixtures. A
subcategory slug is unique within its immutable parent. Rename labels freely
with a current version; deactivate records rather than deleting them. Every
database update advances `lock_version` exactly once.

`TaxonomyReader` exposes bounded active-only lists and selected identifiers plus
labels. Submission calls `selection(..., lock: true)` inside its transaction;
shared locks on category then subcategory preserve availability and labels until
the revision commits. Management uses the same parent-before-child lock order.
Submitted revisions store their own label snapshots.

Lists use display order with UUID tie-breaking and opaque keyset cursors, default
25/maximum 100. No cache is authoritative for selection or authorization.
