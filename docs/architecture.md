# Reactive architecture and workflow

Core owns HTTP/session/auth/database/template services. Reactive owns component
registration, state validation, signed context, the update endpoint, and browser
lifecycle. Admin will own component schemas and visual styling above Reactive.
Neither Core nor the skeleton requires Reactive or Admin.

Initial render: registered alias → initialize trusted state → authorize → mount →
query state → authorize → render body and signed wrapper.

Update: method/size/CSRF → verify signed session/context and query metadata → refresh authentication →
registered alias → restore snapshot → authorize → validate/apply writable changes →
authorize → component validation → allowlisted action permission/execution →
authorize → HTML, response-only refresh targets, and fresh signed wrapper.

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

The examples include a ProductTable with an edit modal and a dependent LocationForm. The form uses
the authenticated owner identity, reloads lookups and saves inside a transaction,
and keeps incomplete drafts separate from required-on-save validation. No form
schema or nested state API is introduced yet. Runtime contracts are proven with
regressions for tampering, typed/locked state, action permissions, revoked access,
expired sessions, multiple tables, slow responses, focus, strict CSP, and RTL.

The dialog adapter uses native dialog modality, preserves its open state through
morphing, wraps Tab focus, and closes locally before an explicit server discard
request. Save actions own transactions and optimistic record/version checks.
Successful mutation effects refresh other independent instances through their own
normal authorized endpoints; effects never become persistent snapshot state.
Server-side ownership/scope and version checks remain necessary across browsers.

Next milestones:

1. Resolve the outstanding Admin correctness findings, then integrate one Admin
   table and one Admin form; keep Core/Admin optionality tests.
2. Expand shared Actions, Forms, Tables and notification contracts from those integrations.
3. Evaluate typed nested form state, nested components, temporary uploads, durable component state, richer
   response effects, and navigation after the initial contracts are proven.

Do not expose arbitrary PHP methods or object hydration, or add uploads/navigation
to the initial milestone. Any extension to Core must be a general capability with
regressions proving existing MVC behavior remains compatible.
