<?php

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use JothamLec\MarketingToolkit\Events\RedirectDeleted;
use JothamLec\MarketingToolkit\Events\RedirectSaved;
use JothamLec\MarketingToolkit\Redirects\AutoRedirects;
use JothamLec\MarketingToolkit\Redirects\Csv;
use JothamLec\MarketingToolkit\Redirects\Drivers;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Redirects\RedirectHit;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\ServiceProvider;
use Statamic\Events\EntryScheduleReached;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\YAML;

/*
 * The file driver: rules in YAML files, one per site, and what moves them between the database and
 * files. The suites of the other redirect tests run on files too, with MT_TEST_REDIRECTS=file.
 */

beforeEach(fn () => redirectsDriver('file'));

function rulesFolder(): string
{
    return (string) config('marketing-toolkit.redirects.directory');
}

function rulesFile(string $key = 'all'): string
{
    return rulesFolder()."/{$key}.yaml";
}

/**
 * @return array<int, mixed>
 */
function rulesIn(string $key = 'all'): array
{
    return YAML::parse((string) file_get_contents(rulesFile($key)));
}

/**
 * Writes a rule file by hand, and reads the files afresh, as `stache:clear` does.
 */
function handWritten(string $key, string $yaml): void
{
    File::ensureDirectoryExists(rulesFolder());
    file_put_contents(rulesFile($key), $yaml);
    Drivers::store()->clear();
}

test('a file holds a site\'s rules, sorted by source, without the values that are the defaults', function () {
    $gone = redirectRule(['source' => '/gone', 'status' => 410]);
    $old = redirectRule(['source' => '/old-page/', 'target' => '/new-page/']);
    $blog = redirectRule(['source' => '/blog/*', 'target' => '/essays/$1', 'status' => 302, 'active' => false, 'automatic' => true]);

    expect(Str::isUuid($old->id))->toBeTrue()
        ->and(rulesIn())->toBe([
            ['id' => $blog->id, 'source' => '/blog/*', 'target' => '/essays/$1', 'status' => 302, 'active' => false, 'automatic' => true],
            ['id' => $gone->id, 'source' => '/gone', 'status' => 410],
            ['id' => $old->id, 'source' => '/old-page', 'target' => '/new-page'],
        ]);

    // Read back as written, the site from the file's name.
    expect(app(RedirectRepository::class)->find($blog->id))
        ->site->toBeNull()->status->toBe(302)->active->toBeFalse()->automatic->toBeTrue();

    $this->get('/old-page')->assertRedirect('https://example.test/new-page');
    $this->get('/gone')->assertStatus(410);
});

test('an emptied file is kept as []', function () {
    $rule = redirectRule(['source' => '/old', 'target' => '/new']);

    deleteRule($rule);

    expect(file_get_contents(rulesFile()))->toBe("[]\n")
        ->and(rulesIn())->toBe([]);
});

test('on a multisite, each site\'s rules are in its own file, and the rules for every site in all.yaml', function () {
    multisite();
    redirectRule(['source' => '/old', 'target' => '/everywhere']);
    redirectRule(['site' => 'cothinking', 'source' => '/old', 'target' => '/here']);
    $moved = redirectRule(['site' => 'default', 'source' => '/moved', 'target' => '/x']);

    expect(collect(rulesIn('cothinking'))->pluck('target')->all())->toBe(['/here'])
        ->and(collect(rulesIn())->pluck('target')->all())->toBe(['/everywhere'])
        ->and(collect(rulesIn('default'))->pluck('source')->all())->toBe(['/moved'])
        ->and(rulesIn('cothinking')[0])->not->toHaveKey('site');

    $this->get('https://cothink.test/old')->assertRedirect('https://cothink.test/here');
    $this->get('https://example.test/old')->assertRedirect('https://example.test/everywhere');
    $this->get('https://cothink.test/moved')->assertNotFound();

    // A rule moved to another site leaves its old file.
    updateRule($moved, ['site' => 'cothinking']);

    expect(rulesIn('default'))->toBe([])
        ->and(collect(rulesIn('cothinking'))->pluck('id')->all())->toContain($moved->id);
});

test('the driver and the folder are read from the config when used, not when the app boots', function () {
    redirectsDriver('eloquent');
    redirectRule(['source' => '/in-database', 'target' => '/x']);
    expect(Redirect::query()->count())->toBe(1)->and(app(RedirectRepository::class)->driver())->toBe('eloquent');

    redirectsDriver('file');
    $this->get('/in-database')->assertNotFound();
    redirectRule(['source' => '/in-files', 'target' => '/y']);
    $this->get('/in-files')->assertRedirect('https://example.test/y');

    config(['marketing-toolkit.redirects.directory' => rulesFolder().'-elsewhere']);
    File::deleteDirectory(rulesFolder());
    File::ensureDirectoryExists(rulesFolder());

    expect(app(RedirectRepository::class)->all())->toHaveCount(0);
    $this->get('/in-files')->assertNotFound();

    config(['marketing-toolkit.redirects.driver' => 'nonsense']);
    expect(fn () => app(RedirectRepository::class))->toThrow(InvalidArgumentException::class, 'must be "file" or "eloquent"');
});

test('a visit is counted in mt_redirect_hits, after the response, and never writes a file', function () {
    $rule = redirectRule(['source' => '/old', 'target' => '/new']);
    $before = [filemtime(rulesFile()), file_get_contents(rulesFile())];
    touch(rulesFile(), time() - 60);
    clearstatcache();
    $mtime = filemtime(rulesFile());

    $this->get('/old')->assertRedirect('https://example.test/new');
    $this->get('/old');

    clearstatcache();
    expect(RedirectHit::query()->sole())->redirect_id->toBe($rule->id)->hits->toBe(2)->last_hit_at->not->toBeNull()
        ->and(freshRule($rule)->hits)->toBe(2)
        ->and(filemtime(rulesFile()))->toBe($mtime)
        ->and(file_get_contents(rulesFile()))->toBe($before[1]);

    // Another request recorded the first hit a moment before: its row is added to, not doubled.
    $other = redirectRule(['source' => '/other', 'target' => '/new']);
    $raced = false;
    DB::listen(function ($query) use (&$raced, $other) {
        if (! $raced && str_starts_with($query->sql, 'update "mt_redirect_hits"')) {
            $raced = true;
            DB::table('mt_redirect_hits')->insert(['redirect_id' => $other->id, 'hits' => 5]);
        }
    });
    RedirectHit::record((string) $other->id);

    expect(freshRule($other)->hits)->toBe(6);

    // Its count goes with it.
    deleteRule($rule);
    expect(RedirectHit::query()->where('redirect_id', $rule->id)->exists())->toBeFalse();
});

test('a rule typed into a file without an id applies, is listed as not editable, and gets an id on the next save', function () {
    handWritten('all', "-\n  source: /typed\n  target: /by-hand\n");
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    $this->get('/typed')->assertRedirect('https://example.test/by-hand');
    expect(RedirectHit::query()->count())->toBe(0);

    expect($this->getJson(cp_route('mt.redirects.listing'))->json('data.0'))->toMatchArray(['source' => '/typed', 'edit_url' => null, 'actions' => []]);
    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices.0.level', 'warning')
        ->where('notices.0.message', fn (string $message) => str_contains($message, 'has no id')));

    redirectRule(['source' => '/another', 'target' => '/x']);

    expect(collect(rulesIn())->every(fn (array $item) => Str::isUuid($item['id'] ?? '')))->toBeTrue();
});

test('of two rules from one address in a file, the first applies, and the screen says so', function () {
    handWritten('all', "-\n  id: a\n  source: /old\n  target: /first\n-\n  id: b\n  source: /old/\n  target: /second\n-\n  id: a\n  source: /other\n  target: /third\n");
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    $this->get('/old')->assertRedirect('https://example.test/first');
    // The same id again: only the first one counts.
    $this->get('/other')->assertNotFound();

    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices', fn ($notices) => collect($notices)->pluck('message')->contains(fn ($message) => str_contains($message, 'more than one redirect from /old/'))
            && collect($notices)->pluck('message')->contains(fn ($message) => str_contains($message, 'with the id a'))));
});

test('a file that doesn\'t parse is logged, applies no rules, fails no request and refuses saves', function () {
    Log::spy();
    handWritten('all', "- source: /old\n  target: [unclosed\n");
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    $this->get('/old')->assertNotFound();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'all.yaml'));

    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices.0.level', 'error')
        ->where('notices.0.message', fn (string $message) => str_contains($message, 'all.yaml can’t be read')));

    expect(fn () => redirectRule(['source' => '/new', 'target' => '/x']))->toThrow(RuntimeException::class, 'can’t be read')
        ->and(file_get_contents(rulesFile()))->toContain('[unclosed');
});

test('a file for a site the install doesn\'t have is ignored and listed as a warning', function () {
    handWritten('nowhere', "-\n  id: x\n  source: /old\n  target: /new\n");
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    $this->get('/old')->assertNotFound();
    expect(app(RedirectRepository::class)->all())->toHaveCount(0);

    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices.0.message', fn (string $message) => str_contains($message, 'nowhere.yaml is for a site this install doesn’t have')));
});

test('a site whose handle is `all` can\'t keep rules in files: the screen says so, and its saves are refused', function () {
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'Acme', 'url' => 'https://example.test/', 'locale' => 'en_US'],
        'all' => ['name' => 'All', 'url' => 'https://all.test/', 'locale' => 'en_US'],
    ]);
    $this->actingAs(cpUser(super: true));

    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices.0.level', 'error')
        ->where('notices.0.message', fn (string $message) => str_contains($message, 'handle is `all`')));

    $this->postJson(cp_route('mt.redirects.store'), ['site' => 'all', 'source' => '/a', 'target' => '/b', 'status' => '301', 'active' => true])
        ->assertJsonValidationErrors('site');
    expect(fn () => redirectRule(['site' => 'all', 'source' => '/a', 'target' => '/b']))->toThrow(RuntimeException::class);
});

test('the safety net: rules still in the database are used while the folder doesn\'t exist', function () {
    File::deleteDirectory(rulesFolder());
    Redirect::query()->create(['source' => '/legacy', 'target' => '/kept']);
    app()->forgetScopedInstances();
    Log::spy();
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    expect(app(RedirectRepository::class)->driver())->toBe('eloquent');
    $this->get('/legacy')->assertRedirect('https://example.test/kept');

    // Writes go to the database too, and no folder is made.
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/new', 'target' => '/x', 'status' => '301', 'active' => true])->assertOk();
    expect(Redirect::query()->count())->toBe(2)->and(is_dir(rulesFolder()))->toBeFalse();

    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('notices.0.message', fn (string $message) => str_contains($message, 'still in the database') && str_contains($message, 'mt:export-redirects')));
    Log::shouldHaveReceived('warning')->once();

    // Once the folder exists, the files win.
    File::ensureDirectoryExists(rulesFolder());
    app()->forgetScopedInstances();

    expect(app(RedirectRepository::class)->driver())->toBe('file');
    $this->get('/legacy')->assertNotFound();
    $this->get(cp_route('mt.redirects.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('notices', []));
});

test('without the folder or rows in the database, rules go to files, and the folder is made on the first save', function () {
    File::deleteDirectory(rulesFolder());
    app()->forgetScopedInstances();

    expect(app(RedirectRepository::class)->driver())->toBe('file');

    redirectRule(['source' => '/old', 'target' => '/new']);

    expect(is_dir(rulesFolder()))->toBeTrue()->and(Redirect::query()->count())->toBe(0);

    // With the Eloquent driver named, the database is used whatever is in the folder.
    config(['marketing-toolkit.redirects.driver' => 'eloquent']);
    expect(app(RedirectRepository::class)->driver())->toBe('eloquent');
});

test('mt:export-redirects writes each site\'s rules into its file, keeping their ids, and copies their hits', function () {
    multisite();
    File::deleteDirectory(rulesFolder());
    $every = Redirect::query()->create(['source' => '/old', 'target' => '/everywhere', 'hits' => 7, 'last_hit_at' => now()->subDay()]);
    $own = Redirect::query()->create(['site' => 'cothinking', 'source' => '/blog/*', 'target' => '/notes/$1', 'status' => 302, 'automatic' => true]);
    Redirect::query()->create(['site' => 'gone', 'source' => '/x', 'target' => '/y']);

    $this->artisan('statamic:mt:export-redirects')
        ->expectsOutputToContain('gone.yaml is for a site this install doesn\'t have')
        ->expectsOutputToContain('Wrote 3 redirects')
        ->expectsOutputToContain('--hits-only')
        ->assertSuccessful();

    expect(rulesIn())->toBe([['id' => (string) $every->id, 'source' => '/old', 'target' => '/everywhere']])
        ->and(rulesIn('cothinking'))->toBe([['id' => (string) $own->id, 'source' => '/blog/*', 'target' => '/notes/$1', 'status' => 302, 'automatic' => true]])
        ->and(file_get_contents(rulesFile()))->toContain("id: '{$every->id}'")
        ->and(file_exists(rulesFile('gone')))->toBeTrue()
        ->and(RedirectHit::query()->sole())->redirect_id->toBe((string) $every->id)->hits->toBe(7);

    // The files are used from now on, with their hits.
    app()->forgetScopedInstances();
    expect(app(RedirectRepository::class)->driver())->toBe('file')
        ->and(app(RedirectRepository::class)->find((string) $every->id)->hits)->toBe(7);
    $this->get('https://cothink.test/blog/x')->assertStatus(302)->assertRedirect('https://cothink.test/notes/x');

    // A second run refuses to overwrite the files, unless forced.
    $this->artisan('statamic:mt:export-redirects')->expectsOutputToContain('--force')->assertFailed();
    $this->artisan('statamic:mt:export-redirects', ['--force' => true])->assertSuccessful();
});

test('mt:export-redirects with no rows makes the folder and an empty all.yaml', function () {
    File::deleteDirectory(rulesFolder());

    $this->artisan('statamic:mt:export-redirects')->assertSuccessful();

    expect(file_get_contents(rulesFile()))->toBe("[]\n");
});

test('mt:export-redirects --hits-only writes no file, and carries the database\'s counts over to the rules in files', function () {
    $row = Redirect::query()->create(['source' => '/old', 'target' => '/new', 'hits' => 10, 'last_hit_at' => now()]);
    Redirect::query()->create(['source' => '/not-in-files', 'target' => '/x', 'hits' => 3]);
    handWritten('all', "-\n  id: '{$row->id}'\n  source: /old\n  target: /new\n");
    // Counted on files since the copy of the database was exported: the larger count is kept.
    RedirectHit::query()->create(['redirect_id' => (string) $row->id, 'hits' => 2, 'last_hit_at' => now()->subDay()]);
    $contents = file_get_contents(rulesFile());

    $this->artisan('statamic:mt:export-redirects', ['--hits-only' => true])->expectsOutputToContain('Copied the hits of 1')->assertSuccessful();

    expect(RedirectHit::query()->sole())->hits->toBe(10)
        ->and(RedirectHit::query()->sole()->last_hit_at->isToday())->toBeTrue()
        ->and(file_get_contents(rulesFile()))->toBe($contents);
});

test('mt:import-redirects copies the files into the database with their hits, and refuses rows already there unless forced', function () {
    multisite();
    $old = redirectRule(['source' => '/old', 'target' => '/new']);
    redirectRule(['site' => 'cothinking', 'source' => '/here', 'target' => '/there', 'status' => 302]);
    RedirectHit::record((string) $old->id);
    RedirectHit::record((string) $old->id);

    $this->artisan('statamic:mt:import-redirects')->expectsOutputToContain('MT_REDIRECTS_DRIVER=eloquent')->assertSuccessful();

    expect(Redirect::query()->orderBy('source')->get(['site', 'source', 'target', 'status', 'hits'])->toArray())->toBe([
        ['site' => 'cothinking', 'source' => '/here', 'target' => '/there', 'status' => 302, 'hits' => 0],
        ['site' => null, 'source' => '/old', 'target' => '/new', 'status' => 301, 'hits' => 2],
    ])->and(file_exists(rulesFile()))->toBeTrue();

    $this->artisan('statamic:mt:import-redirects')->expectsOutputToContain('--force')->assertFailed();

    updateRule($old, ['target' => '/newer']);
    $this->artisan('statamic:mt:import-redirects', ['--force' => true])->assertSuccessful();

    expect(Redirect::query()->count())->toBe(2)
        ->and(Redirect::query()->whereNull('site')->value('target'))->toBe('/newer');
});

test('stache:clear and stache:warm read the files afresh and flush the compiled rules', function () {
    redirectRule(['source' => '/old', 'target' => '/one']);
    $this->get('/old')->assertRedirect('https://example.test/one');

    // Changed by a deploy or by hand, with Statamic's watcher off as in production.
    file_put_contents(rulesFile(), str_replace('/one', '/two', (string) file_get_contents(rulesFile())));
    $this->get('/old')->assertRedirect('https://example.test/one');

    $this->artisan('statamic:stache:clear')->assertSuccessful();
    $this->get('/old')->assertRedirect('https://example.test/two');

    file_put_contents(rulesFile(), str_replace('/two', '/three', (string) file_get_contents(rulesFile())));
    $this->artisan('statamic:stache:warm')->assertSuccessful();
    $this->get('/old')->assertRedirect('https://example.test/three');
});

test('with Statamic\'s watcher on, a file edited by hand applies on the next request', function () {
    config(['statamic.stache.watcher' => true]);
    redirectRule(['source' => '/old', 'target' => '/one']);
    $this->get('/old')->assertRedirect('https://example.test/one');

    file_put_contents(rulesFile(), str_replace('/one', '/two', (string) file_get_contents(rulesFile())));
    touch(rulesFile(), time() + 5);
    clearstatcache();
    // A new request, as the Stache sees one.
    Drivers::store()->resetMemoizedState();

    $this->get('/old')->assertRedirect('https://example.test/two');
});

test('two releases of the app (two base paths) don\'t share their compiled rules', function () {
    $base = base_path();
    $release = sys_get_temp_dir().'/mt-release-'.getmypid();
    redirectRule(['source' => '/old', 'target' => '/one']);
    $this->get('/old')->assertRedirect('https://example.test/one');

    try {
        // The next release has new rules, while the live one has its own cached.
        app()->setBasePath($release);
        file_put_contents(rulesFile(), str_replace('/one', '/two', (string) file_get_contents(rulesFile())));
        Drivers::store()->clear();
        expect(app(Matcher::class)->match('/old', site: 'default')['target'])->toBe('/two');

        // The old release caches its rules again before the switch; the new one doesn't read them.
        app()->setBasePath($base);
        $cached = app(Matcher::class)->match('/old', site: 'default')['target'];
        app()->setBasePath($release);

        expect($cached)->toBe('/one')
            ->and(app(Matcher::class)->match('/old', site: 'default')['target'])->toBe('/two');
    } finally {
        app()->setBasePath($base);
    }
});

test('a CSV import and an automatic redirect each write a file once', function () {
    $writes = 0;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$writes) {
        $writes += (int) str_starts_with($event->key, 'stache::items::mt-redirects::');
    });
    redirectRule(['source' => '/x', 'target' => '/about']);
    $writes = 0;

    $result = app(Csv::class)->import("/a,/b\n/c,/d\n/e,/f\n");
    expect($result['created'])->toBe(3)->and($writes)->toBe(1);

    // The move repoints /x, drops nothing and adds /about: one write.
    $writes = 0;
    app(AutoRedirects::class)->create('/about', '/about-us');

    expect($writes)->toBe(1)
        ->and(collect(rulesIn())->pluck('target', 'source')->all())->toMatchArray(['/x' => '/about-us', '/about' => '/about-us']);
});

test('saving and deleting fire events with the rule, on either driver, and Git automation listens to them', function () {
    Event::fake([RedirectSaved::class, RedirectDeleted::class]);

    foreach (['file', 'eloquent'] as $driver) {
        redirectsDriver($driver);
        $rule = redirectRule(['source' => "/{$driver}", 'target' => '/x']);
        deleteRule($rule);

        Event::assertDispatched(RedirectSaved::class, fn (RedirectSaved $event) => $event->redirect->source === "/{$driver}");
        Event::assertDispatched(RedirectDeleted::class, fn (RedirectDeleted $event) => $event->redirect->id === $rule->id);
    }

    expect((new RedirectSaved(new Rule))->commitMessage())->toBe('Redirect saved');

    config(['statamic.git.enabled' => true]);
    ServiceProvider::listenForGit();

    expect(Event::hasListeners(RedirectSaved::class))->toBeTrue()
        ->and(Event::hasListeners(RedirectDeleted::class))->toBeTrue();
});

test('a site\'s own migration that adds rows writes the database, and migrations write no rule files', function () {
    File::deleteDirectory(rulesFolder());

    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    Redirect::query()->create(['source' => '/from-a-migration', 'target' => '/x']);

    expect(is_dir(rulesFolder()))->toBeFalse()
        ->and(Schema::hasTable('mt_redirect_hits'))->toBeTrue()
        ->and(Redirect::query()->count())->toBe(1);
});

test('scheduled publishing adds no redirect', function () {
    $entry = entryIn('pages', 'about');

    EntryScheduleReached::dispatch(Entry::find($entry->id()));

    expect(app(RedirectRepository::class)->all())->toHaveCount(0)
        ->and(file_exists(rulesFile()))->toBeFalse();
});
