<?php
declare(strict_types=1);

// Standalone development example: no credentials or production database.
// Run: php -S 127.0.0.1:8080 examples/products/router.php
$package = dirname(__DIR__, 2);
require $package . '/vendor/autoload.php';
require __DIR__ . '/ProductTable.php';

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
Config::set('permissions', [1 => ['catalog.view'], 0 => []]);
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
App::$db = new Database(new PDO('sqlite::memory:'));
App::$db->query('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price NUMERIC, featured INTEGER)');
foreach (['Laptop', 'Keyboard', 'Mouse', 'Monitor', 'Desk', 'Chair', 'Camera', 'Microphone', 'Tablet', 'Phone', 'فیلا', 'ڕۆشنایی'] as $index => $name) {
    App::$db->table('products')->insert(['id' => $index + 1, 'name' => $name, 'price' => ($index + 1) * 25, 'featured' => $index % 2]);
}
final class DemoController extends \FloCMS\Core\Controller
{
    public function index(): Response
    {
        $language = App::getRouter()->getLanguage();
        $dir = in_array($language, ['ku', 'ar'], true) ? 'rtl' : 'ltr';
        $body = '<!doctype html><html lang="' . $language . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>FloCMS Reactive — Products</title>' . Reactive::assets() . '<link rel="stylesheet" href="/demo/style"></head><body>'
            . '<h1>FloCMS Reactive</h1><p>Products · بەرهەم · المنتجات</p><nav><a href="/en/demo">English</a> · <a href="/ku/demo">کوردی</a> · <a href="/ar/demo">العربية</a></nav>'
            . '<h2>Catalog</h2>' . Reactive::mount('products', 'catalog') . '<h2>Featured</h2>' . Reactive::mount('products', 'featured', ['featuredOnly' => true])
            . '</body></html>';
        return Response::html($body)->header('Cache-Control', 'no-store');
    }
    public function style(): Response
    {
        return Response::text('body{font:16px/1.6 system-ui;margin:2rem auto;padding:1rem;max-width:850px;color:#243044}section{padding:1.5rem;border:1px solid #d8dee8;border-radius:12px;margin-block:1rem}table{width:100%;border-collapse:collapse}th,td{text-align:start;padding:.6rem;border-block-end:1px solid #e1e6ef}input,button{font:inherit;padding:.4rem;margin:.25rem}input{max-width:100%}button{cursor:pointer}nav{margin-block:1rem}a{color:#146b87}')
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
}
App::resolveControllersUsing(static fn (string $name, string $route): ?string => $name === 'demo' && $route === 'default' ? DemoController::class : null);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
try { App::run($_SERVER['REQUEST_URI']); }
catch (HttpException $e) { Response::text($e->getMessage(), $e->status)->header('Cache-Control', 'no-store')->send(); }
