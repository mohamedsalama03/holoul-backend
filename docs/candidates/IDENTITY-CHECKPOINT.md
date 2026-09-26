# Approved identity candidate checkpoint

Parent pre-G1 candidate: `55b3b79eb205721ac74c2481a92aeff1ae06db25`. Original main: `96445baded70ddd2e4d8b8617793c78a4a3e1816`.

This candidate preserves the approved identity session rotation remediation and permanent race/concurrency tests, independent from the unfinished G1 extension. It is NOT a Production Ready release. B8 performance certification remains pending the target VPS.

The pre-G1 parent preserves accumulated B8/P1/P2/P3/F1-E1 source and historical reports. `HISTORICAL-EVIDENCE-SHA256.json` inventories local ignored evidence, including failed attempts; the raw files remain in place, and may contain private test material, so are not committed. No history was squashed or discarded.

The approved implementation matches `artifacts/identity-session-race/runtime-context.json`; the identity report records both failures and accepted checks. The contract stays at 164 operations, SHA-256 `1422e14ef0c18204c420080c33f0b597f39cce5093acf358aa15aa1ee980499b` and 29 migrations.

Resumed G1 must use this commit as its parent so the G1 delta can be reviewed independently. These refs are local candidate checkpoints; main and its dirty working tree are not reset or staged.
