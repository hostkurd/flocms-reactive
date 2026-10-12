# FloCMS Reactive

Optional reactive PHP components for FloCMS. Define state and actions in PHP;
the browser receives server-rendered HTML updates without a full page refresh.
Works on public websites and supplies a foundation for future Admin components.

**Status: 0.1 prototype.** The first milestone is a searchable, paginated product
table. Nested components, uploads, persistent component storage, and a complete
Admin integration are not included yet. FloCMS Core and the skeleton remain
usable without this package.

## Requirements and release order

- PHP 8.1+, JSON, mbstring, and FloCMS Core **2.3.2+**.
- Core [PR #9](https://github.com/hostkurd/flocms-core/pull/9) adds generic explicit
  controller responses; merge and release it as 2.3.2 before releasing Reactive.
- A real application needs its normal session startup and `APP_KEY`.
- End-user hosting needs PHP and Composer, not Node.js. Compiled assets ship in
  `dist/`; Node.js is used only to develop/rebuild the package's browser bundle.

While Core 2.3.2 is awaiting release, develop against its checkout:

```sh
git clone -b feature/controller-responses https://github.com/hostkurd/flocms-core.git ../flocms-core
php scripts/use-core-checkout.php ../flocms-core
COMPOSER=composer.local.json composer install
php -S 127.0.0.1:8080 examples/products/router.php
```

Open `/en/demo`, `/ku/demo`, or `/ar/demo`. The standalone development example
uses an in-memory SQLite catalog and a demo session. It is not a production
authentication implementation. Do not deploy its router or fixed signing key.
The executable example is included in a Git checkout, not the Composer archive.

After the stable packages are released:

```sh
composer require hostkurd/flocms-reactive
```

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

Assets use hashed filenames and immutable cache headers. The update endpoint uses
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

- `data-rx-bind`: update a declared writable property; input updates debounce 300ms.
- `data-rx-reset="page:1"`: include a related state reset. Enforce the invariant in
  PHP's `updated()` hook too; browser controls can be bypassed.
- `data-rx-submit`: explicitly submit an action, defaulting to `refresh`.
- `data-rx-action`: invoke a zero-argument method carrying `#[Action]`.
- `data-rx-set` + `data-rx-value`: set a declared property before an action (pagination).
- `data-rx-loading`: visible while the component request is running.

`refresh` is reserved for rendering without calling a component method. Normal PHP
methods, including lifecycle methods, are not exposed unless explicitly marked.
Components are not nested in v0.1. Supply an ordinary form/page alternative when
the application needs operation without JavaScript.

## State, lifecycle, and validation

Only initialized, public properties carrying `#[State]` are transmitted. v0.1
supports non-null `string`, `int`, `float`, and `bool`; objects, models, arrays, and
closures are excluded. `#[State(writable: false)]` seals server-provided context,
such as a category ID. It still appears in the browser: never put secrets in state.

`mount()` runs initially; updates recreate the component from its signed state.
`authorize()` runs for initial rendering and every update, including after writable
changes and around actions. Read authorization decisions from current records and
permissions, not from a previous mount. `updated()` handles state changes and must
not write records or send external messages. `validate()` can return additional
field errors. The component's `errors()`/template `$errors` expose validation errors.

`State::rules` uses Core Validator rules (`required`, `string`, `integer`, `numeric`,
`min`, `max`, `in`). Invalid updates do not run an action and return a rendered 422.
For invalid type/rule input the last valid state is retained in this prototype.
For database mutations, reload the record, authorize it, validate business input,
and use a transaction inside the explicitly exposed action.

## Request and security contract

Updates POST `_snapshot`, `_token`, optional `state[property]`, and `_action` to
`/<language>/flo-reactive/update`. Action parameters and arbitrary class names are
not accepted. Requests are serialized per component; newer pending refreshes
replace older pending refreshes. Running actions are not automatically replayed.
For financial/external side effects, implement application-level idempotency.

- Snapshots are HMAC-signed, tied to the session CSRF token, and expire after 30
  minutes by default (`Config::set('reactive.ttl', 1800)`). Signatures do not encrypt.
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

Browser events: `flo-reactive:initialized`, `flo-reactive:before-update`,
`flo-reactive:updated`, `flo-reactive:error`, and `flo-reactive:destroyed`.
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

Rebuild and commit `dist/` with browser-source changes. CI checks PHP 8.1–8.4,
TypeScript, reproducible assets, and Chromium browser interactions. The immutable
Core checkout in CI is a temporary bridge until 2.3.2 is released. Node 24 is used
for the package build. See `docs/architecture.md` for boundaries and next milestones.

## License

MIT. Bundled third-party notices are in `THIRD_PARTY_NOTICES.md`.
