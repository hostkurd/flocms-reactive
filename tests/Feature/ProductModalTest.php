<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Tests\Feature;

use FloCMS\Core\{App, Config, Csrf, Database, HttpException};
use FloCMS\Core\Http\{Request, Response};
use FloCMS\Reactive\{Kernel, Registry, Snapshot};
use FloCMS\Reactive\Examples\ProductTable;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/examples/products/ProductTable.php';
if (!defined('ROOT')) { define('ROOT', dirname(__DIR__, 2) . '/examples/products/site'); }
if (!defined('VIEWS_PATH')) { define('VIEWS_PATH', ROOT . '/views'); }
if (!defined('TEMPLATES_PATH')) { define('TEMPLATES_PATH', ROOT . '/templates'); }
if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }

final class ProductModalTest extends TestCase
{
    private Kernel $kernel;
    private Snapshot $signer;

    protected function setUp(): void
    {
        Config::$settings = [];
        $_SESSION = ['_csrf_token'=>'tok', 'isloggedin'=>true, 'user_id'=>1, 'role'=>1];
        Config::set('permissions', [1=>['catalog.view','catalog.edit'], 2=>['catalog.view']]);
        App::$db = new Database(new \PDO('sqlite::memory:'));
        (require dirname(__DIR__, 2) . '/examples/products/schema.php')(App::$db);
        for ($id=1; $id<=12; $id++) {
            App::db()->table('products')->insert(['id'=>$id,'name'=>'Product '.$id,'price_cents'=>$id*2500,'featured'=>($id+1)%2,'owner_id'=>$id===3?2:1]);
        }
        $registry = new Registry();
        $registry->register('products', ProductTable::class);
        $this->signer = new Snapshot(str_repeat('k',32));
        $this->kernel = new Kernel($registry, $this->signer, '/flo-reactive/update');
    }

    private function attribute(string $html, string $name): string
    {
        preg_match('/' . preg_quote($name, '/') . '="([^"]*)"/', $html, $match);
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }

    private function mount(bool $featured = false, array $query = []): string
    {
        return $this->kernel->mount('products', $featured?'featured':'catalog', ['featuredOnly'=>$featured,'instanceKey'=>$featured?'featured':'catalog'], new Request($query,[],['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/products'],[],[]));
    }

    private function update(string $html, string $action, array $state = [], array $extra = []): Response
    {
        return $this->kernel->update(new Request([], $extra + ['_token'=>Csrf::token(), '_snapshot'=>$this->attribute($html,'data-rx-snapshot'), 'state'=>$state, '_action'=>$action], ['REQUEST_METHOD'=>'POST'], [], []));
    }

    private function open(int $id=2, bool $featured=false, array $query=[]): string
    {
        return $this->update($this->mount($featured,$query), 'edit', ['selectedId'=>(string)$id])->body();
    }

    private function record(int $id=2): object
    {
        return App::db()->table('products')->where('id','=', $id)->first();
    }

    private function state(string $html): array
    {
        return $this->signer->verify($this->attribute($html,'data-rx-snapshot'), Csrf::token())['state'];
    }

    private function denied(callable $callback, int $status=403): void
    {
        try { $callback(); self::fail('Expected HTTP '.$status); }
        catch (HttpException $error) { self::assertSame($status,$error->status); }
    }

    public function testOpeningReloadsCurrentRecordAndSealsIdentityAndVersion(): void
    {
        $html = $this->mount();
        App::db()->query('UPDATE products SET name = ?, version = 8 WHERE id = 2', ['Fresh name']);
        $response = $this->update($html,'edit',['selectedId'=>'2','editName'=>'Forged draft','editPrice'=>'1']);
        self::assertSame(200,$response->status());
        $state = $this->state($response->body());
        self::assertSame(2,$state['editingId']);
        self::assertSame(8,$state['editingVersion']);
        self::assertSame('Fresh name',$state['editName']);
        self::assertSame('50.00',$state['editPrice']);
        self::assertTrue($state['editorOpen']);
    }

    public function testSaveUpdatesTheTablePreservesSearchAndPageAndEmitsOneRefreshEffect(): void
    {
        $html = $this->open(6, query:['catalog_q'=>'Product','catalog_page'=>'2']);
        $response = $this->update($html,'save',['editName'=>'Product edited','editPrice'=>'123.45']);
        self::assertSame(200,$response->status());
        self::assertSame(12345,$this->record(6)->price_cents);
        self::assertSame(2,$this->record(6)->version);
        self::assertStringContainsString('Product edited',$response->body());
        self::assertStringContainsString('2 / 3',$response->body());
        self::assertStringContainsString('Product changes saved.',$response->body());
        self::assertFalse($this->state($response->body())['editorOpen']);
        self::assertSame('Product', $this->state($response->body())['search']);
        self::assertSame(['catalog','featured'],json_decode($this->attribute($response->body(),'data-rx-refresh'),true));
        self::assertArrayNotHasKey('refreshTargets',$this->state($response->body()));
        self::assertSame('[]',$this->attribute($this->update($response->body(),'refresh')->body(),'data-rx-refresh'));
    }

    public function testDecimalPricesAreStoredWithoutFloatRounding(): void
    {
        foreach (['0'=>0,'0.01'=>1,'1.2'=>120,'999999.99'=>99999999] as $price=>$cents) {
            $response=$this->update($this->open(),'save',['editName'=>'Product','editPrice'=>(string)$price]);
            self::assertSame(200,$response->status());
            self::assertSame($cents,$this->record()->price_cents);
        }
    }

    public function testValidationKeepsTheModalAndEscapedDraftOpenWithoutWriting(): void
    {
        $name='<script>'.str_repeat('x',101);
        $response=$this->update($this->open(),'save',['editName'=>$name,'editPrice'=>'1.234']);
        self::assertSame(422,$response->status());
        self::assertTrue($this->state($response->body())['editorOpen']);
        self::assertSame($name,$this->state($response->body())['editName']);
        self::assertStringContainsString('&lt;script&gt;',$response->body());
        self::assertStringNotContainsString('<script>',$response->body());
        self::assertStringContainsString('Use no more than 100 characters.',$response->body());
        self::assertStringContainsString('two decimal places',$response->body());
        self::assertSame('Product 2',$this->record()->name);
        self::assertSame(1,$this->record()->version);
        self::assertSame('[]',$this->attribute($response->body(),'data-rx-refresh'));
        self::assertSame(200,$this->update($response->body(),'save',['editName'=>'Valid','editPrice'=>'12.34'])->status());
    }

    public function testRequiredAndMalformedPricesNeverSave(): void
    {
        foreach (['','-1','1e3','NaN','0001','1000000'] as $price) {
            self::assertSame(422,$this->update($this->open(),'save',['editName'=>'','editPrice'=>$price])->status());
            self::assertSame(1,$this->record()->version);
        }
    }

    public function testCancelDiscardsInvalidDraftWithoutSaving(): void
    {
        $draft=$this->update($this->open(),'refresh',['editName'=>'','editPrice'=>'invalid'])->body();
        $cancel=$this->update($draft,'cancel');
        self::assertSame(200,$cancel->status());
        self::assertSame('', $this->state($cancel->body())['editName']);
        self::assertSame(0, $this->state($cancel->body())['editingId']);
        self::assertFalse($this->state($cancel->body())['editorOpen']);
        self::assertSame('Product 2',$this->record()->name);
        self::assertSame(1,$this->record()->version);
    }

    public function testViewPermissionDoesNotAllowOpeningTheEditor(): void
    {
        $html=$this->mount();
        Config::set('auth.user_loader',static fn ():array=>['role'=>2,'status'=>1]);
        $this->denied(fn()=>$this->update($html,'edit',['selectedId'=>'2']));
    }

    public function testOwnershipAndFeaturedScopeAreCheckedOnOpening(): void
    {
        $this->denied(fn()=>$this->open(3));
        $this->denied(fn()=>$this->open(1,true));
        self::assertSame(422,$this->update($this->mount(),'edit',['selectedId'=>'999'])->status());
    }

    public function testPermissionRevocationBlocksSaveButAllowsDiscard(): void
    {
        $html=$this->open();
        Config::set('auth.user_loader',static fn ():array=>['role'=>2,'status'=>1]);
        $this->denied(fn()=>$this->update($html,'save',['editName'=>'Denied','editPrice'=>'1']));
        self::assertSame(1,$this->record()->version);
        self::assertFalse($this->state($this->update($html,'cancel')->body())['editorOpen']);
    }

    public function testOwnershipAndScopeChangesAfterOpeningBlockWrites(): void
    {
        $html=$this->open();
        App::db()->query('UPDATE products SET owner_id = 2 WHERE id = 2');
        $this->denied(fn()=>$this->update($html,'save',['editName'=>'Denied','editPrice'=>'1']));
        self::assertSame(200,$this->update($html,'cancel')->status());
        App::db()->query('UPDATE products SET owner_id = 1 WHERE id = 2');
        $featured=$this->open(2,true);
        App::db()->query('UPDATE products SET featured = 0 WHERE id = 2');
        $this->denied(fn()=>$this->update($featured,'save',['editName'=>'Denied','editPrice'=>'1']));
        self::assertSame(1,$this->record()->version);
    }

    public function testWritableSelectionCannotRetargetAnOpenEditorAndLockedContextCannotBeForged(): void
    {
        $html=$this->open();
        foreach (['editingId','editingVersion','editorOpen','featuredOnly','instanceKey','notice'] as $field) {
            $this->denied(fn()=>$this->update($html,'save',[$field=>'3']),400);
        }
        self::assertSame(200,$this->update($html,'save',['selectedId'=>'3','editName'=>'Only product 2','editPrice'=>'1'])->status());
        self::assertSame('Only product 2',$this->record()->name);
        self::assertSame('Product 3',$this->record(3)->name);
    }

    public function testDeletedRecordCannotBeRecreatedAndEditorCanBeCancelled(): void
    {
        $html=$this->open();
        App::db()->query('DELETE FROM products WHERE id = 2');
        $response=$this->update($html,'save',['editName'=>'Resurrect','editPrice'=>'1']);
        self::assertSame(422,$response->status());
        self::assertStringContainsString('This product was deleted.',$response->body());
        self::assertSame(0,(int)App::db()->pdo()->query('SELECT COUNT(*) FROM products WHERE id = 2')->fetchColumn());
        self::assertFalse($this->state($this->update($response->body(),'cancel')->body())['editorOpen']);
    }

    public function testConcurrentAndReplayedSavesCannotOverwriteNewerVersions(): void
    {
        $html=$this->open();
        $first=$this->update($html,'save',['editName'=>'First save','editPrice'=>'2']);
        self::assertSame(200,$first->status());
        $stale=$this->update($html,'save',['editName'=>'Stale overwrite','editPrice'=>'3']);
        self::assertSame(422,$stale->status());
        self::assertStringContainsString('changed elsewhere',$stale->body());
        self::assertSame('First save',$this->record()->name);
        self::assertSame(2,$this->record()->version);
        self::assertSame('Stale overwrite',$this->state($stale->body())['editName']);
        $cancel=$this->update($stale->body(),'cancel')->body();
        $reopened=$this->update($cancel,'edit',['selectedId'=>'2'])->body();
        self::assertSame(2,$this->state($reopened)['editingVersion']);
    }

    public function testDatabaseFailureRollsBackAndDoesNotProduceSuccessEffects(): void
    {
        App::db()->query("CREATE TRIGGER reject_update AFTER UPDATE ON products BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        try { $this->update($this->open(),'save',['editName'=>'Not saved','editPrice'=>'1']); self::fail('Expected write failure.'); }
        catch (\PDOException $error) { self::assertStringContainsString('fixture failure',$error->getMessage()); }
        self::assertSame('Product 2',$this->record()->name);
        self::assertSame(1,$this->record()->version);
        self::assertFalse(App::db()->pdo()->inTransaction());
    }

    public function testMalformedRequestMetadataIsRejectedBeforeSaving(): void
    {
        foreach ([['bad'],str_repeat('x',8193)] as $query) {
            $this->denied(fn()=>$this->update($this->open(),'save',['editName'=>'Not saved','editPrice'=>'1'],['_query'=>$query]),400);
        }
        self::assertSame(1,$this->record()->version);
        self::assertSame('Product 2',$this->record()->name);
    }
}
