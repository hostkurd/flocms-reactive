<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Tests\Unit;
use FloCMS\Core\{Config, Csrf, HttpException};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Attributes\State, Attributes\Action, Component, Kernel, Registry, Snapshot, StateSchema};
use PHPUnit\Framework\TestCase;

final class LargeDraft extends Component
{
    #[State(maxBytes: 65536)] public string $draft = '';
    #[State] public string $ordinary = '';
    #[State(writable: false)] public int $saved = 0;
    #[Action] public function save(): void { $this->saved++; }
    public function render(): string { return '<p>' . strlen($this->draft) . ':' . $this->saved . '</p>'; }
}
final class LargeStateTest extends TestCase
{
    private Kernel $kernel;
    protected function setUp(): void
    {
        Config::$settings = []; $_SESSION = ['_csrf_token' => 'large-session'];
        $registry = new Registry(); $registry->register('large', LargeDraft::class);
        $this->kernel = new Kernel($registry, new Snapshot(str_repeat('k', 32)), '/flo-reactive/update');
    }
    private function mount(): string { return $this->kernel->mount('large', 'draft', [], new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/large'], [], [])); }
    private function update(string $html, array $state): \FloCMS\Core\Http\Response
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $html, $match);
        $post = ['_snapshot' => html_entity_decode($match[1], ENT_QUOTES), '_token' => Csrf::token(), '_action' => 'save', 'state' => $state];
        return $this->kernel->update(new Request([], $post, ['REQUEST_METHOD' => 'POST'], [], [], http_build_query($post)));
    }
    public function testUnicodeAndEscapingRoundTripAtTheExplicitByteBoundary(): void
    {
        $draft = str_repeat('ک', 32000) . str_repeat('"', 1536);
        self::assertSame(65536, strlen($draft));
        $response = $this->update($this->mount(), ['draft' => $draft]);
        self::assertSame(200, $response->status()); self::assertStringContainsString('65536:1', $response->body());
        $again = $this->update($response->body(), []);
        self::assertSame(200, $again->status()); self::assertStringContainsString('65536:2', $again->body());
    }
    public function testOversizedUpdatesDoNotRunTheMutationAndDefaultsRemainBounded(): void
    {
        foreach ([['draft' => str_repeat('x', 65537)], ['ordinary' => str_repeat('x', 8193)]] as $state) {
            $response = $this->update($this->mount(), $state);
            self::assertSame(422, $response->status()); self::assertStringContainsString('0:0', $response->body());
        }
    }
    public function testChangedLimitsRejectOldSnapshots(): void
    {
        $schema = new StateSchema(new LargeDraft());
        $this->expectException(HttpException::class);
        $schema->restore(['draft' => str_repeat('x', 65537), 'ordinary' => '', 'saved' => 0]);
    }
    public function testLargePropertiesCannotBePlacedInTheQueryString(): void
    {
        $component = new class extends Component {
            #[State(query: 'draft', maxBytes: 65536)] public string $draft = '';
            public function render(): string { return ''; }
        };
        $this->expectException(\LogicException::class); new StateSchema($component);
    }
    public function testAggregateSnapshotBudgetIsStillBounded(): void
    {
        $this->expectException(\LogicException::class);
        (new Snapshot(str_repeat('k', 32)))->sign(['draft' => str_repeat('x', 262145)], 'session');
    }
}
