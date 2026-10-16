# Product edit modal

Open `/en/demo`, `/ku/demo`, or `/ar/demo` from the runnable package checkout:

```sh
composer install
php -S 127.0.0.1:8080 examples/products/router.php
```

Edit a product in either table. Change its name or price, submit, and see both
tables refresh without a page reload. Cancel or Escape discards the draft. The
example supports English/Kurdish/Arabic, RTL, field errors, deleted records,
revoked access and optimistic conflicts. Compiled assets use the release version, for example `reactive-v0.5.0.*`.

The implementation lives in `examples/products/ProductTable.php`, `table.html`,
`schema.php` and `lang/`. The router uses a private SQLite file per demo session
under `sys_get_temp_dir()/flocms-reactive-demo-v0.3`. New example databases use
integer cents, ownership and record versions; old demo databases are not migrated.
Demo login, fixed signing keys and test fixture routes are not production setup.

## Declare candidate and active record state separately

`selectedId` is writable and lets an Edit button propose a record. The `edit`
action reloads that record, checks permissions/ownership/scope and replaces any
submitted draft with its current values. The server then sets locked `editingId`,
`editingVersion` and `editorOpen`. The save action uses those sealed fields and
ignores later changes to the writable candidate selection.

The product catalog is shared with users carrying `catalog.view`; editing also
requires `catalog.edit` and ownership by the current authenticated user. The
featured table additionally requires that the record remains featured. Those
checks run on opening and on subsequent editing requests, including inside the
save transaction. Apply your application's tenant/read/edit policies consistently
when adapting these queries; the example policy is deliberately small.

`instanceKey` is locked initial state and supplies unique IDs for the controls:

```php
Reactive::mount('products', 'catalog', ['instanceKey' => 'catalog']);
Reactive::mount('products', 'featured', [
    'instanceKey' => 'featured',
    'featuredOnly' => true,
]);
```

Use stable, matching instance keys and unique control IDs for every mounted copy.
Locked state is visible to the browser; it is not a place to store secrets.

## Native dialog template contract

Keep the dialog element in the rendered component, including while closed:

```html
<button id="catalog-edit-2" type="button"
        data-rx-action="edit" data-rx-set="selectedId" data-rx-value="2"
        data-rx-dialog-trigger="editor">Edit</button>

<dialog id="catalog-editor" data-rx-dialog="editor"
        data-rx-open="{{ $editorOpen ? 'true' : 'false' }}"
        data-rx-close="cancel" aria-labelledby="catalog-editor-title">
    <h2 id="catalog-editor-title">Edit product</h2>
    @if($editorOpen)
    <form data-rx-submit="save" novalidate>
        <label for="catalog-edit-name">Name</label>
        <input id="catalog-edit-name" data-rx-bind="editName"
               value="{{ $editName }}" autofocus>
        <!-- Add aria-invalid, aria-describedby and escaped field errors. -->
        <button type="submit">Save changes</button>
        <button type="button" data-rx-dialog-close="editor">Cancel</button>
    </form>
    @endif
    <p data-rx-status role="status" aria-live="polite"></p>
</dialog>
```

Do not render the native `open` attribute yourself. `data-rx-open` is the server
decision; the adapter calls `showModal()` and `close()`, preserving native modal
state during DOM morphs. `data-rx-dialog` names are unique within a component.
The trigger name and close-button name refer to that same dialog.

Native dialogs make the background inert. The adapter wraps Tab/Shift+Tab focus,
honors `autofocus`, keeps focus and selection through draft refreshes, and focuses
the first invalid control (or a `data-rx-error-summary` with `tabindex="-1"`) after
a failed submit. Closing returns focus to the original trigger or its replacement
with the same ID. If that row disappeared, use `data-rx-focus-fallback` on the search
input; otherwise the wrapper receives focus. Removing a component closes its dialog,
detaches listeners and releases the stylesheet's scrolling lock.

Add a status container inside the dialog so network/authentication errors are
visible while the background is inert. The wrapper status container is retained
for updates that fail before a dialog opens or after local cancellation.

## Cancellation and mutations

Escape and `data-rx-dialog-close` close locally, then invoke the named exposed
discard action with no writable draft updates. They clear pending debounce work.
Cancellation still runs session/view authorization on the server. It must only
clear editing state, not write records; incomplete or invalid business drafts must
not block it. The example permits discard after edit permission, ownership or
featured status is lost, while still enforcing `catalog.view`.

If view permission, CSRF, the connection or the server fails during cancellation,
the local dialog remains closed. The page displays the failure and the user can
reload to restore canonical server state. A local close does not authorize any
mutation and does not save the draft.

During a non-refresh action, dialog-bound inputs and action buttons are disabled.
Escape/cancellation is ignored until the request finishes; cancellation cannot
undo a save that already reached the server. Running actions are not automatically
replayed by repeated submits. Ordinary draft refreshes remain debounced and editable.

## Validation, exact prices and optimistic concurrency

Required/name/price checks run only in `save`, so incomplete drafts can update.
ValidationException returns rendered errors with 422 and a fresh signed snapshot.
Accepted invalid drafts stay available for correction and are escaped in templates.
The example accepts ASCII decimal prices from 0 to 999999.99, with at most two
decimal places. Prices remain strings in state and are converted to integer cents
without float arithmetic before storage. Localized labels do not imply localized
numeric parsing; normalize localized digits/formats explicitly if your application
accepts them.

Every save reloads the record and checks its current policy in a transaction.
The database update includes the sealed ID, expected version, authenticated owner
and optional featured scope, and increments the version. A version mismatch or
zero affected rows is a conflict; the newer record is not overwritten. All other
writers to the same resource must increment that version too.

Conflict errors preserve the draft. Close and reopen to load the newer version;
the package does not silently merge fields. Deleted records return an explanatory
error and cannot be recreated by save. Loss of ownership/scope/edit permission
returns 403 and still permits a view-authorized discard. Database exceptions propagate
and roll back instead of becoming a success notification.

Search and pagination remain independent of editor state. A saved name that no
longer matches a search disappears from that table; if its last page becomes empty,
normal pagination clamps to the remaining last page and focus falls back to search.

## Refresh other instances after a commit

The originating component re-renders itself. After committing, the example calls:

```php
$this->refreshComponents('catalog', 'featured');
```

The response carries up to 32 unique, validated component keys. Effects are not
stored in signed snapshots and do not repeat on later refreshes. The browser skips
the origin and invokes each other mounted instance's normal refresh endpoint with
that instance's own session/snapshot/state; current permissions are checked again.
Effects run only for successful responses, including an obsolete action response
whose mutation committed while newer edits suppressed its HTML.

An open target editor retains its draft and sealed version while table rows refresh.
Its next save still checks concurrency. External JavaScript can call
`window.FloReactive.refresh('featured')`; unknown/unmounted keys return false.
These are explicit component refreshes, not nested components or a general event bus.

## Checks and next integration

PHP tests cover record reloads, candidate/active identity separation, locked-state
tampering, permission/ownership/scope changes, exact prices, invalid drafts, deletion,
stale/replayed saves, request metadata, rollback and response-only effects. Browser
tests cover keyboard opening/focus wrapping, cancellation, save/error focus, both
tables, URL preservation, conflicts, deletion, permission loss, failed cancellation,
slow saves, cleanup, refresh APIs, strict CSP and mobile Kurdish/Arabic layouts.

This prototype has no stacked/nested dialog contract, confirmation workflow,
generic notification center or Admin resource schema. The next step is to resolve
the Admin correctness findings and use these contracts in one Admin table/form.
