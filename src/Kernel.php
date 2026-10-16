<?php
declare(strict_types=1);
namespace FloCMS\Reactive;

use FloCMS\Core\{Auth, Csrf, HttpException, Session, Validator, ValidationException};
use FloCMS\Core\Http\{Request, Response};
use FloCMS\Reactive\Attributes\Action;

final class Kernel
{
    /** Reserved action name for snapshot renewal; never a PHP method name. */
    public const RENEW = '$renew';

    public function __construct(private readonly Registry $registry, private readonly Snapshot $signer, private readonly string $endpoint)
    {
        // Core 2.3.2 defines ValidationException in Validator.php. Load that
        // declaration even for components without State validation rules.
        if (!class_exists(ValidationException::class)) { class_exists(Validator::class); }
    }

    public function mount(string $name, string $key, array $initial, Request $request, bool $admin = false): string
    {
        self::validKey($key);
        $this->guard($admin);
        $component = $this->registry->create($name);
        $schema = new StateSchema($component);
        $values = $schema->values();
        if (array_diff_key($initial, $values) !== []) { throw new \LogicException('Initial state must be explicitly declared.'); }
        $schema->restore(array_replace($values, $initial));
        $component->authorize($request);
        $component->mount();
        // URL restoration is initialization, not a live edit. Preserve the
        // requested search/page pair instead of firing dependent reset hooks.
        $component->setErrors($schema->apply($schema->queryUpdates($key, $request->query()), notify: false));
        $component->authorize($request);
        $context = ['version' => 1, 'name' => $name, 'key' => $key, 'admin' => $admin, 'path' => $request->path(),
            'language' => defined('ACTIVE_LANG') ? (string) ACTIVE_LANG : 'en'];
        return $this->render($component, $schema, $context, $request->query());
    }

    public function update(Request $request): Response
    {
        if (!$request->isMethod('POST')) { return Response::text('Method not allowed.', 405)->header('Allow', 'POST'); }
        if (strlen($request->rawBody()) > 1048576) { throw new HttpException(413); }
        $token = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');
        if (!is_string($token) || !Csrf::validate($token)) { throw new HttpException(419); }
        $snapshot = $request->input('_snapshot');
        if (!is_string($snapshot)) { throw new HttpException(400); }
        $data = $this->signer->verify($snapshot, Csrf::token());
        // Reject malformed metadata before any action can mutate records.
        $query = $request->input('_query', '');
        if (!is_string($query) || strlen($query) > 8192) { throw new HttpException(400); }
        parse_str(ltrim($query, '?'), $parsedQuery);
        if (($data['version'] ?? null) !== 1 || !is_string($data['name'] ?? null) || !is_string($data['key'] ?? null)
            || !is_bool($data['admin'] ?? null) || !is_array($data['state'] ?? null) || !is_string($data['path'] ?? null)
            || !str_starts_with($data['path'], '/') || str_starts_with($data['path'], '//')
            || preg_match('/[\r\n?#\\\\]/', $data['path']) === 1
            || !is_string($data['language'] ?? null)) { throw new HttpException(400); }
        self::validKey($data['key']);
        if (defined('ACTIVE_LANG') && ACTIVE_LANG !== $data['language']) { throw new HttpException(400, 'Component language changed. Reload the page.'); }
        $this->guard($data['admin']);
        $component = $this->registry->create($data['name']);
        $schema = new StateSchema($component);
        if (strlen($request->rawBody()) > $schema->requestLimit()) { throw new HttpException(413); }
        $schema->restore($data['state']);
        $component->authorize($request);
        $updates = $request->input('state', []);
        if (!is_array($updates)) { throw new HttpException(400); }
        $action = $request->input('_action', 'refresh');
        if (!is_string($action)) { throw new HttpException(400); }
        if ($action === self::RENEW) {
            // Keep-alive: re-sign the unchanged state so a long editing session
            // does not expire. No client updates, no actions, no rendering.
            $context = $data;
            $context['state'] = $schema->values();
            unset($context['issued'], $context['session']);
            return Response::json(['snapshot' => $this->signer->sign($context, Csrf::token())])->header('Cache-Control', 'no-store');
        }
        $errors = $schema->apply($updates);
        $component->authorize($request);
        $config = null;
        if ($action !== 'refresh') {
            if (!method_exists($component, $action)) { throw new HttpException(404); }
            $method = new \ReflectionMethod($component, $action);
            $attributes = $method->getAttributes(Action::class);
            if ($attributes === [] || !$method->isPublic() || $method->isStatic() || $method->getNumberOfParameters() !== 0 || $method->isConstructor()) { throw new HttpException(404); }
            $config = $attributes[0]->newInstance();
        }
        // Field rules always apply. validate() runs before validating actions and,
        // unless the component opts out, on plain updates too.
        if ($errors === [] && ($config === null ? $component->validatesOnUpdate() : $config->validate)) { $errors = $component->validate(); }
        $component->setErrors($errors);
        if ($config !== null) {
            if ($config->permission !== null) { Auth::authorize($config->permission); }
            if ($errors === [] || !$config->validate) {
                try { $method->invoke($component); }
                catch (ValidationException $error) {
                    foreach ($error->getErrors() as $field => $messages) {
                        $errors[$field] = implode(' ', (array) $messages);
                    }
                    $component->setErrors($errors);
                }
            }
        }
        $component->authorize($request);
        return Response::html($this->render($component, $schema, $data, $parsedQuery), $errors === [] ? 200 : 422)
            ->header('Cache-Control', 'no-store')->header('Vary', 'HX-Request');
    }

    private function guard(bool $admin): void
    {
        if (!Auth::refresh()) { throw new HttpException(401, 'Your session ended. Reload the page.'); }
        if ($admin && (!Session::get('isloggedin') || !Session::get('admin_access'))) { throw new HttpException(403); }
    }

    private function render(Component $component, StateSchema $schema, array $context, array $query): string
    {
        $body = $component->render();
        $context['state'] = $schema->values();
        unset($context['issued'], $context['session']);
        $snapshot = $this->signer->sign($context, Csrf::token());
        $attributes = ['id' => 'rx-' . $context['key'], 'data-rx-component' => $context['name'],
            'data-rx-snapshot' => $snapshot, 'data-rx-endpoint' => $this->endpoint,
            'data-rx-state' => json_encode($schema->values(true), JSON_THROW_ON_ERROR),
            'data-rx-refresh' => json_encode($component->refreshTargets(), JSON_THROW_ON_ERROR),
            'data-rx-token' => Csrf::token(), 'data-rx-url' => $schema->url($context['path'], $context['key'], $query),
            'data-rx-ttl' => (string) $this->signer->ttl(),
            'data-rx-messages' => json_encode(Messages::all($context['language']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        $html = '<section';
        foreach ($attributes as $name => $value) { $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'; }
        return $html . '>' . $body . '<p class="rx-status" role="status" aria-live="polite" data-rx-status></p></section>';
    }

    private static function validKey(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $key) !== 1) { throw new \InvalidArgumentException('Use a stable component key containing lowercase letters, numbers and hyphens.'); }
    }
}
