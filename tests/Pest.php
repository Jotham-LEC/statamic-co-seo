<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JothamLec\MarketingToolkit\Commands\Install;
use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\Cp\Navigation;
use JothamLec\MarketingToolkit\IndexNow\IndexNow;
use JothamLec\MarketingToolkit\Meta;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Redirects\Drivers;
use JothamLec\MarketingToolkit\Redirects\File\SiteRules;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Redirects\RedirectHit;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Reports\ExternalLinkChecker;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\CP\Navigation\NavItem;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\Facades\User;

uses(TestCase::class)
    ->beforeEach(function () {
        $this->app['env'] = 'production';
        // Statamic keeps this in a static flag; a test that turns it on must not leak.
        URL::enforceTrailingSlashes(false);
        // Nothing leaves the test run: IndexNow is faked where it is tested.
        Http::preventStrayRequests();
        // Production env makes Laravel's CSRF check live; the CP sends the token.
        // PreventRequestForgery is Laravel 13's; ValidateCsrfToken is Laravel 12's.
        $this->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class]);

        // mt:install writes collection blueprints to disk, which would outlive the test.
        File::deleteDirectory(resource_path('blueprints/collections'));

        // Redirect rules: the suites that cover both drivers run on the database by default, and on files
        // with MT_TEST_REDIRECTS=file (composer test runs both). Rule files go in a folder of this
        // process's own, emptied for each test.
        redirectsDriver(env('MT_TEST_REDIRECTS') === 'file' ? 'file' : 'eloquent');

        // On a host without Imagick, pages would point at no card: the tests of card URLs
        // still run there (those that draw one are skipped), so cards count as drawable.
        if (! extension_loaded('imagick')) {
            app()->instance(Generator::class, new class extends Generator
            {
                public function available(): bool
                {
                    return true;
                }
            });
        }

        Site::setSites(['default' => ['name' => 'Acme', 'url' => 'https://example.test/', 'locale' => 'en_US']]);
        AssetContainer::make('assets')->disk('assets')->save();
        Collection::make('home')->routes('/')->save();
        Collection::make('pages')->routes('{slug}')->save();
        Collection::make('essays')->routes('essays/{slug}')->dated(true)->save();
    })
    ->in('Feature');

/**
 * @param  array<string, mixed>  $data
 */
function entryIn(string $collection, string $slug, array $data = [], ?string $date = null): EntryContract
{
    $entry = Entry::make()->collection($collection)->slug($slug)->data(['title' => ucfirst(str_replace('-', ' ', $slug)), ...$data]);

    if ($date) {
        $entry->date($date);
    }

    $entry->save();

    return $entry;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function metaFor(EntryContract|Term|null $entry, string $uri = '/', array $overrides = [], int $status = 200): Meta
{
    // Bound as the app's request too: Statamic works out the current site from it.
    app()->instance('request', $request = Request::create(absoluteTestUrl($uri)));

    return app(SiteSeo::class)->meta(Context::make($entry, $request, $overrides, $status));
}

/**
 * A path on the default site, or a full address (another site's) as it is.
 */
function absoluteTestUrl(string $uri): string
{
    return preg_match('#^https?://#', $uri) ? $uri : 'https://example.test'.$uri;
}

/**
 * The brand global with these values on one site. On a multi-site install
 * the set is on every site, and each other site's origin is the default.
 *
 * @param  array<string, mixed>  $values
 */
function seoGlobal(array $values, string $site = 'default'): void
{
    Blueprint::make('seo')->setNamespace('globals')->setContents(['tabs' => Install::tabs('assets')])->save();

    $set = GlobalSet::findByHandle('seo') ?? GlobalSet::make('seo')->title('SEO & brand');

    if (Site::multiEnabled()) {
        $set->sites(Site::all()->mapWithKeys(fn ($each) => [$each->handle() => $each->handle() === 'default' ? null : 'default'])->all());
    }

    $set->save();
    $set->in($site)->data($values)->save();
}

/**
 * Two sites on two domains, as Statamic Pro runs them: `default` on
 * example.test and `cothinking` on cothink.test. Home and pages are on both.
 */
function multisite(): void
{
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);

    Site::setSites([
        'default' => ['name' => 'Acme', 'url' => 'https://example.test/', 'locale' => 'en_US'],
        'cothinking' => ['name' => 'CoThinking', 'url' => 'https://cothink.test/', 'locale' => 'en_GB'],
    ]);

    Collection::findByHandle('home')->sites(['default', 'cothinking'])->save();
    Collection::findByHandle('pages')->sites(['default', 'cothinking'])->save();
}

/**
 * One site in four languages: English (US) on example.test, French under
 * /fr/, British English under /uk/, German on its own domain. Home and pages
 * are on every site; a localization names its origin.
 */
function multilang(): void
{
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);

    Site::setSites([
        'default' => ['name' => 'Acme', 'url' => 'https://example.test/', 'locale' => 'en_US'],
        'fr' => ['name' => 'Acme', 'url' => 'https://example.test/fr/', 'locale' => 'fr_FR'],
        'uk' => ['name' => 'Acme', 'url' => 'https://example.test/uk/', 'locale' => 'en_GB'],
        'de' => ['name' => 'Acme', 'url' => 'https://de.example.test/', 'locale' => 'de_DE'],
    ]);

    Collection::findByHandle('home')->sites(['default', 'fr', 'uk', 'de'])->save();
    Collection::findByHandle('pages')->sites(['default', 'fr', 'uk', 'de'])->save();
}

/**
 * $origin in another language: an entry on $site that names it as its origin.
 *
 * @param  array<string, mixed>  $data
 */
function translationOf(EntryContract $origin, string $site, string $slug, array $data = []): EntryContract
{
    $entry = Entry::make()->collection($origin->collectionHandle())->locale($site)->origin($origin)->slug($slug)
        ->data(['title' => ucfirst(str_replace('-', ' ', $slug)), ...$data]);
    $entry->save();

    return $entry;
}

/**
 * An entry on another site than the default.
 *
 * @param  array<string, mixed>  $data
 */
function entryOn(string $site, string $collection, string $slug, array $data = []): EntryContract
{
    $entry = Entry::make()->collection($collection)->locale($site)->slug($slug)->data(['title' => ucfirst(str_replace('-', ' ', $slug)), ...$data]);
    $entry->save();

    return $entry;
}

/**
 * Renders Blade as if for a request to $uri (a path on the default site, or
 * a full address): what a layout's <s:mt:meta /> prints.
 *
 * @param  array<string, mixed>  $data
 */
function renderAt(string $uri, string $blade, array $data = []): string
{
    app()->instance('request', Request::create(absoluteTestUrl($uri)));

    return Blade::render($blade, $data);
}

/**
 * A control panel user: a super user, or one whose role has these permissions.
 *
 * @param  list<string>  $permissions
 */
function cpUser(array $permissions = [], bool $super = false): Statamic\Contracts\Auth\User
{
    $user = User::make()->email(($super ? 'super' : 'editor').'@example.test');

    if ($super) {
        $user->makeSuper();
    } else {
        Role::make('seo-role')->permissions(['access cp', ...$permissions])->save();
        $user->assignRole('seo-role');
    }

    return tap($user)->save();
}

/**
 * @return array<string, mixed>|null
 */
function nodeOf(Meta $meta, string $type): ?array
{
    return collect($meta->graph)->first(fn (array $node) => in_array($type, (array) $node['@type'], true));
}

/**
 * The publisher node of a page, once the brand global holds $values.
 *
 * @param  array<string, mixed>  $values
 * @return array<string, mixed>
 */
function publisherOf(array $values): array
{
    seoGlobal($values);

    return collect(metaFor(entryIn('pages', 'about'))->graph)->firstWhere('@id', 'https://example.test/#publisher');
}

/**
 * The addresses IndexNow will send when the request ends.
 *
 * @return list<string>
 */
function queuedForIndexNow(): array
{
    return (fn () => $this->queued())->call(app(IndexNow::class));
}

/**
 * Host names the external link checker resolves, to these addresses; any
 * other doesn't resolve. Nothing leaves the test run for DNS either.
 *
 * @param  array<string, string|list<string>>  $hosts
 */
function fakeDns(array $hosts): void
{
    app()->instance(ExternalLinkChecker::class, new class($hosts) extends ExternalLinkChecker
    {
        /** @param  array<string, string|list<string>>  $hosts */
        public function __construct(private array $hosts) {}

        protected function resolve(string $host): array
        {
            return array_values((array) ($this->hosts[$host] ?? []));
        }
    });
}

/**
 * The CP navigation's items under Tools, keyed by name, as the user sees them.
 *
 * @return Illuminate\Support\Collection<string, NavItem>
 */
/**
 * The Marketing section of the nav, as the current user sees it: its items
 * by label, and with `children: true` each item's children after it.
 */
function marketingNav(bool $children = false): Illuminate\Support\Collection
{
    // AddonTestCase mocks the nav after the addon has extended the real one.
    Nav::swap(new Statamic\CP\Navigation\Nav);
    Navigation::register();

    $section = collect(Nav::build())->firstWhere('display', __('marketing-toolkit::cp.seo'));
    $items = collect($section['items'] ?? []);

    if ($children) {
        $items = $items->flatMap(fn ($item) => [$item, ...($item->resolveChildren()->children() ?? [])]);
    }

    return $items->keyBy(fn ($item) => $item->display());
}

/**
 * Stores redirect rules with this driver from now on, in an empty folder of the test process's own.
 * For files the folder is made, so rows a test writes to the database don't bring the safety net in.
 */
function redirectsDriver(string $driver): void
{
    $folder = storage_path('framework/testing/redirects');
    File::deleteDirectory($folder);

    if ($driver === 'file') {
        File::ensureDirectoryExists($folder);
    }

    config(['marketing-toolkit.redirects.driver' => $driver, 'marketing-toolkit.redirects.directory' => $folder]);
    app()->forgetScopedInstances();
}

/**
 * Whether these tests store redirect rules in files (MT_TEST_REDIRECTS=file).
 */
function onFiles(): bool
{
    return app(RedirectRepository::class)->driver() === 'file';
}

/**
 * Saves a redirect rule through the driver the test runs on.
 *
 * @param  array<string, mixed>  $attributes
 */
function redirectRule(array $attributes): Rule
{
    $rule = app(RedirectRepository::class)->save(Rule::make($attributes));

    // Hits are counted, never saved with a rule: a test that starts with some writes them where the driver counts them.
    if ($hits = (int) ($attributes['hits'] ?? 0)) {
        onFiles()
            ? RedirectHit::merge((string) $rule->id, $hits, null)
            : Redirect::query()->whereKey((int) $rule->id)->update(['hits' => $hits]);
        $rule->hits = $hits;
    }

    return $rule;
}

/**
 * Every redirect rule the driver holds, with its hits, oldest first on the database.
 *
 * @return Illuminate\Support\Collection<int, Rule>
 */
function redirectRules(): Illuminate\Support\Collection
{
    $repository = app(RedirectRepository::class);

    return $repository->all()->map(fn ($rule) => $repository->find((string) $rule->id) ?? $rule)->values();
}

/**
 * The rule as it is stored now, with its hits, or null once it is deleted.
 */
function freshRule(Rule $rule): ?Rule
{
    return app(RedirectRepository::class)->find((string) $rule->id);
}

/**
 * Changes a saved rule's fields and saves it through the driver.
 *
 * @param  array<string, mixed>  $changes
 */
function updateRule(Rule $rule, array $changes): Rule
{
    return app(RedirectRepository::class)->save($rule->fill($changes));
}

function deleteRule(Rule $rule): void
{
    app(RedirectRepository::class)->delete($rule);
}

/**
 * Whether the suite runs on files (MT_TEST_REDIRECTS=file), for a test that is about the database
 * driver alone and is skipped there.
 */
function testingOnFiles(): bool
{
    return env('MT_TEST_REDIRECTS') === 'file';
}

/**
 * Stores a rule as it is, unchecked and untidied, as a site's own migration inserts a row or someone
 * types it into a rule file.
 *
 * @param  array<string, mixed>  $attributes
 */
function storedRule(array $attributes): void
{
    if (! onFiles()) {
        DB::table('mt_redirects')->insert(['status' => 301, 'active' => true, 'automatic' => false, 'hits' => 0, ...$attributes]);

        return;
    }

    $store = Drivers::store();
    $file = $store->file(SiteRules::keyFor($attributes['site'] ?? null));
    $file->rules[] = Rule::make(['id' => (string) Str::uuid(), ...$attributes]);
    $store->save($file);
    Matcher::flush();
}

/**
 * These fields of a rule, as an array.
 *
 * @param  list<string>  $fields
 * @return array<string, mixed>
 */
function ruleFields(?Rule $rule, array $fields): array
{
    return collect($rule?->toArray() ?? [])->only($fields)->all();
}

/**
 * These fields of every rule, sorted by site (every site first) and source.
 *
 * @param  list<string>  $fields
 * @return list<array<string, mixed>>
 */
function ruleRows(array $fields): array
{
    return redirectRules()
        ->sort(fn ($a, $b) => [$a->site !== null, (string) $a->site, $a->source] <=> [$b->site !== null, (string) $b->site, $b->source])
        ->map(fn ($rule) => ruleFields($rule, $fields))
        ->values()
        ->all();
}
