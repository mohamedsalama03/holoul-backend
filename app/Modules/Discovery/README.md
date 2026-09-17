# Discovery

Owns discovery records, revisions, requirements and explicit staff sign-off.
Reads locked Intake facts through `ProjectIntake/Contracts/CommercialContext`.
Completed content and sign-offs are immutable; changes start a new revision.
The outer Commercial workflow owns the request lock and version update.
See [B5 implementation](../../../docs/B5-IMPLEMENTATION.md).
