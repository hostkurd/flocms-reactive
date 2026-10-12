<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Core\{App, Config, Env};
use FloCMS\Core\Http\Request;

final class Reactive
{
    private static ?Registry $registry = null;
    private static bool $booted = false;
    private static bool $configured = false;
    private static array $mounted = [];

    public static function boot(): void
    {
        if (self::$booted) { return; }
        self::$booted = true;
        App::resolveControllersUsing(static fn (string $controller, string $route): ?string =>
            $controller === 'flo-reactive' && $route === 'default' ? Http\ReactiveController::class : null);
    }

    public static function register(string $name, string $class): void { self::registry()->register($name, $class); }

    public static function registry(): Registry
    {
        self::$registry ??= new Registry();
        if (!self::$configured) {
            self::$configured = true;
            $root = defined('ROOT') ? ROOT : getcwd();
            $file = $root . '/config/reactive.php';
            if (is_file($file)) {
                $components = require $file;
                if (!is_array($components)) { throw new \LogicException('config/reactive.php must return component name => class.'); }
                foreach ($components as $name => $class) { self::$registry->register($name, $class); }
            }
        }
        return self::$registry;
    }

    public static function kernel(): Kernel
    {
        $key = (string) Config::get('reactive.key', Env::get('APP_KEY', ''));
        if (str_starts_with($key, 'base64:')) { $key = base64_decode(substr($key, 7), true) ?: ''; }
        return new Kernel(self::registry(), new Snapshot($key, (int) Config::get('reactive.ttl', 1800)), self::url('update'));
    }

    public static function mount(string $name, string $key, array $initial = []): string
    {
        if (isset(self::$mounted[$key])) { throw new \LogicException('Component keys must be unique on a page: ' . $key); }
        self::$mounted[$key] = true;
        return self::kernel()->mount($name, $key, $initial, Request::fromGlobals(), App::hasRouter() && App::getRouter()->getRoute() === 'admin');
    }

    public static function assets(): string
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/dist/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        $asset = $manifest['resources/js/reactive.ts'];
        $escape = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '';
        $styles = array_unique(array_merge($asset['css'] ?? [], isset($manifest['style.css']) ? [$manifest['style.css']['file']] : []));
        foreach ($styles as $css) { $html .= '<link rel="stylesheet" href="' . $escape(self::url('asset/' . basename($css))) . '">'; }
        return $html . '<script defer src="' . $escape(self::url('asset/' . basename($asset['file']))) . '"></script>';
    }

    public static function url(string $path): string
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        return $base . '/' . $lang . 'flo-reactive/' . $path;
    }

    public static function reset(): void { self::$registry = null; self::$configured = false; self::$mounted = []; }
}
