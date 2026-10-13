# Reactive architecture and workflow

Core owns HTTP/session/auth/database/template services. Reactive owns component
registration, state validation, signed context, the update endpoint, and browser
lifecycle. Admin will own component schemas and visual styling above Reactive.
Neither Core nor the skeleton requires Reactive or Admin.

Initial render: registered alias → initialize trusted state → authorize → mount →
query state → authorize → render body and signed wrapper.

Update: method/size/CSRF → verify signed session/context → refresh authentication →
registered alias → restore snapshot → authorize → validate/apply writable changes →
authorize → component validation → allowlisted action permission/execution →
authorize → HTML and fresh signed wrapper.

Accepted state is applied as a complete batch before changed-property hooks run
in declaration order. Save actions may throw Core ValidationException to return
field errors with 422; database transactions belong to the action/application.
Initial query-state restoration omits update hooks so bookmarked search/page
combinations survive reloads.

Transport is htmx. The adapter deliberately disables htmx's DOM swap and hands
component updates to Alpine Morph. State binding belongs to the adapter; Alpine
remains available for local interactions. One runtime owns each lifecycle stage.
Per-component serialized requests prevent concurrent writes; sequence counters
prevent display of obsolete responses. URL merging updates only that component's
query keys. Browser history restoration currently uses a full reload.

The examples include a ProductTable and a dependent LocationForm. The form uses
the authenticated owner identity, reloads lookups and saves inside a transaction,
and keeps incomplete drafts separate from required-on-save validation. No form
schema or nested state API is introduced yet. Runtime contracts are proven with
regressions for tampering, typed/locked state, action permissions, revoked access,
expired sessions, multiple tables, slow responses, focus, strict CSP, and RTL.

Next milestones:

1. Edit modal: record reload/authorization, transaction handling, focus restoration,
   and action results/notifications.
2. Integrate one Admin table and one Admin form; keep Core/Admin optionality tests.
3. Evaluate typed nested form state, nested components, temporary uploads, durable component state, richer
   response effects, and navigation after the initial contracts are proven.

Do not expose arbitrary PHP methods or object hydration, or add uploads/navigation
to the initial milestone. Any extension to Core must be a general capability with
regressions proving existing MVC behavior remains compatible.
