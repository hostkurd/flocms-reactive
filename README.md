# FloCMS Reactive

Optional reactive PHP components for FloCMS. Define state and actions in PHP;
the browser receives server-rendered HTML updates without a full page refresh.
Works on public websites and supplies a foundation for future Admin components.

**Status: 0.5, pre-1.0.** The API may still change between minor versions. Examples include a searchable, paginated
product table with an edit modal, a dependent country/city form with transactional saving,
and a contact form showing the binding modes.
Nested components, uploads, persistent component storage, and a complete
Admin integration are not included yet. FloCMS Core and the skeleton remain
usable without this package.

## Requirements and installation

- PHP 8.1+, JSON, mbstring, and FloCMS Core **2.3.2+**.
- A real application needs its normal session startup and `APP_KEY`.
- End-user hosting needs PHP and Composer, not Node.js. Compiled assets ship in
  `dist/`; Node.js is used only to develop/rebuild the package's browser bundle.

Install into a FloCMS application. FloCMS 1.8.1 pins Core 2.3.1, so update
that root requirement explicitly if your skeleton still uses it:

```sh
composer require hostkurd/flocms-core:2.3.2 hostkurd/flocms-reactive -W
```

If the root requirement is already Core 2.3.2+, `composer require
hostkurd/flocms-reactive` is sufficient.

## Run the examples

From a Git checkout of this package:

```sh
composer install
php -S 127.0.0.1:8080 examples/products/router.php
```

Open `/en/demo` for the tables, `/en/demo/form` for the country/city form or
`/en/demo/contact` for the binding modes.
Both also support `/ku/` and `/ar/`. The demo uses SQLite files in
`sys_get_temp_dir()/flocms-reactive-demo-v0.3`, one per demo session, so saved
addresses and product edits survive a reload. This versioned directory starts fresh
rather than migrating old example databases. Delete those files after testing. They are application records;
Reactive itself still recreates components from signed snapshots on each request.

The demo session is not production authentication. Do not deploy its router or
fixed signing key. Executable examples are included in Git, not the Composer archive.
See [the dependent form walkthrough](docs/dependent-forms.md).
Use Edit in either product table for [the edit-modal walkthrough](docs/edit-modals.md).

## Define a component

```php
namespace App\Components;

use FloCMS\Reactive\Component;
use FloCMS\Reactive\Attributes\{State, Action};
use FloCMS\Core\Auth;
use FloCMS\Core\Http\Request;

final class ProductTable extends Component
{
    #[State(rules: 'max:100', query: 'q')]
    public string $search = '';

    #[State(rules: 'integer|min:1|max:100000', query: 'page')]
    public int $page = 1;

    public function authorize(Request $request): void
    {
        Auth::authorize('catalog.view');
    }

    public function updated(string $property): void
    {
        if ($property === 'search') $this->page = 1;
    }

    #[Action(permission: 'catalog.view')]
    public function clear(): void
    {
        $this->search = '';
        $this->page = 1;
    }

    public function render(): string
    {
        // Run an authorized, parameter-bound query here.
        return $this->view(ROOT . '/views/components/products.html', [
            'search' => $this->search,
            'page' => $this->page,
        ]);
    }
}
```

See `examples/products/ProductTable.php` and `table.html` for the complete database
query, pagination, locked featured scope, localized labels, and empty/loading states.
Put `App\Components` in your application's Composer autoload mapping, or use an
existing application namespace. This package does not rewrite your composer.json.

Register component aliases explicitly in `config/reactive.php`:

```php
return ['products' => App\Components\ProductTable::class];
```

Alternatively call `Reactive::register('products', ProductTable::class)` in your
normal application bootstrap. The lazy package resolver answers only the default
route's `flo-reactive` controller. Application controllers retain priority: reserve
that controller name, or mount an update endpoint yourself using `Kernel`.

## Mount and load assets

In a FloCMS template:

```html
{!! \FloCMS\Reactive\Reactive::assets() !!}
{!! \FloCMS\Reactive\Reactive::mount('products', 'catalog') !!}
```

Load assets once per page, preferably in the layout's head. Component keys must be
unique and stable (`catalog`, `featured`), using lowercase letters, digits, and
hyphens. The wrapper IDs are `rx-catalog`, `rx-featured`. Query state is scoped to
those keys (`catalog_q`, `catalog_page`), so multiple components do not overwrite
each other's state. Refreshing a URL restores its component query state. Back and
forward navigation reloads the page in this prototype.

Assets use the package's full release version (for example `reactive-v0.5.0.js`
and `reactive-v0.5.0.css`) and immutable cache headers. The build reads the version
from `package.json`; the manifest and `Reactive::assets()` select the filenames
automatically. Every released asset change needs a new package version so browsers
download the update. Do not replace published files under an existing version.
The update endpoint uses
`Cache-Control: no-store`. Endpoint and asset URLs honor `SITE_URI` and the active
language, including applications installed below a subdirectory.

## Template controls

```html
<form data-rx-submit="refresh">
    <label>Search
        <input type="search" value="{{ $search }}"
               data-rx-bind="search" data-rx-reset="page:1">
    </label>
    <button type="submit">Search</button>
    <button type="button" data-rx-action="clear">Clear</button>
    <span data-rx-loading>Loading…</span>
</form>
```

- `data-rx-bind`: update a declared writable property; by default an update is sent
  300 ms after typing stops. `data-rx-model` chooses another moment (below).
- `data-rx-reset="page:1"`: include a related state reset. Enforce the invariant in
  PHP's `updated()` hook too; browser controls can be bypassed. Matching bound
  controls are reset immediately, including dependent selects.
- `data-rx-submit`: explicitly submit an action, defaulting to `refresh`. Submit
  reads current bound control values, including autofill that emitted no input event.
  Buttons are disabled while an action runs; existing disabled states are preserved.
  A click during a plain update is queued and runs right after it, once.
- `data-rx-action`: invoke a zero-argument method carrying `#[Action]`.
- `data-rx-set` + `data-rx-value`: set a declared property before an action (pagination).
- `data-rx-loading`: visible while the component request is running.
- `data-rx-dialog`, `data-rx-open`, `data-rx-close`: manage a native `<dialog>`
  with server-controlled opening and an explicit discard action.
- `data-rx-dialog-trigger`: remember the triggering action button for focus return.
- `data-rx-dialog-close`: cancel locally and request the server discard action.
- `data-rx-focus-fallback`: focus target when a dialog's opener has disappeared.
- `data-rx-ignore="key"` (0.5+): updates leave this element and its contents alone while
  the key stays the same, so a widget that builds its own DOM (a rich text editor, a map)
  survives. When the server renders another key (another record), it is replaced.

### When a bound control is sent (0.5+)

```html
<input data-rx-bind="name">                                <!-- live: 300 ms after typing stops -->
<input data-rx-bind="search" data-rx-model="debounce:600"> <!-- live, with another delay (0–10000 ms) -->
<input data-rx-bind="email"  data-rx-model="lazy">         <!-- when the value is committed (change) -->
<input data-rx-bind="city"   data-rx-model="blur">         <!-- when an edited control loses focus -->
<textarea data-rx-bind="body" data-rx-model="defer"></textarea> <!-- only with the next request -->
```

Every mode keeps the browser's copy current, so an action (Save) always sends the
latest values of all bound controls. Use `lazy`, `blur` or `defer` for long forms:
each keystroke pause is otherwise a request, which matters on shared hosting.
A deferred or lazy value that was typed while another update was running is kept
as typed when that update's response arrives; the server copy catches up with the
next request. Run the example at `/en/demo/contact`.

`refresh` is reserved for rendering without calling a component method. Normal PHP
methods, including lifecycle methods, are not exposed unless explicitly marked.
Components are not nested in this prototype. Supply an ordinary form/page alternative when
the application needs operation without JavaScript.

## State, lifecycle, and validation

Only initialized, public properties carrying `#[State]` are transmitted. This prototype
supports non-null `string`, `int`, `float`, and `bool`; objects, models, arrays, and
closures are excluded. `#[State(writable: false)]` seals server-provided context,
such as a category ID. It still appears in the browser: never put secrets in state.

`mount()` runs initially; updates recreate the component from its signed state.
`authorize()` runs for initial rendering and every update, including after writable
changes and around actions. Read authorization decisions from current records and
permissions, not from a previous mount. All valid submitted properties are applied
before `updated()` runs, in property declaration order. Hooks see the complete
accepted batch and their dependent resets are not overwritten by later client fields.
Initial query-state restoration validates and applies values without calling
`updated()`, preserving a bookmarked search/page combination.
Hooks must not write records or send external messages. `validate()` can return additional
field errors. The component's `errors()`/template `$errors` expose validation errors.

`validate()` runs before every action and, by default, on plain updates too. Return
`false` from `validatesOnUpdate()` to check only before actions, so a half-filled
form shows no errors and a costly check (a database lookup) does not run while typing.
Field rules from `#[State(rules: ...)]` always apply. Mark Cancel, Discard or Reset
actions `#[Action(validate: false)]`: they run even while the form has errors
(a value rejected by its field rule is still not applied).

```php
public function validatesOnUpdate(): bool { return false; }

#[Action(validate: false)]
public function discard(): void { $this->name = ''; }
```

`State::rules` uses Core Validator rules (`required`, `string`, `integer`, `numeric`,
`min`, `max`, `in`). Invalid updates do not run an action and return a rendered 422.
For invalid type/rule input the last valid state is retained in this prototype.
For database mutations, reload the record, authorize it, validate business input,
and use a transaction inside the explicitly exposed action.
An action may throw Core's `ValidationException`; Kernel renders its field errors
with HTTP 422 and a fresh snapshot, retaining the accepted draft. Other exceptions
still propagate. Validate before writing, or throw within a transaction so writes
roll back. Action-only required checks let incomplete forms update without errors.

After a successful commit, `$this->refreshComponents('catalog', 'featured')`
requests refreshes of other mounted component keys. These are response-only effects,
not snapshot state. Each target refresh uses its own snapshot and current authorization;
the originating component already renders its new data. External JavaScript may call
`window.FloReactive.refresh('catalog')` (returns false for an unknown key).

### Larger string drafts (0.4+)

Properties retain an 8,192 UTF-8 byte limit by default. A form component may explicitly declare a larger string:

```php
#[State(maxBytes: 65536)]
public string $draft = '';
```

`maxBytes` must be 1–65,536. An override applies only to strings; query-bound properties cannot exceed 8,192 bytes. Input, server values and restored snapshots all enforce the declared byte limit. Existing scalar-only rules, writable flags, session/CSRF/signature checks and action authorization still apply. Signed state is readable by the browser and must never contain secrets.

Only schemas opting into larger strings accept a larger HTTP envelope (up to 1 MiB); other schemas keep the 64 KiB request limit. Aggregate signed snapshot JSON is bounded at 256 KiB (the encoded token is capped at 350,000 bytes). JSON escaping and URL encoding count toward envelope limits, so allocate headroom when choosing your component's own draft budget. Configure the web server/PHP request-body limits accordingly. This does not introduce nested state, uploads or automatic write retries.

Upgrade the Composer package and deploy the matching release-version assets. Reload open pages when changing a component's schema or limits; a restored value exceeding the new limit returns 409. Core remains `^2.3.2`, and standalone FloCMS does not depend on Reactive. The local example at `/en/demo/large` demonstrates a larger draft through the real browser transport.

## Edit modals

The product example reloads and authorizes a selected record in an explicit `edit`
action, then seals the active record ID and version in locked state. `save` validates
the draft, reloads/rechecks ownership and scope inside a transaction, and updates
only if the version still matches. Prices are decimal strings in drafts and integer
cents in storage. Stale/replayed saves return a field-error response without overwriting
newer records. Successful saves close the dialog and refresh both example tables.

Native dialogs provide background inertness; the adapter preserves them through
morph updates, wraps keyboard Tab focus, handles Escape, restores opener/fallback
focus, and cleans up removed components. Include a status element inside the dialog
so transport/authentication errors remain visible there. Mutation requests disable
dialog-bound controls, submission and cancellation until the request settles.
Cancellation closes locally and sends no draft updates, so failed cancellation
cannot trap the user or persist their draft. The PHP discard action still needs
view/session authorization and must not require valid business input or mutate records.

See [docs/edit-modals.md](docs/edit-modals.md) for the complete contracts and example.

## Request and security contract

Updates POST `_snapshot`, `_token`, optional `state[property]`, and `_action` to
`/<language>/flo-reactive/update`. Action parameters and arbitrary class names are
not accepted. Requests are serialized per component; newer pending refreshes
replace older pending refreshes. Running actions are not automatically replayed.
For financial/external side effects, implement application-level idempotency.

- Snapshots are HMAC-signed, tied to the session CSRF token, and expire after 30
  minutes by default (`Config::set('reactive.ttl', 1800)`). Signatures do not encrypt.
- Long sessions (0.5+): while a page stays open, the browser re-signs each component's
  snapshot once half the TTL has passed (`_action=$renew`). Renewal re-checks CSRF,
  the session, the admin context and `authorize()`, re-signs the unchanged state and
  renders nothing. An editor can keep a form open for hours without losing the draft;
  the session itself (login, CSRF token) still ends as configured, and then the
  component shows the "session ended" or "expired" message.
- A signed snapshot can be replayed until it expires. That is safe because every
  update re-checks permissions and actions re-check records, but do not rely on
  snapshot expiry for anything else.
- Signing uses `APP_KEY`, or an explicit `reactive.key`, with at least 32 bytes.
- State/property allowlists, action allowlists, and typed input are enforced server-side.
- Core CSRF runs before the endpoint, and Kernel repeats the check for custom mounts.
- `Auth::refresh()` runs on updates. Configure Core's user loader; its configured
  refresh interval also applies. Use interval 0 for checks on every request.
- Admin mounting context is signed and enforced at the update endpoint. Other route
  middleware/guards are not automatically inherited: repeat them in `authorize()`.
- Application record/tenant scopes must apply to queries and actions. An allowed
  component is not permission to access every record.
- Invalid signatures/properties return 400; unknown components/actions 404;
  authorization 403; ended sessions 401; CSRF/expired snapshots 419; validation 422.

The browser bundle uses htmx for transport, Alpine's CSP build for local behavior,
and Alpine Morph for DOM updates. htmx evaluation, script execution, and history
HTML caching are disabled. The example tests `script-src 'self'` and
`style-src 'self'`, without inline scripts or `unsafe-eval`. Escape untrusted
template content as usual; rich HTML still needs application sanitization.

The bundle starts Alpine only when `window.Alpine` is absent. Load an application's
Alpine before the Reactive bundle if it already owns Alpine initialization.

Messages the runtime shows itself (session ended, expired, no permission, failed
update) follow the page language: the package ships English, Arabic and Kurdish
(Sorani), with English for other languages. A site overrides any line in its own
lang files with the key `reactive.<name>`, for example
`'reactive.expired' => 'Please reload the page.'`. Names: `session`, `forbidden`,
`missing`, `changed`, `expired`, `failed`, `invalid`.

Browser events: `flo-reactive:initialized`, `flo-reactive:before-update`,
`flo-reactive:updated`, `flo-reactive:error`, and `flo-reactive:destroyed`.
Dialogs also emit `flo-reactive:dialog-opened` and `flo-reactive:dialog-closed`
with `detail.name` identifying the dialog within its owning component.
Use them to integrate and clean up editors and other JavaScript widgets.

## Development and checks

```sh
vendor/bin/phpunit
npm ci
npm run typecheck
npm run build
npx playwright install chromium
npm run test:browser
```

For a new release, update the full version in `package.json` and `package-lock.json`,
then rebuild and commit `dist/` with browser-source changes. CI checks PHP 8.1–8.4,
TypeScript, reproducible assets, and Chromium browser interactions. CI installs
the released Core package from Packagist. Node 24 is used
for the package build. See `docs/architecture.md` for boundaries and next milestones.

## License

MIT. Bundled third-party notices are in `THIRD_PARTY_NOTICES.md`.
