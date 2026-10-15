<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Tests\Unit;

use FloCMS\Core\{Auth, Config, Csrf, HttpException};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Attributes\State, Attributes\Action, Component, Kernel, Registry, Snapshot, StateSchema};
use PHPUnit\Framework\TestCase;

class Counter extends Component
{
    #[State(rules: 'integer|min:0|max:10', query: 'count')]
    public int $count = 0;
    #[State(writable: false)]
    public int $recordId = 7;
    protected string $secret = 'must-never-leave-the-server';
    public function authorize(Request $request): void { Auth::authorize('counter.view'); }
    #[Action(permission: 'counter.edit')]
    public function increment(): void { $this->count++; }
    public function dangerous(): void { $this->count = 999; }
    #[Action]
    protected function hidden(): void {}
    public function render(): string { return '<p data-count>' . $this->count . '</p>' . ($this->errors === [] ? '' : '<p role="alert">invalid</p>'); }
}
final class RequiredCounter extends Counter
{
    public function validate(): array { return $this->count === 4 ? ['count' => 'Four is not allowed.'] : []; }
}
final class ScopedCounter extends Counter
{
    public function authorize(Request $request): void
    {
        parent::authorize($request);
        if ($this->recordId !== 20) { throw new HttpException(403); }
    }
}

final class KernelTest extends TestCase
{
    private Kernel $kernel;
    private Registry $registry;
    private Snapshot $signer;

    protected function setUp(): void
    {
        $_SESSION = ['_csrf_token' => 'session-one', 'isloggedin' => true, 'user_id' => 1, 'role' => 1, 'admin_access' => true];
        Config::$settings = [];
        Config::set('permissions', [1 => ['counter.view', 'counter.edit'], 0 => []]);
        $this->registry = new Registry();
        $this->registry->register('counter', Counter::class);
        $this->registry->register('required', RequiredCounter::class);
        $this->signer = new Snapshot(str_repeat('k', 32));
        $this->kernel = new Kernel($this->registry, $this->signer, '/flo-reactive/update');
    }

    private function request(array $post = [], array $query = [], string $method = 'POST', string $body = ''): Request
    {
        return new Request($query, $post, ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/products'], [], [], $body);
    }

    private function mounted(string $name = 'counter', string $key = 'one', bool $admin = false, array $initial = [], array $query = []): string
    {
        return $this->kernel->mount($name, $key, $initial, $this->request([], $query, 'GET'), $admin);
    }

    private function snapshot(string $html): string
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $html, $match);
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }

    private function update(array $post = [], ?string $html = null): \FloCMS\Core\Http\Response
    {
        return $this->kernel->update($this->request($post + ['_snapshot' => $this->snapshot($html ?? $this->mounted()), '_token' => Csrf::token()]));
    }

    private function fails(int $status, callable $callback): void
    {
        try { $callback(); self::fail('Expected HTTP ' . $status); }
        catch (HttpException $error) { self::assertSame($status, $error->status); }
    }

    public function testInitialRenderContainsDeclaredStateOnly(): void
    {
        $html = $this->mounted(initial: ['count' => 2]);
        self::assertStringContainsString('<p data-count>2</p>', $html);
        $data = $this->signer->verify($this->snapshot($html), Csrf::token());
        self::assertSame(['count' => 2, 'recordId' => 7], $data['state']);
        self::assertStringNotContainsString('must-never-leave-the-server', $html);
    }

    public function testInitialAuthorizationUsesTrustedRecordContextBeforeMounting(): void
    {
        $this->registry->register('scoped', ScopedCounter::class);
        self::assertStringContainsString('data-rx-component="scoped"', $this->mounted('scoped', initial: ['recordId' => 20]));
        $this->fails(403, fn () => $this->mounted('scoped', initial: ['recordId' => 21]));
    }

    public function testValidUpdateAndExplicitAction(): void
    {
        $response = $this->update(['state' => ['count' => '2'], '_action' => 'increment']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('<p data-count>3</p>', $response->body());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    public function testInvalidStateDoesNotExecuteAnAction(): void
    {
        $response = $this->update(['state' => ['count' => '99'], '_action' => 'increment']);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('<p data-count>0</p>', $response->body());
    }

    public function testMalformedScalarTypesAreRejected(): void
    {
        foreach (['1abc', ['nested'], '9999999999999999999999'] as $value) {
            self::assertSame(422, $this->update(['state' => ['count' => $value]])->status());
        }
    }

    public function testCustomValidationStopsActions(): void
    {
        $response = $this->update(['state' => ['count' => '4'], '_action' => 'increment'], $this->mounted('required'));
        self::assertSame(422, $response->status());
        self::assertStringContainsString('<p data-count>4</p>', $response->body());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testActionCanThrowValidationWithoutPreviouslyLoadingCoreValidator(): void
    {
        $component = new class extends Component {
            #[State]
            public string $name = 'Draft';
            #[Action]
            public function save(): void { throw new \FloCMS\Core\ValidationException(['name'=>['Required.', 'Try again.']]); }
            public function render(): string { return $this->name . ' ' . ($this->errors['name'] ?? ''); }
        };
        $this->registry->register('draft', $component::class);
        $response = $this->update(['_action'=>'save'], $this->mounted('draft'));
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Draft Required. Try again.', $response->body());
    }

    public function testLockedAndUnknownPropertiesAreRejected(): void
    {
        foreach (['recordId', 'role', 'secret'] as $property) {
            $this->fails(400, fn () => $this->update(['state' => [$property => '2']]));
        }
    }

    public function testUnexposedAndLifecycleMethodsCannotBeInvoked(): void
    {
        foreach (['dangerous', 'hidden', 'render', 'mount', 'authorize', 'refreshTargets', '__construct', 'missing'] as $method) {
            $this->fails(404, fn () => $this->update(['_action' => $method]));
        }
    }

    public function testTamperedSnapshotsAreRejected(): void
    {
        $snapshot = $this->snapshot($this->mounted());
        $this->fails(400, fn () => $this->update(['_snapshot' => 'X' . substr($snapshot, 1)]));
    }

    public function testMissingAndArrayCsrfAreRejected(): void
    {
        foreach (['', ['bad']] as $token) {
            $this->fails(419, fn () => $this->update(['_token' => $token]));
        }
    }

    public function testSnapshotCannotMoveToAnotherSession(): void
    {
        $html = $this->mounted();
        $_SESSION['_csrf_token'] = 'session-two';
        $this->fails(419, fn () => $this->update([], $html));
    }

    public function testActionPermissionIsCheckedSeparately(): void
    {
        Config::set('permissions', [1 => ['counter.view']]);
        $this->fails(403, fn () => $this->update(['_action' => 'increment']));
    }

    public function testPermissionChangesAreReloadedAfterMount(): void
    {
        $html = $this->mounted();
        Config::set('auth.user_loader', static fn (): array => ['role' => 0, 'status' => 1]);
        $this->fails(403, fn () => $this->update([], $html));
    }

    public function testExpiredAuthenticationCannotUpdate(): void
    {
        $html = $this->mounted();
        Config::set('auth.user_loader', static fn (): array => ['role' => 1, 'status' => 3]);
        $this->fails(401, fn () => $this->update([], $html));
    }

    public function testAdminContextIsPreservedOnPublicUpdateEndpoint(): void
    {
        $html = $this->mounted(admin: true);
        $_SESSION['admin_access'] = false;
        $this->fails(403, fn () => $this->update([], $html));
    }

    public function testQueryStateIsScopedByComponentKey(): void
    {
        $html = $this->mounted(query: ['one_count' => '3', 'two_count' => '5']);
        self::assertStringContainsString('<p data-count>3</p>', $html);
        self::assertStringContainsString('two_count=5', $html);
        self::assertStringContainsString('<p data-count>5</p>', $this->mounted(key: 'two', query: ['one_count' => '3', 'two_count' => '5']));
    }

    public function testGetRequestsAndOversizedPayloadsAreRejected(): void
    {
        self::assertSame(405, $this->kernel->update($this->request(method: 'GET'))->status());
        $this->fails(413, fn () => $this->kernel->update($this->request(body: str_repeat('x', 1048577))));
        $this->fails(413, fn () => $this->kernel->update($this->request(['_snapshot' => $this->snapshot($this->mounted()), '_token' => Csrf::token()], body: str_repeat('x', 65537))));
    }

    public function testUnknownComponentsAreNotInstantiated(): void
    {
        $this->fails(404, fn () => $this->mounted('arbitrary-class'));
    }

    public function testSnapshotsExpireAndCannotBeIssuedInTheFuture(): void
    {
        foreach ([1, 4000] as $issued) {
            $token = $this->signer->sign([], 'session', $issued);
            $this->fails(419, fn () => $this->signer->verify($token, 'session', 2000));
        }
    }

    public function testRegistryRejectsDuplicateNamesAndNonComponents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register('counter', RequiredCounter::class);
    }

    public function testRegistryRequiresAZeroArgumentConstructor(): void
    {
        $component = new class('initial') extends Component {
            public function __construct(string $argument) {}
            public function render(): string { return ''; }
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register('invalid', $component::class);
    }

    public function testOldSnapshotWithChangedStateTypeReturns409(): void
    {
        $this->fails(409, fn () => (new StateSchema(new Counter()))->restore(['count' => 'old-string-type', 'recordId' => 7]));
    }

    public function testStateSupportsBooleanAndFloatWithoutLooseCoercion(): void
    {
        $component = new class extends Component {
            #[State] public bool $enabled = false;
            #[State] public float $price = 1.5;
            public function render(): string { return ''; }
        };
        $schema = new StateSchema($component);
        self::assertSame([], $schema->apply(['enabled' => '1', 'price' => '12.25']));
        self::assertTrue($component->enabled);
        self::assertSame(12.25, $component->price);
        self::assertArrayHasKey('enabled', $schema->apply(['enabled' => 'false']));
    }

    public function testUndeclaredObjectsAreNotSerialized(): void
    {
        $component = new class extends Component {
            public object $connection;
            public function __construct() { $this->connection = new \stdClass(); }
            public function render(): string { return ''; }
        };
        self::assertSame([], (new StateSchema($component))->values());
    }
}
