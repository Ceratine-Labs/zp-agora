# Email templates

`agora.EmailTemplate` stores the current content under `(BranchId, Code)`. Branch 2
(the configured group entity) is the default; an active row for the explicit
business branch wins. An inactive override falls back to the active group row.
With neither active, rendering refuses the mail. Every save records a full,
immutable `EmailTemplateVersion` snapshot; a stale submitted `Version` is refused.

A module adds `Email/VariableRegistry.php` implementing
`Modules\Email\Contracts\VariableRegistry`. Each `TemplateDefinition` owns a
`{module}.{event}` code, initial subject/HTML/text, allowed variable names, and
fictional sample values. URL variables declare `type => url`. Its own
`Database/Seeders/EmailTemplateSeeder.php` calls `TemplateService::seed()` for
those definitions. Seeders insert missing group defaults and never overwrite
edits. Follow the normal seed ledger: later default changes need a new seeder.

Content supports only `{{variable.name}}`, without expressions or conditions.
Values are escaped in HTML; links accept a complete URL variable or an HTTP/HTTPS
literal. Simple paragraphs, headings, tables, lists and links are supported.
Comments, declarations, images, CSS, scripts and other attributes are refused.
The editor previews only registry samples in a sandboxed iframe and a response
with `default-src 'none'` and `sandbox`; preview never sends mail.

Setup → Email templates uses `email.templates.view` and `email.templates.edit`.
The seeder registers these without copying grants to roles or users. Existing
administrator wildcard conventions apply. Group edits also require unrestricted
head-office scope; other editors may save only their available branch scopes.
Deactivation is the reversible remove action, retaining all versions.

The existing password-reset, staff-short and debtor mail classes preserve their
constructors, delivery callers and attachments. Cash and Debtors payloads carry
an explicit `branchId`; an older payload without it uses the group default,
never an ambient browser branch. Debtor test warnings remain outside editable
content and `debtors.send_mode` is unchanged. There is no new queue or transport.

Install the Email migration and run the module permission/menu seeders plus
Core, Cash and Debtors `EmailTemplateSeeder` classes through `seed:master` before
using these mail renderers. Production deployment and sends require their own
authorization; the development tests use loopback connections and fake mail.
