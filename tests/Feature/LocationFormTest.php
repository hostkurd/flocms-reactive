<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Tests\Feature;

use FloCMS\Core\{App, Config, Csrf, Database, HttpException};
use FloCMS\Core\Http\{Request, Response};
use FloCMS\Reactive\{Kernel, Registry, Snapshot};
use FloCMS\Reactive\Examples\LocationForm;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/examples/forms/LocationForm.php';
if (!defined('ROOT')) { define('ROOT', dirname(__DIR__, 2) . '/examples/products/site'); }
if (!defined('VIEWS_PATH')) { define('VIEWS_PATH', ROOT . '/views'); }
if (!defined('TEMPLATES_PATH')) { define('TEMPLATES_PATH', ROOT . '/templates'); }
if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }

final class LocationFormTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        Config::$settings = [];
        $_SESSION = ['_csrf_token'=>'tok', 'isloggedin'=>true, 'user_id'=>1, 'role'=>1];
        Config::set('permissions', [1=>['locations.view','locations.edit'], 2=>['locations.view']]);
        App::$db = new Database(new \PDO('sqlite::memory:'));
        App::$db->query('PRAGMA foreign_keys = ON');
        (require dirname(__DIR__, 2) . '/examples/forms/schema.php')(App::$db);
        $registry = new Registry();
        $registry->register('location', LocationForm::class);
        $this->kernel = new Kernel($registry, new Snapshot(str_repeat('k',32)), '/flo-reactive/update');
    }

    private function mount(): string
    {
        return $this->kernel->mount('location', 'address', [], new Request([],[],['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/demo/form'],[],[]));
    }

    private function update(string $html, array $state, string $action = 'refresh'): Response
    {
        preg_match('/data-rx-snapshot="([^"]+)"/', $html, $match);
        return $this->kernel->update(new Request([], ['_token'=>Csrf::token(), '_snapshot'=>html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), 'state'=>$state, '_action'=>$action], ['REQUEST_METHOD'=>'POST'], [], []));
    }

    private function country(int $country = 1): string
    {
        return $this->update($this->mount(), ['countryId'=>(string) $country])->body();
    }

    private function addressCount(): int
    {
        return (int) App::db()->pdo()->query('SELECT COUNT(*) FROM delivery_addresses')->fetchColumn();
    }

    public function testIncompleteDraftsLoadOnlyCitiesInTheirCountry(): void
    {
        $html = $this->mount();
        self::assertStringContainsString('Choose a country to load its cities.', $html);
        self::assertStringNotContainsString('Sulaymaniyah', $html);
        $response = $this->update($html, ['name'=>'Partial', 'countryId'=>'1']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sulaymaniyah', $response->body());
        self::assertStringNotContainsString('Athens', $response->body());
        self::assertSame(0, $this->addressCount());
    }

    public function testCountryChangesResetCityForBothSubmittedOrders(): void
    {
        $html = $this->update($this->country(), ['cityId'=>'1'])->body();
        foreach ([['countryId'=>'2','cityId'=>'1'], ['cityId'=>'1','countryId'=>'2']] as $state) {
            $response = $this->update($html, $state);
            self::assertSame(200, $response->status());
            self::assertStringContainsString('&quot;cityId&quot;:0', $response->body());
            self::assertStringContainsString('Athens', $response->body());
            self::assertStringNotContainsString('Sulaymaniyah', $response->body());
        }
    }

    public function testSaveValidatesRequiredFieldsAndRetainsDraftWithFreshSnapshot(): void
    {
        $response = $this->update($this->mount(), [], 'save');
        self::assertSame(422, $response->status());
        foreach (['Enter your full name.', 'Choose a country.', 'Choose a city.'] as $error) { self::assertStringContainsString($error, $response->body()); }
        self::assertFalse(App::db()->pdo()->inTransaction());
        self::assertSame(0, $this->addressCount());
        $country = $this->update($response->body(), ['name'=>'Khalat', 'countryId'=>'1'])->body();
        $saved = $this->update($country, ['cityId'=>'1'], 'save');
        self::assertSame(200, $saved->status());
        self::assertStringContainsString('Your address has been saved.', $saved->body());
    }

    public function testWrongCountryCityAndUnknownLookupsNeverSave(): void
    {
        foreach ([['cityId'=>'3'], ['cityId'=>'999'], ['countryId'=>'999']] as $state) {
            $response = $this->update($this->country(), ['name'=>'Khalat'] + $state, 'save');
            self::assertSame(422, $response->status());
            self::assertSame(0, $this->addressCount());
        }
        $response = $this->update($this->country(), ['countryId'=>'2','cityId'=>'3','name'=>'Khalat'], 'save');
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choose a city.', $response->body());
        self::assertSame(0, $this->addressCount());
    }

    public function testLookupDeletionAfterSelectionIsCheckedAgain(): void
    {
        $html = $this->update($this->country(), ['name'=>'Khalat','cityId'=>'1'])->body();
        App::db()->query('DELETE FROM cities WHERE id = 1');
        $response = $this->update($html, [], 'save');
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choose a city belonging', $response->body());
        self::assertSame(0, $this->addressCount());
    }

    public function testValidSavePersistsReloadsAndUpdatesWithoutDuplicateRows(): void
    {
        $response = $this->update($this->country(), ['name'=>'  Khalat Jalal  ','cityId'=>'2'], 'save');
        self::assertSame(200, $response->status());
        $record = App::db()->table('delivery_addresses')->where('user_id', '=', 1)->first();
        self::assertSame('Khalat Jalal', $record->name);
        self::assertSame(2, $record->city_id);
        self::assertStringContainsString('value="Khalat Jalal"', $this->mount());
        self::assertStringContainsString('&quot;cityId&quot;:2', $this->mount());
        $second = $this->update($response->body(), ['name'=>'Khalat'], 'save');
        self::assertSame(200, $second->status());
        self::assertSame(1, $this->addressCount());
        self::assertSame('Khalat', App::db()->table('delivery_addresses')->where('user_id', '=', 1)->first()->name);
    }

    public function testInvalidNameIsRetainedEscapedAndCanBeCorrected(): void
    {
        $name = '<script>' . str_repeat('x', 101);
        $response = $this->update($this->country(), ['name'=>$name,'cityId'=>'1'], 'save');
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Use no more than 100 characters.', $response->body());
        self::assertStringContainsString('&lt;script&gt;', $response->body());
        self::assertStringNotContainsString('<script>', $response->body());
        self::assertSame(0, $this->addressCount());
        self::assertSame(200, $this->update($response->body(), ['name'=>'Valid'], 'save')->status());
    }

    public function testSavePermissionIsRecheckedAndReadOnlyRenderDisablesSubmit(): void
    {
        $html = $this->country();
        Config::set('auth.user_loader', static fn (): array => ['role'=>2,'status'=>1]);
        try {
            $this->update($html, ['name'=>'Khalat','cityId'=>'1'], 'save');
            self::fail('Expected revoked save permission.');
        } catch (HttpException $error) { self::assertSame(403, $error->status); }
        self::assertSame(0, $this->addressCount());
        self::assertStringContainsString('type="submit"  disabled', $this->mount());
    }

    public function testEachUserReadsAndWritesOnlyTheirOwnAddress(): void
    {
        $this->update($this->country(), ['name'=>'First user','cityId'=>'1'], 'save');
        $_SESSION['user_id'] = 2;
        self::assertStringNotContainsString('First user', $this->mount());
        $this->update($this->country(2), ['name'=>'Second user','cityId'=>'3'], 'save');
        self::assertSame(2, $this->addressCount());
        self::assertSame('First user', App::db()->table('delivery_addresses')->where('user_id', '=', 1)->first()->name);
        self::assertSame('Second user', App::db()->table('delivery_addresses')->where('user_id', '=', 2)->first()->name);
    }

    public function testWriteFailureRollsBackAndIsNotMisreportedAsValidation(): void
    {
        App::db()->query("CREATE TRIGGER reject_save BEFORE INSERT ON delivery_addresses BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        try {
            $this->update($this->country(), ['name'=>'Khalat','cityId'=>'1'], 'save');
            self::fail('Expected database failure.');
        } catch (\PDOException $error) { self::assertStringContainsString('fixture failure', $error->getMessage()); }
        self::assertSame(0, $this->addressCount());
        self::assertFalse(App::db()->pdo()->inTransaction());
    }
}
