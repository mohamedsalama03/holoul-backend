# PublicPortfolio and Contact — candidate changelog

Version: `1.4.0-public-services-candidate` (OpenAPI 3.1.1). Local integration candidate; no production certification or frontend cutover.

Previous SHA-256: `fafbc8b62976788dae0cc98492add8bed014228ad42844d2c7498ca57d1a7f4e`
New SHA-256: `7739bd957665b285c3baea8a4883aa987d6d11dee3fb43bcc5ef1ff9fb0c8051`

182 existing operation objects and every pre-existing component object are unchanged. The contract adds 20 operations, 29 schemas and one independent UUID idempotency parameter. Total: 202 operations, 250 schemas. No change to the frontend contract copies.

| Method | Path | operationId | Permission |
|---|---|---|---|
| GET | `/api/v1/admin/contact-messages` | `adminContactList` | contact.read |
| GET | `/api/v1/admin/contact-messages/{message}` | `adminContactDetail` | contact.read |
| PATCH | `/api/v1/admin/contact-messages/{message}` | `adminContactUpdate` | contact.manage |
| DELETE | `/api/v1/admin/contact-messages/{message}` | `adminContactRedact` | contact.redact |
| GET | `/api/v1/admin/portfolio/projects` | `adminPortfolioList` | portfolio.read |
| POST | `/api/v1/admin/portfolio/projects` | `adminPortfolioCreate` | portfolio.manage |
| GET | `/api/v1/admin/portfolio/projects/{project}` | `adminPortfolioDetail` | portfolio.read |
| PATCH | `/api/v1/admin/portfolio/projects/{project}` | `adminPortfolioUpdate` | portfolio.manage |
| POST | `/api/v1/admin/portfolio/projects/{project}/images` | `adminPortfolioReserveImage` | portfolio.manage |
| GET | `/api/v1/admin/portfolio/projects/{project}/images/{image}` | `adminPortfolioImageStatus` | portfolio.read |
| DELETE | `/api/v1/admin/portfolio/projects/{project}/images/{image}` | `adminPortfolioRemoveImage` | portfolio.manage |
| PUT | `/api/v1/admin/portfolio/projects/{project}/images/{image}/content` | `adminPortfolioUploadImage` | portfolio.manage |
| POST | `/api/v1/admin/portfolio/projects/{project}/publications` | `adminPortfolioPublish` | portfolio.publish |
| POST | `/api/v1/admin/portfolio/projects/{project}/unpublications` | `adminPortfolioUnpublish` | portfolio.publish |
| GET | `/api/v1/admin/public-content/capabilities` | `adminPublicContentCapabilities` | See persona/session requirements |
| POST | `/api/v1/public/contact-messages` | `publicContactCreate` | See persona/session requirements |
| GET | `/api/v1/public/portfolio/categories` | `publicPortfolioCategories` | See persona/session requirements |
| GET | `/api/v1/public/portfolio/images/{image}/{variant}` | `publicPortfolioImage` | See persona/session requirements |
| GET | `/api/v1/public/portfolio/projects` | `publicPortfolioList` | See persona/session requirements |
| GET | `/api/v1/public/portfolio/projects/{project}` | `publicPortfolioDetail` | See persona/session requirements |

New schemas:

- `AdminContactMessage`
- `AdminContactPage`
- `AdminContactResponse`
- `AdminContactSummary`
- `AdminContactUpdateInput`
- `AdminPortfolioCard`
- `AdminPortfolioCreateInput`
- `AdminPortfolioImageInput`
- `AdminPortfolioImageResponse`
- `AdminPortfolioImageStatus`
- `AdminPortfolioPage`
- `AdminPortfolioProject`
- `AdminPortfolioPublishInput`
- `AdminPortfolioResponse`
- `AdminPortfolioUpdateInput`
- `AdminPublicContentCapabilities`
- `AdminPublicContentCapabilitiesResponse`
- `PublicContactInput`
- `PublicContactReceipt`
- `PublicContactReceiptResponse`
- `PublicPortfolioCard`
- `PublicPortfolioCategoriesResponse`
- `PublicPortfolioCategory`
- `PublicPortfolioDetail`
- `PublicPortfolioDetailResponse`
- `PublicPortfolioImage`
- `PublicPortfolioImageVariant`
- `PublicPortfolioPage`
- `PublicServicesEmptyInput`

New parameter: `PublicServicesIdempotencyKey`. No existing parameter, response, schema, or security scheme changed.

Six grants are added only to Super Admin and Administrator: portfolio.read/manage/publish and contact.read/manage/redact. Existing staff invitation and role-grant limits are unchanged. New self-capabilities are independent of the old identity capabilities schema.

Migrations 32–34 are additive. Existing receipts, immutable intake revisions, documents, staff history and old permissions are preserved. Unused empty new schemas can be rolled back; retained new history requires forward recovery.

Operational changes: isolated static-image worker; private immutable object namespace; Contact notification routing to notifications; terminal delivery/processing reconciliation; explicit no-cookie public reads and read-only Contact CSRF session handling; narrow successful-public-GET edge cache exception.

Catalogue: approved 12 parents/49 children, repeatable add-only import and read-only B8 exclusion inventory. B8 deactivation and legacy website cutover require the separately reviewed cutover step.

Retention: 180 days remains undecided; automatic redaction is disabled. Manual authorized redaction preserves receipts. Recipient: info@holoul.ly. Production email/dashboard link enablement remains gated.
