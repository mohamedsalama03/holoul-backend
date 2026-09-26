# Reporting

B8 implements bounded operational dashboards and reports through aggregate-only owner read contracts. Reporting imports no other module's models or query implementations and performs no business writes. Current verified staff authority is required by the Application boundary; the explicit `reporting.read` grant permits platform operational aggregates without exposing customer records or private content.

Owner contracts provide Intake, Proposals, Projects and Customers counts, trends, denominators and timings. Monetary sums remain exact minor-unit strings grouped by currency, with customer estimated budgets separate from accepted proposal values. No revenue or currency conversion is inferred.

See [B8 reporting and audit semantics](../../../docs/B8-REPORTING.md) and the [approved B0 architecture](../../../docs/B0-ARCHITECTURE.md).
