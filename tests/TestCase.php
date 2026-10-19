<?php

namespace JothamLec\MarketingToolkit\Tests;

use Composer\Autoload\ClassLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JothamLec\MarketingToolkit\ServiceProvider;
use ReflectionClass;
use Statamic\Facades\Stache;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk, RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Each test process gets its own copy of Testbench's skeleton (storage,
     * resources) and its own Stache and assets folder, so processes never
     * read or delete each other's files, and no run sees what an earlier one
     * left behind.
     */
    private static bool $fresh = false;

    public static function applicationBasePath()
    {
        $skeleton = parent::applicationBasePath();
        // Serial runs get their own copy too, so nothing a test writes lands in vendor/.
        $token = self::token() ?? 'serial';

        // One per checkout and process, made afresh when the process starts, so
        // blueprints and forms a test leaves on disk don't outlive the run.
        $vendor = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName(), 2);
        $copy = sys_get_temp_dir().'/marketing-toolkit-tests/'.md5($vendor).'-'.$token;
        $files = new Filesystem;

        if (! self::$fresh) {
            $files->deleteDirectory($copy);
            self::$fresh = true;
        }

        if (! is_dir($copy)) {
            $files->ensureDirectoryExists($copy);
            foreach (array_diff(scandir($skeleton), ['.', '..', 'storage', 'vendor', 'resources']) as $item) {
                is_dir($skeleton.'/'.$item)
                    ? $files->copyDirectory($skeleton.'/'.$item, $copy.'/'.$item)
                    : $files->copy($skeleton.'/'.$item, $copy.'/'.$item);
            }
            // resources/ starts empty: what tests save there (blueprints, forms, roles) is theirs alone.
            $files->copyDirectory($skeleton.'/resources/views', $copy.'/resources/views');
            foreach (['app/public', 'app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
                $files->ensureDirectoryExists($copy.'/storage/'.$folder);
            }
            $files->link($vendor, $copy.'/vendor');
        }

        return $copy;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // As a site with Model::shouldBeStrict() runs: reading a column a query didn't load throws.
        Model::preventAccessingMissingAttributes();

        if ($token = self::token()) {
            $shared = $this->fakeStacheDirectory;
            $this->fakeStacheDirectory = $shared.'-'.$token;
            Stache::stores()->each(fn ($store) => $store->directory(str_replace($shared, $this->fakeStacheDirectory, $store->directory())));
        }
    }

    private static function token(): ?string
    {
        return ($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN')) ?: null;
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('app.env', 'production');
        $app['config']->set('cache.default', 'array');
        // Like the file and Redis stores sites use: each read is a fresh copy, so
        // the Stache never hands back the very object a test is changing.
        $app['config']->set('cache.stores.array.serialize', true);
        $app['config']->set('database.default', 'testing');

        // MT_TEST_DB=pgsql runs the suite on Postgres (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD).
        if (env('MT_TEST_DB') === 'pgsql') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'mt_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'disable',
            ]);
        }

        // A template and layout, so a page that exists renders instead of 404ing.
        $app['config']->set('view.paths', [__DIR__.'/fixtures/views']);
        $app['config']->set('statamic.editions.pro', false);
        $app['config']->set('filesystems.disks.assets', [
            'driver' => 'local',
            'root' => __DIR__.'/__fixtures__/dev-null'.(self::token() ? '-'.self::token() : '').'/assets',
            'url' => '/assets',
            'visibility' => 'public',
        ]);
    }
}
