<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Examples;

use FloCMS\Reactive\{Component, Attributes\State, Attributes\Action};
use FloCMS\Core\{App, Auth};
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

    public function authorize(Request $request): void { Auth::authorize('catalog.view'); }

    public function updated(string $property): void
    {
        if ($property === 'search') { $this->page = 1; }
    }

    #[Action(permission: 'catalog.view')]
    public function clear(): void { $this->search = ''; $this->page = 1; }

    #[Action(permission: 'catalog.view')]
    public function goToPage(): void {}

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
        $language = defined('ACTIVE_LANG') ? ACTIVE_LANG : 'en';
        $labels = require __DIR__ . '/lang/' . (in_array($language, ['en', 'ku', 'ar'], true) ? $language : 'en') . '.php';
        return $this->view(__DIR__ . '/table.html', ['products' => $result['data'], 'search' => $this->search,
            'page' => $this->page, 'last' => $last, 'total' => $result['pagination']['items_count'], 'labels' => $labels]);
    }
}
