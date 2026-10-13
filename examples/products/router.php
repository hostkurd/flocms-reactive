<?php
declare(strict_types=1);

// Standalone development example: no credentials or production database.
// Run: php -S 127.0.0.1:8080 examples/products/router.php
$package = dirname(__DIR__, 2);
require $package . '/vendor/autoload.php';
require __DIR__ . '/ProductTable.php';
require dirname(__DIR__) . '/forms/LocationForm.php';

use FloCMS\Core\{App, Config, Csrf, Database, HttpException, Session};
use FloCMS\Core\Http\Response;
use FloCMS\Reactive\Reactive;

define('ROOT', __DIR__ . '/site');
define('DS', DIRECTORY_SEPARATOR);
define('VIEWS_PATH', ROOT . '/views');
define('TEMPLATES_PATH', ROOT . '/templates');
define('SITE_URI', '');
session_start();
// PHP's development router reports requested URLs as SCRIPT_NAME. FloCMS
// expects the front-controller script path, as it receives under rewrites.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_ENV['DEFAULT_LANG'] = 'en';
Config::set('routes', ['default' => '', 'admin' => 'admin_']);
Config::set('default_route', 'default');
Config::set('default_controller', 'demo');
Config::set('default_action', 'index');
Config::set('languages', ['en', 'ar', 'ku']);
Config::set('default_language', 'en');
Config::set('reactive.key', hash('sha256', __FILE__)); // Example only: real apps use APP_KEY.
Config::set('permissions', [1 => ['catalog.view', 'catalog.edit', 'locations.view', 'locations.edit'], 2 => ['catalog.view', 'locations.view'], 0 => []]);
Config::set('auth.user_loader', static fn (int $id): array => ['role' => Session::get('demo_role', 1), 'status' => 1]);
Config::set('admin_access_roles', [1]);
if (!Session::get('demo_initialized')) {
    Session::set('demo_initialized', true);
    Session::set('demo_role', 1);
    Session::set('isloggedin', true);
    Session::set('admin_access', true);
    Session::set('user_id', 1);
    Session::set('role', 1);
}
Reactive::register('products', \FloCMS\Reactive\Examples\ProductTable::class);
Reactive::register('location', \FloCMS\Reactive\Examples\LocationForm::class);
// A private temporary file per demo session makes saves survive HTTP requests.
// Real applications use their configured database, migrations, and real login.
$storage = sys_get_temp_dir() . '/flocms-reactive-demo-v0.3';
if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) { throw new RuntimeException('Cannot create demo storage.'); }
$database = $storage . '/' . hash('sha256', $package . session_id()) . '.sqlite';
App::$db = new Database(new PDO('sqlite:' . $database));
App::$db->query('PRAGMA foreign_keys = ON');
$seedProducts = (int) App::$db->pdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'products'")->fetchColumn() === 0;
(require __DIR__ . '/schema.php')(App::$db);
if ($seedProducts) {
    foreach (['Laptop', 'Keyboard', 'Mouse', 'Monitor', 'Desk', 'Chair', 'Camera', 'Microphone', 'Tablet', 'Phone', 'فیلا', 'ڕۆشنایی'] as $index => $name) {
        App::$db->table('products')->insert(['id'=>$index + 1, 'name'=>$name, 'price_cents'=>($index + 1) * 2500, 'featured'=>$index % 2, 'owner_id'=>$index === 11 ? 2 : 1]);
    }
}
(require dirname(__DIR__) . '/forms/schema.php')(App::$db);
final class DemoController extends \FloCMS\Core\Controller
{
    public function index(): Response
    {
        $language = App::getRouter()->getLanguage();
        $dir = in_array($language, ['ku', 'ar'], true) ? 'rtl' : 'ltr';
        $labels = require __DIR__ . '/lang/' . $language . '.php';
        $body = '<!doctype html><html lang="' . $language . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>FloCMS Reactive — Products</title>' . Reactive::assets() . '<link rel="stylesheet" href="/demo/style"></head><body>'
            . '<h1>FloCMS Reactive</h1><p>Products · بەرهەم · المنتجات</p><nav><a href="/en/demo">English</a> · <a href="/ku/demo">کوردی</a> · <a href="/ar/demo">العربية</a></nav>'
            . '<p><a href="/' . $language . '/demo/form">' . $labels['form_link'] . '</a></p>'
            . '<h2>' . $labels['catalog'] . '</h2>' . Reactive::mount('products', 'catalog') . '<h2>' . $labels['featured'] . '</h2>' . Reactive::mount('products', 'featured', ['featuredOnly' => true, 'instanceKey'=>'featured'])
            . '</body></html>';
        return Response::html($body)->header('Cache-Control', 'no-store');
    }
    public function form(): Response
    {
        $language = App::getRouter()->getLanguage();
        $dir = in_array($language, ['ku', 'ar'], true) ? 'rtl' : 'ltr';
        $labels = require dirname(__DIR__) . '/forms/lang/' . $language . '.php';
        return Response::html('<!doctype html><html lang="' . $language . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>FloCMS Reactive — ' . $labels['title'] . '</title>' . Reactive::assets() . '<link rel="stylesheet" href="/demo/style"></head><body>'
            . '<h1>' . $labels['title'] . '</h1><nav><a href="/en/demo/form">English</a> · <a href="/ku/demo/form">کوردی</a> · <a href="/ar/demo/form">العربية</a></nav>'
            . Reactive::mount('location', 'address') . '<a href="/' . $language . '/demo">' . $labels['products_link'] . '</a></body></html>')->header('Cache-Control', 'no-store');
    }
    public function style(): Response
    {
        return Response::text('body{font:16px/1.6 system-ui;margin:2rem auto;padding:1rem;max-width:850px;color:#243044}section{padding:1.5rem;border:1px solid #d8dee8;border-radius:12px;margin-block:1rem}.table-scroll{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:.6rem;border-block-end:1px solid #e1e6ef}input,select,button{font:inherit;padding:.4rem;margin:.25rem;box-sizing:border-box}input,select{max-width:100%}button{cursor:pointer}nav{margin-block:1rem}a{color:#146b87}.form-field{margin-block:1rem}.form-field label{display:block;font-weight:600}.form-field input,.form-field select{width:100%;margin-inline:0}.form-field p{font-size:.9rem;margin-block:.3rem}.field-error{color:#9c2424}.field-error:empty{display:none}[aria-invalid=true]{border:2px solid #9c2424}button:disabled{opacity:.6}input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #146b87;outline-offset:3px}[data-saved],[data-notice]{color:#166534}@media(max-width:480px){section{padding:1rem}}')
            ->header('Content-Type', 'text/css; charset=utf-8');
    }
    public function permission(): Response
    {
        // Browser regression fixture; only active with an explicit local test flag.
        if (getenv('FLO_REACTIVE_TESTING') !== '1') { throw new HttpException(404); }
        if (!$this->request->isMethod('POST')) { throw new HttpException(405); }
        Session::set('demo_role', 0);
        return Response::noContent();
    }
    public function expire(): Response
    {
        if (getenv('FLO_REACTIVE_TESTING') !== '1') { throw new HttpException(404); }
        if (!$this->request->isMethod('POST')) { throw new HttpException(405); }
        Csrf::rotate();
        return Response::noContent();
    }
    public function readonly(): Response
    {
        if (getenv('FLO_REACTIVE_TESTING') !== '1') { throw new HttpException(404); }
        if (!$this->request->isMethod('POST')) { throw new HttpException(405); }
        Session::set('demo_role', 2);
        return Response::noContent();
    }
    public function mutate(): Response
    {
        // Per-session browser fixtures only; these are not production routes.
        if (getenv('FLO_REACTIVE_TESTING') !== '1') { throw new HttpException(404); }
        if (!$this->request->isMethod('POST')) { throw new HttpException(405); }
        $id = filter_var($this->request->input('id'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || $id > 12) { throw new HttpException(400); }
        match ($this->request->input('change')) {
            'conflict' => App::db()->query('UPDATE products SET name = ?, version = version + 1 WHERE id = ?', ['Changed elsewhere', $id]),
            'delete' => App::db()->query('DELETE FROM products WHERE id = ?', [$id]),
            'owner' => App::db()->query('UPDATE products SET owner_id = 2 WHERE id = ?', [$id]),
            'scope' => App::db()->query('UPDATE products SET featured = 0 WHERE id = ?', [$id]),
            default => throw new HttpException(400),
        };
        return Response::noContent();
    }
}
App::resolveControllersUsing(static fn (string $name, string $route): ?string => $name === 'demo' && $route === 'default' ? DemoController::class : null);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
try { App::run($_SERVER['REQUEST_URI']); }
catch (HttpException $e) { Response::text($e->getMessage(), $e->status)->header('Cache-Control', 'no-store')->send(); }
