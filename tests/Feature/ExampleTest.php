<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Tests\Feature;

use FloCMS\Core\{App, Auth, Config, Database};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Kernel, Registry, Snapshot};
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/examples/products/ProductTable.php';
if (!defined('ROOT')) { define('ROOT', dirname(__DIR__, 2) . '/examples/products/site'); }
if (!defined('VIEWS_PATH')) { define('VIEWS_PATH', ROOT . '/views'); }
if (!defined('TEMPLATES_PATH')) { define('TEMPLATES_PATH', ROOT . '/templates'); }
if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }

final class ExampleTest extends TestCase
{
    public function testRealQuerySearchPaginationAndLockedFeaturedScope(): void
    {
        Config::$settings = [];
        $_SESSION = ['role' => 1, 'isloggedin' => true, '_csrf_token' => 'tok'];
        Config::set('permissions', [1 => ['catalog.view']]);
        App::$db = new Database(new \PDO('sqlite::memory:'));
        (require dirname(__DIR__, 2) . '/examples/products/schema.php')(App::$db);
        for ($i = 1; $i <= 12; $i++) { App::$db->table('products')->insert(['id'=>$i,'name'=>'Product '.$i,'price_cents'=>$i * 100,'featured'=>$i % 2,'owner_id'=>1]); }
        $registry = new Registry();
        $registry->register('products', \FloCMS\Reactive\Examples\ProductTable::class);
        $kernel = new Kernel($registry, new Snapshot(str_repeat('k',32)), '/flo-reactive/update');
        $request = new Request([],[],['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/demo'],[],[]);
        $html = $kernel->mount('products','catalog',[],$request);
        self::assertStringContainsString('12 products', $html);
        self::assertStringContainsString('1 / 3', $html);
        preg_match('/data-rx-snapshot="([^"]+)"/', $html, $match);
        $response = $kernel->update(new Request([],['_token'=>'tok','_snapshot'=>html_entity_decode($match[1]),'state'=>['search'=>'Product 12','page'=>'1']],['REQUEST_METHOD'=>'POST'],[],[]));
        self::assertStringContainsString('1 products', $response->body());
        self::assertStringContainsString('Product 12', $response->body());
        self::assertStringNotContainsString('Product 11', $response->body());
        $featured = $kernel->mount('products','featured',['featuredOnly'=>true],$request);
        self::assertStringContainsString('6 products', $featured);
        $restored = $kernel->mount('products', 'catalog', [], new Request(
            ['catalog_q'=>'Product', 'catalog_page'=>'2'], [],
            ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/demo'], [], []
        ));
        self::assertStringContainsString('2 / 3', $restored);
        self::assertStringContainsString('Product 6', $restored);
        self::assertStringNotContainsString('<td>Product 1</td>', $restored);
    }
}
