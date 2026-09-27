# HOLOUL frontend endpoint matrix

Generated from the reviewed OpenAPI source. Permissions never override ownership, assignment, MFA, recent authentication, visibility or state checks. Empty permission cells mean no named permission, not public access. Health/operational routes are excluded.

| Frontend feature | Endpoint | Method | Persona | Permission |
|---|---|---|---|---|
| API version | `/api/v1` | GET | public | — |
| Investigate audit events | `/api/v1/admin/audit-events` | GET | staff | audit.investigate |
| List Categories | `/api/v1/admin/categories` | GET | staff | taxonomy.manage |
| Create Category | `/api/v1/admin/categories` | POST | staff | taxonomy.manage |
| Get Category | `/api/v1/admin/categories/{category}` | GET | staff | taxonomy.manage |
| Update Category | `/api/v1/admin/categories/{category}` | PATCH | staff | taxonomy.manage |
| List Subcategories | `/api/v1/admin/categories/{category}/subcategories` | GET | staff | taxonomy.manage |
| Create Subcategory | `/api/v1/admin/categories/{category}/subcategories` | POST | staff | taxonomy.manage |
| Customer directory | `/api/v1/admin/customers` | GET | staff | customers.directory.read |
| Customer detail | `/api/v1/admin/customers/{customer}` | GET | staff | customers.directory.read |
| Customer request summaries | `/api/v1/admin/customers/{customer}/project-requests` | GET | staff | customers.directory.read, intake.read |
| Customer Project summaries | `/api/v1/admin/customers/{customer}/projects` | GET | staff | customers.directory.read, projects.read |
| Inspect notification deliveries | `/api/v1/admin/notification-deliveries` | GET | staff | notifications.delivery.read |
| Inspect delivery attempts | `/api/v1/admin/notification-deliveries/{delivery}` | GET | staff | notifications.delivery.read |
| Replay failed notification delivery | `/api/v1/admin/notification-deliveries/{delivery}/replays` | POST | staff | notifications.delivery.replay |
| Staff List project request | `/api/v1/admin/project-requests` | GET | staff | intake.read |
| Staff Reference project request | `/api/v1/admin/project-requests/by-reference/{reference}` | GET | staff | intake.read |
| Staff Detail project request | `/api/v1/admin/project-requests/{projectRequest}` | GET | staff | intake.read |
| Staff Assign project request | `/api/v1/admin/project-requests/{projectRequest}/assignments` | POST | staff | intake.read, intake.assign |
| Staff Assignments project request | `/api/v1/admin/project-requests/{projectRequest}/assignments` | GET | staff | intake.read |
| Convert approved request to project | `/api/v1/admin/project-requests/{projectRequest}/conversions` | POST | staff | intake.read, projects.convert, projects.read, projects.transition, projects.team.manage |
| List discovery revisions | `/api/v1/admin/project-requests/{projectRequest}/discovery` | GET | staff | intake.read, discovery.read |
| Create discovery revision | `/api/v1/admin/project-requests/{projectRequest}/discovery` | POST | staff | intake.read, discovery.manage |
| Staff Discovery project request | `/api/v1/admin/project-requests/{projectRequest}/discovery-handoffs` | POST | staff | intake.read, intake.discovery |
| Read discovery revision | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}` | GET | staff | intake.read, discovery.read |
| Replace discovery content | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}` | PUT | staff | intake.read, discovery.manage, discovery.read |
| Complete and sign off discovery | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/completions` | POST | staff | intake.read, discovery.manage, discovery.read, discovery.complete |
| Replace discovery requirements | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/requirements` | PUT | staff | intake.read, discovery.manage, discovery.read |
| Start discovery | `/api/v1/admin/project-requests/{projectRequest}/discovery/{revision}/starts` | POST | staff | intake.read, discovery.manage, discovery.read |
| Read request document metadata | `/api/v1/admin/project-requests/{projectRequest}/documents/{document}` | GET | staff | intake.read, documents.read |
| Download request document | `/api/v1/admin/project-requests/{projectRequest}/documents/{document}/download` | GET | staff | intake.read, documents.download |
| Request assignee picker | `/api/v1/admin/project-requests/{projectRequest}/eligible-assignees` | GET | staff | intake.read, intake.assign |
| Staff History project request | `/api/v1/admin/project-requests/{projectRequest}/history` | GET | staff | intake.read |
| Staff Information project request | `/api/v1/admin/project-requests/{projectRequest}/information-requests` | GET | staff | intake.read |
| Staff Ask project request | `/api/v1/admin/project-requests/{projectRequest}/information-requests` | POST | staff | intake.read, intake.information |
| Staff Acknowledge project request | `/api/v1/admin/project-requests/{projectRequest}/information-requests/{information}/acknowledgements` | POST | staff | intake.read, intake.information |
| Staff proposals | `/api/v1/admin/project-requests/{projectRequest}/proposals` | GET | staff | intake.read, proposals.read |
| Create proposal draft | `/api/v1/admin/project-requests/{projectRequest}/proposals` | POST | staff | intake.read, proposals.create |
| Staff proposal detail | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}` | GET | staff | intake.read, proposals.read |
| Replace unissued proposal terms | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}` | PUT | staff | intake.read, proposals.read, proposals.edit |
| Approve proposal internally | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/approvals` | POST | staff | intake.read, proposals.read, proposals.approve |
| Attach available proposal document | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents` | POST | staff | intake.read, proposals.read, proposals.edit, documents.read |
| Detach proposal document | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` | DELETE | staff | intake.read, proposals.read, proposals.edit, documents.read |
| Read proposal document | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` | GET | staff | intake.read, proposals.read, documents.read |
| Download proposal document | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}/download` | GET | staff | intake.read, proposals.read, documents.download |
| Issue approved proposal | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/issuances` | POST | staff | intake.read, proposals.read, proposals.issue |
| Supersede issued proposal | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/supersessions` | POST | staff | intake.read, proposals.read, proposals.issue |
| Withdraw issued proposal | `/api/v1/admin/project-requests/{projectRequest}/proposals/{proposal}/withdrawals` | POST | staff | intake.read, proposals.read, proposals.withdraw |
| Staff Reject project request | `/api/v1/admin/project-requests/{projectRequest}/rejections` | POST | staff | intake.read, intake.reject |
| Staff Review project request | `/api/v1/admin/project-requests/{projectRequest}/reviews` | POST | staff | intake.read, intake.review |
| Staff Revisions project request | `/api/v1/admin/project-requests/{projectRequest}/revisions` | GET | staff | intake.read |
| Staff Revision project request | `/api/v1/admin/project-requests/{projectRequest}/revisions/{revision}` | GET | staff | intake.read |
| Staff project list | `/api/v1/admin/projects` | GET | staff | projects.read |
| Staff project detail | `/api/v1/admin/projects/{project}` | GET | staff | projects.read |
| Project activity | `/api/v1/admin/projects/{project}/activity` | GET | staff | projects.read |
| Advance project phase | `/api/v1/admin/projects/{project}/advances` | POST | staff | projects.read, projects.transition |
| Cancel project | `/api/v1/admin/projects/{project}/cancellations` | POST | staff | projects.read, projects.transition |
| List project documents | `/api/v1/admin/projects/{project}/documents` | GET | staff | projects.read, projects.documents.read |
| Reserve project document | `/api/v1/admin/projects/{project}/documents` | POST | staff | projects.read, projects.documents.upload |
| Read project document | `/api/v1/admin/projects/{project}/documents/{document}` | GET | staff | projects.read, projects.documents.read |
| Cancel pending project upload | `/api/v1/admin/projects/{project}/documents/{document}` | DELETE | staff | projects.read, projects.documents.read, projects.documents.upload |
| Upload and finalize project document | `/api/v1/admin/projects/{project}/documents/{document}/content` | PUT | staff | projects.read, projects.documents.upload, projects.documents.read |
| Download project document | `/api/v1/admin/projects/{project}/documents/{document}/download` | GET | staff | projects.read, projects.documents.read |
| Retry project document scan | `/api/v1/admin/projects/{project}/documents/{document}/scan-retries` | POST | staff | projects.read, projects.documents.read, projects.documents.upload |
| Project team picker | `/api/v1/admin/projects/{project}/eligible-staff` | GET | staff | projects.read, projects.team.manage |
| Project phase evidence | `/api/v1/admin/projects/{project}/evidence` | GET | staff | projects.read |
| Record phase evidence | `/api/v1/admin/projects/{project}/evidence` | POST | staff | projects.read, projects.manage |
| Record failed delivery phase | `/api/v1/admin/projects/{project}/failures` | POST | staff | projects.read, projects.transition |
| Hold project | `/api/v1/admin/projects/{project}/holds` | POST | staff | projects.read, projects.transition |
| Staff milestones | `/api/v1/admin/projects/{project}/milestones` | GET | staff | projects.read |
| Create milestone | `/api/v1/admin/projects/{project}/milestones` | POST | staff | projects.read, projects.milestones.manage |
| Replace milestone editable fields | `/api/v1/admin/projects/{project}/milestones/{milestone}` | PATCH | staff | projects.read, projects.milestones.manage |
| Milestone completions | `/api/v1/admin/projects/{project}/milestones/{milestone}/completions` | POST | staff | projects.read, projects.milestones.manage |
| Milestone delays | `/api/v1/admin/projects/{project}/milestones/{milestone}/delays` | POST | staff | projects.read, projects.milestones.manage |
| Milestone starts | `/api/v1/admin/projects/{project}/milestones/{milestone}/starts` | POST | staff | projects.read, projects.milestones.manage |
| Resume project | `/api/v1/admin/projects/{project}/resumptions` | POST | staff | projects.read, projects.transition |
| Project team | `/api/v1/admin/projects/{project}/team-members` | GET | staff | projects.read |
| Add project team member | `/api/v1/admin/projects/{project}/team-members` | POST | staff | projects.read, projects.team.manage |
| Remove project team member | `/api/v1/admin/projects/{project}/team-members/{member}` | DELETE | staff | projects.read, projects.team.manage |
| Staff project updates | `/api/v1/admin/projects/{project}/updates` | GET | staff | projects.read |
| Publish customer project update | `/api/v1/admin/projects/{project}/updates` | POST | staff | projects.read, projects.updates.publish |
| Read customers statistics | `/api/v1/admin/reports/customers` | GET | staff | reporting.read |
| Read dashboard statistics | `/api/v1/admin/reports/dashboard` | GET | staff | reporting.read |
| Read projects statistics | `/api/v1/admin/reports/projects` | GET | staff | reporting.read |
| Read requests statistics | `/api/v1/admin/reports/requests` | GET | staff | reporting.read |
| Get Subcategory | `/api/v1/admin/subcategories/{subcategory}` | GET | staff | taxonomy.manage |
| Update Subcategory | `/api/v1/admin/subcategories/{subcategory}` | PATCH | staff | taxonomy.manage |
| List own AI runs | `/api/v1/ai-runs` | GET | customer, staff | ai.self.use, ai.use |
| Request optional AI assistance | `/api/v1/ai-runs` | POST | customer, staff | ai.self.use, ai.use |
| Poll own AI run | `/api/v1/ai-runs/{aiRun}` | GET | customer, staff | ai.self.use, ai.use |
| Apply AI suggestion explicitly | `/api/v1/ai-runs/{aiRun}/applications` | POST | customer, staff | ai.self.use, ai.use, ai.self.apply, ai.apply, discovery.manage |
| Cancel pending AI assistance | `/api/v1/ai-runs/{aiRun}/cancellations` | POST | customer, staff | ai.self.use, ai.use |
| Dismiss AI suggestion | `/api/v1/ai-runs/{aiRun}/dismissals` | POST | customer, staff | ai.self.use, ai.use |
| Request another verification email | `/api/v1/auth/email/resend` | POST | guest, customer, staff | — |
| Verify email from an expiring token | `/api/v1/auth/email/verify` | POST | guest, customer, staff | — |
| Sign in with email and password | `/api/v1/auth/login` | POST | guest, customer, staff | — |
| Sign out the current session | `/api/v1/auth/logout` | POST | customer, staff | — |
| Disable staff MFA and sign out | `/api/v1/auth/mfa` | DELETE | staff | — |
| Complete staff MFA challenge | `/api/v1/auth/mfa/challenge` | POST | staff | — |
| Begin staff MFA enrollment | `/api/v1/auth/mfa/enrollment` | POST | staff | — |
| Confirm staff MFA enrollment | `/api/v1/auth/mfa/enrollment/confirm` | POST | staff | — |
| Use a staff MFA recovery code | `/api/v1/auth/mfa/recovery` | POST | staff | — |
| Regenerate staff MFA recovery codes | `/api/v1/auth/mfa/recovery-codes` | POST | staff | — |
| Change password and revoke other sessions | `/api/v1/auth/password/change` | POST | customer, staff | — |
| Confirm a recent password | `/api/v1/auth/password/confirm` | POST | customer, staff | — |
| Request a password-reset email | `/api/v1/auth/password/forgot` | POST | guest, customer, staff | — |
| Reset a password with a one-use token | `/api/v1/auth/password/reset` | POST | guest, customer, staff | — |
| Request customer registration | `/api/v1/auth/register` | POST | guest, customer, staff | — |
| Team and invitations | `/api/v1/auth/staff-invitations/accept` | POST | guest | — |
| Team and invitations | `/api/v1/auth/staff-invitations/lookup` | POST | guest | — |
| List Active Categories | `/api/v1/categories` | GET | customer, staff | — |
| List Active Subcategories | `/api/v1/categories/{category}/subcategories` | GET | customer, staff | — |
| Find the current customer profile | `/api/v1/customers` | GET | customer | — |
| Read an owned customer profile | `/api/v1/customers/{customer}` | GET | customer | — |
| Update an owned customer profile | `/api/v1/customers/{customer}` | PATCH | customer | — |
| Customer Nested project request | `/api/v1/customers/{customer}/project-requests/{projectRequest}` | GET | customer | — |
| Create an ephemeral guest intake draft | `/api/v1/guest/project-requests` | POST | guest | — |
| Reserve the guest draft document | `/api/v1/guest/project-requests/{projectRequest}/documents` | POST | guest | — |
| Read document processing metadata before submission | `/api/v1/guest/project-requests/{projectRequest}/documents/{document}` | GET | guest | — |
| Upload guest document bytes | `/api/v1/guest/project-requests/{projectRequest}/documents/{document}/content` | PUT | guest | — |
| Submit a guest idea | `/api/v1/guest/project-requests/{projectRequest}/submissions` | POST | guest | — |
| Read an owned customer profile | `/api/v1/identities/{identity}/customers/{customer}` | GET | customer | — |
| Update an owned customer profile | `/api/v1/identities/{identity}/customers/{customer}` | PATCH | customer | — |
| Team and invitations | `/api/v1/identity/capabilities` | GET | customer, staff | — |
| Get the current identity | `/api/v1/identity/me` | GET | customer, staff | — |
| Update the current full name | `/api/v1/identity/me` | PATCH | customer, staff | — |
| List active sessions | `/api/v1/identity/sessions` | GET | customer, staff | — |
| Revoke other sessions | `/api/v1/identity/sessions/revoke-others` | POST | customer, staff | — |
| Team and invitations | `/api/v1/identity/staff` | GET | staff | identity.staff.read |
| Team and invitations | `/api/v1/identity/staff/invitations` | GET | staff | identity.staff.read |
| Team and invitations | `/api/v1/identity/staff/invitations` | POST | staff | identity.staff.read, identity.staff.manage |
| Team and invitations | `/api/v1/identity/staff/invitations/{invitation}/resends` | POST | staff | identity.staff.read, identity.staff.manage |
| Team and invitations | `/api/v1/identity/staff/invitations/{invitation}/revocations` | POST | staff | identity.staff.read, identity.staff.manage |
| Read a staff identity and assigned roles | `/api/v1/identity/staff/{user}` | GET | staff | identity.staff.read |
| Change staff roles or enabled status | `/api/v1/identity/staff/{user}/authorization` | PATCH | staff | identity.staff.read, identity.staff.manage |
| Team and invitations | `/api/v1/identity/staff/{user}/authorization` | GET | staff | identity.staff.read |
| Team and invitations | `/api/v1/identity/staff/{user}/authorization` | PUT | staff | identity.staff.read, identity.staff.manage |
| List Active Categories | `/api/v1/intake/categories` | GET | guest, customer, staff | — |
| List Active Subcategories | `/api/v1/intake/categories/{category}/subcategories` | GET | guest, customer, staff | — |
| List own notifications | `/api/v1/notifications` | GET | customer, staff | notifications.self.read |
| Read notification preference | `/api/v1/notifications/preferences` | GET | customer, staff | notifications.self.read |
| Change workflow email preference | `/api/v1/notifications/preferences` | PATCH | customer, staff | notifications.self.manage |
| Mark own inbox read | `/api/v1/notifications/read-all` | POST | customer, staff | notifications.self.manage |
| Poll unread notification count | `/api/v1/notifications/unread-count` | GET | customer, staff | notifications.self.read |
| Read own notification | `/api/v1/notifications/{notification}` | GET | customer, staff | notifications.self.read |
| Mark notification read | `/api/v1/notifications/{notification}/read` | POST | customer, staff | notifications.self.manage |
| Explicitly claim an eligible guest submission | `/api/v1/project-request-claims` | POST | customer | — |
| Customer List project request | `/api/v1/project-requests` | GET | customer | — |
| Customer Create project request | `/api/v1/project-requests` | POST | customer | — |
| Customer Reference project request | `/api/v1/project-requests/by-reference/{reference}` | GET | customer | — |
| Customer Detail project request | `/api/v1/project-requests/{projectRequest}` | GET | customer | — |
| Customer Amend project request | `/api/v1/project-requests/{projectRequest}/amendments` | POST | customer | — |
| Reserve request document | `/api/v1/project-requests/{projectRequest}/documents` | POST | customer | — |
| Read request document metadata | `/api/v1/project-requests/{projectRequest}/documents/{document}` | GET | customer | — |
| Remove draft document | `/api/v1/project-requests/{projectRequest}/documents/{document}` | DELETE | customer | — |
| Upload and finalize request document | `/api/v1/project-requests/{projectRequest}/documents/{document}/content` | PUT | customer | — |
| Download request document | `/api/v1/project-requests/{projectRequest}/documents/{document}/download` | GET | customer | — |
| Retry request document scan | `/api/v1/project-requests/{projectRequest}/documents/{document}/scan-retries` | POST | customer | — |
| Customer Update project request | `/api/v1/project-requests/{projectRequest}/draft` | PATCH | customer | — |
| Customer History project request | `/api/v1/project-requests/{projectRequest}/history` | GET | customer | — |
| Customer Information project request | `/api/v1/project-requests/{projectRequest}/information-requests` | GET | customer | — |
| Customer Response project request | `/api/v1/project-requests/{projectRequest}/information-requests/{information}/responses` | POST | customer | — |
| Customer proposals | `/api/v1/project-requests/{projectRequest}/proposals` | GET | customer | proposals.self.read |
| Customer proposal detail | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}` | GET | customer | proposals.self.read |
| Customer proposal acceptances | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/acceptances` | POST | customer | proposals.self.read, proposals.self.accept |
| Customer proposal declines | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/declines` | POST | customer | proposals.self.read, proposals.self.decline |
| Read proposal document | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}` | GET | customer | proposals.self.read |
| Download proposal document | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/documents/{document}/download` | GET | customer | proposals.self.read |
| Customer proposal rescissions | `/api/v1/project-requests/{projectRequest}/proposals/{proposal}/rescissions` | POST | customer | proposals.self.read, proposals.self.accept |
| Customer Revisions project request | `/api/v1/project-requests/{projectRequest}/revisions` | GET | customer | — |
| Customer Revision project request | `/api/v1/project-requests/{projectRequest}/revisions/{revision}` | GET | customer | — |
| Customer Submit project request | `/api/v1/project-requests/{projectRequest}/submissions` | POST | customer | — |
| Customer Withdraw project request | `/api/v1/project-requests/{projectRequest}/withdrawals` | POST | customer | — |
| Customer project list | `/api/v1/projects` | GET | customer | projects.self.read |
| Customer project detail | `/api/v1/projects/{project}` | GET | customer | projects.self.read |
| Confirm project deployment | `/api/v1/projects/{project}/completion-confirmations` | POST | customer | projects.self.read, projects.self.confirm |
| List project documents | `/api/v1/projects/{project}/documents` | GET | customer | projects.self.read, projects.self.documents.read |
| Read project document | `/api/v1/projects/{project}/documents/{document}` | GET | customer | projects.self.read, projects.self.documents.read |
| Download project document | `/api/v1/projects/{project}/documents/{document}/download` | GET | customer | projects.self.read, projects.self.documents.read |
| Customer milestones | `/api/v1/projects/{project}/milestones` | GET | customer | projects.self.read |
| Customer project updates | `/api/v1/projects/{project}/updates` | GET | customer | projects.self.read |
| Initialize the SPA session and CSRF cookie | `/sanctum/csrf-cookie` | GET | guest, customer, staff | — |
