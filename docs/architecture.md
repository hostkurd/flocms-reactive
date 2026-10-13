# Reactive 0.1 architecture and workflow

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

Transport is htmx. The adapter deliberately disables htmx's DOM swap and hands
component updates to Alpine Morph. State binding belongs to the adapter; Alpine
remains available for local interactions. One runtime owns each lifecycle stage.
Per-component serialized requests prevent concurrent writes; sequence counters
prevent display of obsolete responses. URL merging updates only that component's
query keys. Browser history restoration currently uses a full reload.

The initial deliverable includes the full executable ProductTable example and
regressions for tampering, typed/locked state, action permissions, revoked access,
expired sessions, multiple tables, slow responses, focus, strict CSP, and RTL.

Next milestones:

1. Dependent country/city form: retain invalid input safely, typed nested form state,
   validation ergonomics, and accessible error association.
2. Edit modal: record reload/authorization, transaction handling, focus restoration,
   and action results/notifications.
3. Integrate one Admin table and one Admin form; keep Core/Admin optionality tests.
4. Evaluate nested components, temporary uploads, durable component state, richer
   response effects, and navigation after the initial contracts are proven.

Do not expose arbitrary PHP methods or object hydration, or add uploads/navigation
to the initial milestone. Any extension to Core must be a general capability with
regressions proving existing MVC behavior remains compatible.
