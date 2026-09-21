# AI

B7 implements provider-independent AI runs, immutable source snapshots and validated suggestions. The module owns AI persistence, PostgreSQL cost reservations and concurrency limits, provider attempt history, durable generation, cancellation and explicit suggestion decisions. Application services supply current authorization/source verification and apply accepted suggestions through the owning business module.

The configured `sandbox` provider is deterministic and local. AI is disabled by default (`HOLOUL_AI_ENABLED=false`). No real provider or paid failover is enabled; external processing requires the outstanding B0 privacy, model and budget approval.

Workers resolve source/provider adapters lazily, perform external work outside database transactions, and reauthorize before dispatch and before storing output. Unknown paid outcomes retain their reservation and never trigger blind retries. The scheduled `ai:reconcile-runs` command terminalizes exhausted work and its outstanding attempt conservatively.

See [B7 implementation](../../../docs/B7-IMPLEMENTATION.md) for purpose schemas, source boundaries, reliability, limits and integration behavior, and [B0 architecture](../../../docs/B0-ARCHITECTURE.md) for approved module ownership.
