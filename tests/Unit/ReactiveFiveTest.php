<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Tests\Unit;

use FloCMS\Core\{Config, Csrf, HttpException, Lang};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Attributes\State, Attributes\Action, Component, Kernel, Messages, Registry, Snapshot};
use PHPUnit\Framework\TestCase;

/** validate() and the counter of how often it ran (session-free, per request). */
class DraftForm extends Component
{
    public static int $validations = 0;
    public static int $discards = 0;
    #[State(rules: 'integer|min:0|max:10')]
    public int $count = 0;
    public function validate(): array { self::$validations++; return $this->count === 4 ? ['count' => 'Four is not allowed.'] : []; }
    #[Action]
    public function save(): void { $this->count = 9; }
    #[Action(validate: false)]
    public function discard(): void { self::$discards++; $this->count = 0; }
    public function render(): string { return '<p data-count>' . $this->count . '</p>' . (isset($this->errors['count']) ? '<p role="alert">' . $this->errors['count'] . '</p>' : ''); }
}
final class QuietDraftForm extends DraftForm
{
    public function validatesOnUpdate(): bool { return false; }
}

/** Reactive 0.5: action validation options, snapshot renewal and runtime messages. */
final class ReactiveFiveTest extends TestCase
{
    private Kernel $kernel;
    private Registry $registry;
    private Snapshot $signer;

    protected function setUp(): void
    {
        $_SESSION = ['_csrf_token' => 'session-one', 'isloggedin' => true, 'user_id' => 1, 'role' => 1, 'admin_access' => true];
        Config::$settings = [];
        $this->registry = new Registry();
        $this->registry->register('draft', DraftForm::class);
        $this->registry->register('quiet', QuietDraftForm::class);
        $this->signer = new Snapshot(str_repeat('k', 32), 600);
        $this->kernel = new Kernel($this->registry, $this->signer, '/flo-reactive/update');
        DraftForm::$validations = 0; DraftForm::$discards = 0;
        self::setLang([]);
    }

    protected function tearDown(): void { self::setLang([]); }

    private static function setLang(array $data): void
    {
        $property = new \ReflectionProperty(Lang::class, 'data');
        $property->setAccessible(true);
        $property->setValue(null, $data);
    }

    private function mounted(string $name = 'draft'): string
    {
        return $this->kernel->mount($name, 'one', [], new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/form'], [], []));
    }

    private static function snapshot(string $html): string
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $html, $match);
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }

    private function update(array $post, string $html): \FloCMS\Core\Http\Response
    {
        return $this->kernel->update(new Request([], $post + ['_snapshot' => self::snapshot($html), '_token' => Csrf::token()], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/flo-reactive/update'], [], []));
    }

    public function testValidateRunsOnUpdatesByDefaultAsBefore(): void
    {
        $response = $this->update(['state' => ['count' => '4']], $this->mounted());
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Four is not allowed.', $response->body());
        self::assertSame(1, DraftForm::$validations);
    }

    public function testComponentsCanValidateOnlyBeforeActions(): void
    {
        $html = $this->mounted('quiet');
        $typing = $this->update(['state' => ['count' => '4']], $html);
        self::assertSame(200, $typing->status());
        self::assertStringNotContainsString('role="alert"', $typing->body());
        self::assertSame(0, DraftForm::$validations);
        $save = $this->update(['state' => ['count' => '4'], '_action' => 'save'], $html);
        self::assertSame(422, $save->status());
        self::assertStringContainsString('<p data-count>4</p>', $save->body());
        self::assertSame(1, DraftForm::$validations);
        // Field rules are not part of validate() and still apply while typing.
        self::assertSame(422, $this->update(['state' => ['count' => '99']], $html)->status());
    }

    public function testActionsWithoutValidationRunDespiteErrors(): void
    {
        $html = $this->mounted();
        $response = $this->update(['state' => ['count' => '4'], '_action' => 'discard'], $html);
        self::assertSame(1, DraftForm::$discards);
        self::assertSame(0, DraftForm::$validations);
        self::assertStringContainsString('<p data-count>0</p>', $response->body());
        // A value rejected by its field rule is not applied, and Discard still runs.
        $rejected = $this->update(['state' => ['count' => '99'], '_action' => 'discard'], $html);
        self::assertSame(2, DraftForm::$discards);
        self::assertSame(422, $rejected->status());
    }

    public function testRenewalReSignsTheSameStateWithoutRendering(): void
    {
        $html = $this->update(['state' => ['count' => '3']], $this->mounted())->body();
        $old = self::snapshot($html);
        $issued = $this->signer->verify($old, Csrf::token())['issued'];
        sleep(1);
        DraftForm::$validations = 0;
        $response = $this->update(['_action' => Kernel::RENEW, 'state' => ['count' => '7']], $html);
        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        $snapshot = json_decode($response->body(), true, 4, JSON_THROW_ON_ERROR)['snapshot'];
        $data = $this->signer->verify($snapshot, Csrf::token());
        self::assertSame(['count' => 3], $data['state']);
        self::assertGreaterThan($issued, $data['issued']);
        self::assertSame(['version', 'name', 'key', 'admin', 'path', 'language', 'state', 'issued', 'session'], array_keys($data));
        self::assertSame(0, DraftForm::$validations);
    }

    public function testRenewalNeedsAValidUnexpiredSnapshotAndCsrf(): void
    {
        $html = $this->mounted();
        $expired = $this->signer->sign(['version' => 1, 'name' => 'draft', 'key' => 'one', 'admin' => false, 'path' => '/form', 'language' => 'en', 'state' => ['count' => 0]], Csrf::token(), time() - 601);
        foreach ([
            [419, ['_snapshot' => $expired, '_token' => Csrf::token()]],
            [419, ['_snapshot' => self::snapshot($html), '_token' => 'forged']],
            [400, ['_snapshot' => self::snapshot($html) . 'x', '_token' => Csrf::token()]],
        ] as [$status, $post]) {
            try {
                $this->kernel->update(new Request([], $post + ['_action' => Kernel::RENEW], ['REQUEST_METHOD' => 'POST'], [], []));
                self::fail('Renewed an invalid snapshot');
            } catch (HttpException $error) { self::assertSame($status, $error->status); }
        }
    }

    public function testRuntimeReceivesTtlAndMessagesInThePageLanguage(): void
    {
        $html = $this->mounted();
        self::assertStringContainsString('data-rx-ttl="600"', $html);
        preg_match('/data-rx-messages="([^"]+)"/', $html, $match);
        $messages = json_decode(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame(['session', 'forbidden', 'missing', 'changed', 'expired', 'failed', 'invalid'], array_keys($messages));
        self::assertSame('This page expired. Reload the page.', $messages['expired']);
        foreach (['ar', 'ku'] as $language) {
            $local = Messages::all($language);
            self::assertSame(array_keys($messages), array_keys($local));
            foreach ($local as $key => $line) { self::assertNotSame($messages[$key], $line, "$language.$key is untranslated"); }
        }
        self::assertSame($messages, Messages::all('el'));
        self::assertSame($messages, Messages::all('../en'));
    }

    public function testSitesCanOverrideRuntimeMessages(): void
    {
        self::setLang(['reactive.expired' => 'Session timed out — please reload.']);
        $messages = Messages::all('ku');
        self::assertSame('Session timed out — please reload.', $messages['expired']);
        self::assertSame(Messages::all('ku')['failed'], $messages['failed']);
        self::assertStringContainsString('Session timed out', $this->mounted());
    }
}
