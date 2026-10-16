<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Core\Lang;

/**
 * Browser runtime messages in the page language. The package ships en, ar and
 * ku; other languages fall back to English. A site overrides a line with the
 * key "reactive.<name>" (for example "reactive.expired") in its lang files.
 *
 * @internal
 */
final class Messages
{
    /** @var array<string, array<string, string>> */
    private static array $files = [];

    /** @return array<string, string> */
    public static function all(string $language): array
    {
        $english = self::file('en');
        $local = preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D', $language) === 1 ? self::file($language) : [];
        $messages = [];
        foreach ($english as $key => $line) {
            $site = class_exists(Lang::class) ? Lang::get('reactive.' . $key, '') : '';
            $messages[$key] = is_string($site) && $site !== '' && $site !== 'reactive.' . $key ? $site : ($local[$key] ?? $line);
        }
        return $messages;
    }

    /** @return array<string, string> */
    private static function file(string $language): array
    {
        if (!isset(self::$files[$language])) {
            $path = dirname(__DIR__) . '/resources/lang/' . $language . '.php';
            $data = is_file($path) ? require $path : [];
            self::$files[$language] = is_array($data) ? array_filter($data, 'is_string') : [];
        }
        return self::$files[$language];
    }
}
