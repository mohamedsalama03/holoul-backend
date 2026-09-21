# B7 baseline

B6 approved; B7 AI Assistance and Notifications is the only authorized batch.

- Baseline HEAD: `bf738302657f8da1bca911058186a24e87b7ea37`
- Baseline parent: `cbcde0a583fd230ccf11e6817cb56d82f56428a5`
- Branch: `main`; initial working tree clean.
- Existing migrations: 24, preserved byte-for-byte for the exact upgrade gate.
- Existing permission catalog: 42; routes: 139.
- Inherited PHP gate: 663 tests / 33,610 assertions (654 feature/unit, 9 architecture).

AI supplier/privacy/cost approval remains outstanding under B0 ADR-07. B7 uses a deterministic local sandbox adapter and keeps external customer-data processing disabled. No external paid provider is invoked during verification. B8 is not authorized.
