# Changelog

## 0.3.0 - Unreleased

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
