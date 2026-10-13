# Dependent country/city form

The runnable form at `/en/demo/form` demonstrates changing a country, loading
only its cities, resetting a previous city, displaying field errors, and saving
an address that survives a reload. Kurdish and Arabic routes use RTL layouts.

Start the development example from a package Git checkout:

```sh
composer install
php -S 127.0.0.1:8080 examples/products/router.php
```

The implementation is in `examples/forms/LocationForm.php`, `location.html`,
`schema.php`, and `lang/{en,ku,ar}.php`. The demo router initializes a private
SQLite file for each session. It supplies fake login and a fixed example signing
key; use your application's real login, APP_KEY, database and migrations when
adapting the component. Demo lookup labels use fixed IDs for clarity.

## State and dependent reset

Use explicitly declared scalar state for the draft. IDs use `0` to represent
an empty selection, so they remain non-null integers:

```php
#[State]
public string $name = '';
#[State(rules: 'integer|min:0|max:1000000')]
public int $countryId = 0;
#[State(rules: 'integer|min:0|max:1000000')]
public int $cityId = 0;

public function updated(string $property): void
{
    if ($property === 'countryId') $this->cityId = 0;
}
```

The country select uses `data-rx-bind="countryId" data-rx-reset="cityId:0"`.
The browser immediately clears the related select; the PHP hook enforces the
same reset when browser behavior is bypassed. All accepted fields are applied
before hooks run, in declaration order, so a submitted city cannot overwrite
the country hook's reset. A single request changing both country and city resets
the city; select the city after the country update completes.

The options query filters by the current country. A country with no cities
disables the city select and displays an explanation. This query limits the UI;
validation must still verify the relationship against current database records.

## Draft validation and save validation

`validate()` checks non-empty selections on every update. Incomplete drafts
are permitted. A forged city, unknown country, or removed lookup returns 422.
The declared field rules still reject malformed scalar values before hooks run.

Required and business checks belong to the explicit save action. Use Core's
Validator or throw its ValidationException with translated field messages:

```php
#[Action(permission: 'locations.edit')]
public function save(): void
{
    App::db()->transaction(function (): void {
        // Reload the current record/lookups and check ownership/permissions.
        Validator::validate(['name' => trim($this->name)], [
            'name' => 'required|string|max:100',
        ]);
        // Verify that cityId belongs to countryId before writing.
        // Persist only validated values using parameter-bound queries.
    });
}
```

Kernel catches ValidationException from the action and returns rendered field
errors with 422 and a fresh signed snapshot. Accepted draft values remain
available for correction. Validation inside a transaction rolls back writes;
ordinary database failures propagate rather than appearing as success or field
validation. A transaction cannot undo email delivery or other external effects.

Keep draft strings within the runtime's 8KB scalar limit. The example delays
required/max-name business checks until save, allowing incomplete and invalid
names to remain visible and editable. A malformed type or a failed `State::rules`
check still retains the last accepted batch, as in the table example.

## Permissions, ownership and persistence

`authorize()` requires `locations.view` on mount and every update. The save
action independently requires `locations.edit`, including after permissions
change. Users with view permission alone see a disabled save button; server
authorization remains authoritative if that button is bypassed.

The owner ID comes from the authenticated session on each request and is never
a writable state field. Address reads and writes are scoped to that identity.
`mount()` reads the existing address for reload persistence. Components themselves
are recreated from signed snapshots; the application database stores the address.
The example maintains one address per owner and updates it on later saves.

For other resources, load and authorize the specific record as well. Tenant and
record scopes must cover both option queries and mutations. For external effects
or concurrent requests outside this browser component, add application-level
idempotency and conflict handling appropriate to the operation.

## Accessible template and browser behavior

The template associates labels with stable input IDs and uses `aria-invalid`
and `aria-describedby` for field errors and help text. Messages are escaped by
FloCMS templates. Error containers use `role="alert"`; the success message uses
`role="status"`. This example mounts one location form per page; give controls
unique IDs when adapting it for multiple instances.

`<form data-rx-submit="save" novalidate>` submits the registered action.
`novalidate` lets server errors demonstrate a consistent localized validation
flow. Binding tracks input changes, and submit also reads current controls to
capture autofill that did not emit an input event. Related resets update controls
before that collection. Submit buttons are temporarily disabled during updates,
and an already disabled button remains disabled afterwards.

Requests remain serialized. Newer country edits suppress older options, focus
survives DOM updates, and duplicate submits while busy do not replay a running
save. Client serialization is not a server-side idempotency guarantee. Forms
needing operation without JavaScript should also provide an ordinary POST route.

The PHP tests cover relationship validation, reset ordering, required errors,
draft escaping, fresh snapshots, persistence, user isolation, revocation and
transaction rollback. Browser tests cover selects, autofill, save errors/recovery,
reloads, slow updates, duplicate submits, permissions, mobile layouts and RTL.
