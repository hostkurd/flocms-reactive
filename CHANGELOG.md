# Changelog

## 0.5.0 - 2026-10-16

No breaking changes: existing components behave as in 0.4.

- **Binding modes.** `data-rx-model="lazy"` (send on change), `"blur"` (send when
  an edited control loses focus), `"defer"` (send with the next request) and
  `"debounce:600"` (live with another delay). The default stays live after 300 ms.
  Values typed while another update runs are kept as typed when it returns.
- **Validation where it belongs.** `validatesOnUpdate(): false` runs `validate()` only
  before actions; `#[Action(validate: false)]` runs Cancel/Discard/Reset actions even
  while the form has errors. Default: `validate()` still runs on every update.
- **Long sessions stay alive.** The browser re-signs snapshots in the background once
  half the TTL has passed (`$renew`, no rendering), so a form left open for more than
  30 minutes no longer fails with "expired". Login and CSRF expiry are unchanged.
- **Translated runtime messages.** Session, permission, expiry and failure messages
  come from PHP in the page language (`data-rx-messages`): English, Arabic and
  Kurdish included, overridable per site with `reactive.<name>` lang keys.
- **No lost clicks.** Buttons lock only while an action runs. A click during a plain
  update (for example leaving a lazy field by clicking Save) is queued and sent once
  after it, with the latest values. A second click while it waits is ignored.
- Contact-form example for the binding modes; new PHP and browser tests.
- Release-version assets `reactive-v0.5.0.js` and `reactive-v0.5.0.css`.

## 0.4.1 - 2026-10-16

- Documentation only. The 0.4.0 entry now carries its release date, the README
  status and asset examples match 0.4, and the larger-drafts section moved from
  after the license into "State, lifecycle, and validation".
- No PHP or browser changes. Browser assets stay `reactive-v0.4.0.js` and
  `reactive-v0.4.0.css`; deployed assets do not need replacing.

## 0.4.0 - 2026-10-15

- Opt-in `#[State(maxBytes: ...)]` for non-query scalar strings, up to 65,536 UTF-8 bytes. Existing properties retain their 8,192-byte default; limits apply to input, snapshot restoration and server output.
- Larger request envelopes (up to 1 MiB) only for opted-in schemas. Other component requests retain their 64 KiB bound. Global signed snapshot JSON is bounded to 256 KiB.
- Query state cannot opt into larger values; explicit state, permissions, session-bound signatures, CSRF and action allowlists remain unchanged. Nested/object state and uploads are not introduced.
- Large Unicode/escaping, aggregate-limit and transport regressions plus a runnable bounded-draft example.
- Release-version assets `reactive-v0.4.0.js` and `reactive-v0.4.0.css`. No Core or Admin dependency is introduced.

## 0.3.0 - 2026-10-13

- Native dialog adapter with server-controlled opening, background inertness,
  keyboard focus wrapping, Escape/discard handling, opener/fallback focus restoration,
  validation focus, mutation locking, and cleanup when a component is removed.
- Product edit modal with independent dialog IDs, localized/accessible errors,
  transactional saves, current ownership/featured-scope checks, sealed record/version
  context, integer-cent storage and optimistic concurrency protection.
- Response-only component refresh effects and the `FloReactive.refresh(key)` browser
  API. Product saves refresh both tables without resetting their search or pagination.
- Request query metadata is validated before exposed actions can write records.
- New PHP/browser regressions and an edit-modal walkthrough. Compiled assets use
  `reactive-v0.3.0.js` and `reactive-v0.3.0.css`; Core/Admin need no changes.

## 0.2.0 - 2026-10-13

- Compiled assets use the package's full release version, for example
  `reactive-v0.2.0.js` and `reactive-v0.2.0.css`, instead of content hashes.
  New released asset contents require a version bump to invalidate browser caches.
- Dependent country/city form with incomplete drafts, localized and accessible
  field errors, empty-city state, transactional saving, reload persistence,
  owner-scoped records, and independently enforced view/save permissions.
- Actions can throw Core ValidationException to render field errors with HTTP
  422 and renew the signed snapshot. Other failures still propagate.
- Writable update batches are applied before hooks run in declaration order.
  Dependent resets no longer depend on client field order. Hooks now see all
  accepted changes rather than partially applied state.
- Initial URL state is restored without live-update hooks, preserving search
  and pagination together when a bookmarked page is reloaded.
- Form submission reads current bound controls, including autofilled values;
  related resets also update controls. Submit buttons preserve disabled state
  while updates run, and running saves are not replayed by duplicate submission.
- CI and example installation now use released Core 2.3.2 from Packagist.
- PHP and browser regressions for dependent inputs, forged relationships,
  validation recovery, persistence, permissions, rollback, slow responses and RTL.

## 0.1.0 - 2026-10-13

- Explicit component registration, scalar State attributes, locked state, and
  zero-argument Action attributes with per-action permissions.
- Signed session-bound snapshots, update-time authentication and authorization,
  typed writable state validation, and rendered validation errors.
- htmx transport, Alpine CSP/Morph integration, debounced input, serialized requests,
  stale-result suppression, loading/error states, lifecycle hooks, and scoped URL state.
- Compiled content-hashed assets with a manifest and cacheable asset endpoint.
- Searchable/paginated product example with two independent components, English,
  Kurdish and Arabic labels, and RTL layouts.
- PHP and browser regression tests and CI. Requires Core 2.3.2 explicit responses.
