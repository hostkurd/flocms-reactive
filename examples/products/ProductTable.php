<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Examples;

use FloCMS\Reactive\{Component, Attributes\State, Attributes\Action};
use FloCMS\Core\{App, Auth, HttpException, Session, Validator, ValidationException};
use FloCMS\Core\Http\Request;

final class ProductTable extends Component
{
    #[State(rules: 'max:100', query: 'q')]
    public string $search = '';

    #[State(rules: 'integer|min:1|max:100000', query: 'page')]
    public int $page = 1;

    // Bound on the server and sealed in the snapshot; browsers cannot change it.
    #[State(writable: false)]
    public bool $featuredOnly = false;

    // Supply a matching stable key when mounting multiple table instances.
    #[State(writable: false)]
    public string $instanceKey = 'catalog';
    #[State(rules: 'integer|min:0|max:1000000')]
    public int $selectedId = 0;
    #[State(writable: false)]
    public int $editingId = 0;
    #[State(writable: false)]
    public int $editingVersion = 0;
    #[State(writable: false)]
    public bool $editorOpen = false;
    #[State]
    public string $editName = '';
    #[State]
    public string $editPrice = '';
    #[State(writable: false)]
    public string $notice = '';

    public function authorize(Request $request): void
    {
        Auth::authorize('catalog.view');
        if ($this->editorOpen && $request->input('_action') !== 'cancel') {
            Auth::authorize('catalog.edit');
            $this->record($this->editingId);
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'search') { $this->page = 1; }
        $this->notice = '';
    }

    #[Action(permission: 'catalog.view')]
    public function clear(): void { $this->search = ''; $this->page = 1; $this->notice = ''; }

    #[Action(permission: 'catalog.view')]
    public function goToPage(): void {}

    #[Action(permission: 'catalog.edit')]
    public function edit(): void
    {
        $record = $this->record($this->selectedId);
        if ($record === null) { throw new ValidationException(['_form'=>$this->labels()['deleted']]); }
        $this->editingId = (int) $record->id;
        $this->editingVersion = (int) $record->version;
        $this->editName = (string) $record->name;
        $this->editPrice = intdiv((int) $record->price_cents, 100) . '.' . sprintf('%02d', (int) $record->price_cents % 100);
        $this->editorOpen = true;
        $this->notice = '';
    }

    #[Action(permission: 'catalog.edit')]
    public function save(): void
    {
        $labels = $this->labels();
        if (!$this->editorOpen || $this->editingId < 1) { throw new HttpException(400, 'No product is being edited.'); }
        $this->editName = trim($this->editName);
        $errors = [];
        try { Validator::validate(['editName'=>$this->editName], ['editName'=>'required|string|max:100']); }
        catch (ValidationException) { $errors['editName'] = $this->editName === '' ? $labels['required_name'] : $labels['long_name']; }
        // Money stays a decimal string in the draft and integer cents in storage.
        if (preg_match('/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/D', $this->editPrice) !== 1) { $errors['editPrice'] = $labels['invalid_price']; }
        if ($errors !== []) { throw new ValidationException($errors); }
        [$whole, $fraction] = array_pad(explode('.', $this->editPrice, 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        App::db()->transaction(function () use ($cents, $labels): void {
            // Reload inside the transaction; signed IDs/versions do not replace
            // current record authorization or optimistic concurrency checks.
            Auth::authorize('catalog.edit');
            $record = $this->record($this->editingId);
            if ($record === null) { throw new ValidationException(['_form'=>$labels['deleted']]); }
            if ((int) $record->version !== $this->editingVersion) { throw new ValidationException(['_form'=>$labels['conflict']]); }
            $statement = App::db()->pdo()->prepare('UPDATE products SET name = ?, price_cents = ?, version = version + 1 WHERE id = ? AND version = ? AND owner_id = ?' . ($this->featuredOnly ? ' AND featured = 1' : ''));
            $statement->execute([$this->editName, $cents, $this->editingId, $this->editingVersion, (int) Session::get('user_id', 0)]);
            if ($statement->rowCount() !== 1) { throw new ValidationException(['_form'=>$labels['conflict']]); }
        });
        $this->discard();
        $this->notice = $labels['saved'];
        $this->refreshComponents('catalog', 'featured');
    }

    #[Action]
    public function cancel(): void { $this->discard(); $this->notice = ''; }

    private function discard(): void
    {
        $this->editorOpen = false;
        $this->selectedId = $this->editingId = $this->editingVersion = 0;
        $this->editName = $this->editPrice = '';
    }

    private function record(int $id): ?object
    {
        $record = App::db()->table('products')->where('id', '=', $id)->first();
        if ($record !== null && (($this->featuredOnly && !(bool) $record->featured)
            || (int) $record->owner_id !== (int) Session::get('user_id', 0) || (int) Session::get('user_id', 0) < 1)) {
            throw new HttpException(403);
        }
        return $record;
    }

    private function labels(): array
    {
        $language = defined('ACTIVE_LANG') ? ACTIVE_LANG : 'en';
        return require __DIR__ . '/lang/' . (in_array($language, ['en', 'ku', 'ar'], true) ? $language : 'en') . '.php';
    }

    public function render(): string
    {
        $query = App::db()->table('products');
        if ($this->featuredOnly) { $query = $query->where('featured', '=', 1); }
        if ($this->search !== '') {
            $like = '%' . strtr($this->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query = $query->whereRaw("name LIKE ? ESCAPE '!'", [$like]);
        }
        $result = $query->orderBy('id', 'asc')->paginate($this->page, 5);
        $last = max(1, (int) $result['pagination']['last_page']);
        if ($this->page > $last) { $this->page = $last; return $this->render(); }
        $labels = $this->labels();
        $errors = $this->errors;
        if ($this->editorOpen && $this->record($this->editingId) === null) { $errors['_form'] = $labels['deleted']; }
        return $this->view(__DIR__ . '/table.html', ['products' => $result['data'], 'search' => $this->search,
            'page' => $this->page, 'last' => $last, 'total' => $result['pagination']['items_count'], 'labels' => $labels,
            'prefix'=>'rx-' . $this->instanceKey, 'editorOpen'=>$this->editorOpen, 'editName'=>$this->editName, 'editPrice'=>$this->editPrice,
            'notice'=>$this->notice, 'errors'=>$errors, 'canEdit'=>Auth::can('catalog.edit'), 'ownerId'=>(int) Session::get('user_id', 0)]);
    }
}
