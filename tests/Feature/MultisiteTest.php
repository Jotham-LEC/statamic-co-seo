<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use JothamLec\MarketingToolkit\Actions\CreateRedirect;
use JothamLec\MarketingToolkit\Actions\DeleteRecords;
use JothamLec\MarketingToolkit\IndexNow\IndexNow;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Reports\Report;
use JothamLec\MarketingToolkit\Reports\Runner;
use JothamLec\MarketingToolkit\SearchConsole\Client;
use JothamLec\MarketingToolkit\SearchConsole\Connection;
use JothamLec\MarketingToolkit\SearchConsole\SearchStat;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Package;
use JothamLec\MarketingToolkit\Widgets\SeoWidget;
use Statamic\Facades\Addon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/*
 * Statamic Pro with two sites on two domains: `default` (example.test) and
 * `cothinking` (cothink.test). Single-site behaviour is what every other test covers.
 */
beforeEach(fn () => multisite());

test('install puts the brand global on every site, the others inheriting from the default', function () {
    entryIn('home', 'home', ['description' => 'We make things.']);

    $this->artisan('statamic:mt:install')->assertSuccessful();
    $set = GlobalSet::findByHandle('seo');

    expect($set->origins()->all())->toBe(['default' => null, 'cothinking' => 'default'])
        ->and($set->in('default')->data()->all())->toMatchArray(['default_description' => 'We make things.'])
        // Left empty, so it follows the default site's values rather than a copy of them.
        ->and($set->in('cothinking')->data()->all())->toBe([])
        ->and($set->in('cothinking')->value('default_description'))->toBe('We make things.');
});

test('install names the sites an existing set is missing, and enables it there when asked', function () {
    Blueprint::make('seo')->setNamespace('globals')->setContents(['tabs' => []])->save();
    GlobalSet::make('seo')->title('SEO & brand')->sites(['default' => null])->save();
    $question = 'SEO & brand isn\'t enabled on: cothinking. Enable it there, taking what each leaves empty from the default site?';

    $this->artisan('statamic:mt:install')->expectsConfirmation($question, 'no')->expectsOutputToContain('Those sites use the addon\'s defaults')->assertSuccessful();
    expect(GlobalSet::findByHandle('seo')->sites()->all())->toBe(['default']);

    $this->artisan('statamic:mt:install', ['--no-interaction' => true])->expectsOutputToContain('cothinking')->assertSuccessful();
    expect(GlobalSet::findByHandle('seo')->sites()->all())->toBe(['default']);

    $this->artisan('statamic:mt:install')->expectsConfirmation($question, 'yes')->assertSuccessful();
    expect(GlobalSet::findByHandle('seo')->origins()->all())->toBe(['default' => null, 'cothinking' => 'default']);
});

test('a site reads its own brand values, and what it leaves empty from its origin', function () {
    seoGlobal(['title_separator' => '|', 'twitter_handle' => 'acme', 'allow_ai_training' => false]);
    seoGlobal(['twitter_handle' => 'cothinking'], 'cothinking');

    $meta = metaFor(entryOn('cothinking', 'pages', 'about'), 'https://cothink.test/about');

    expect($meta->title)->toBe('About | CoThinking')
        ->and($meta->twitterSite)->toBe('@cothinking');

    $this->get('https://cothink.test/robots.txt')->assertSee('User-agent: GPTBot');
    expect(metaFor(entryIn('pages', 'about'))->twitterSite)->toBe('@acme');
});

test('one process serves each site its own meta, whichever site it served first', function () {
    seoGlobal(['title_separator' => '|', 'default_description' => 'Acme makes things.']);
    seoGlobal(['title_separator' => '–', 'default_description' => 'CoThinking builds websites.'], 'cothinking');
    entryIn('pages', 'about');
    entryOn('cothinking', 'pages', 'about');

    foreach ([['https://example.test', 'About | Acme', 'Acme makes things.'], ['https://cothink.test', 'About – CoThinking', 'CoThinking builds websites.'], ['https://example.test', 'About | Acme', 'Acme makes things.']] as [$home, $title, $description]) {
        $this->get($home.'/about')->assertOk()
            ->assertSee('<title>'.e($title).'</title>', false)
            ->assertSee('<meta name="description" content="'.e($description).'">', false)
            ->assertSee('<link rel="canonical" href="'.$home.'/about">', false);
    }
});

test('each domain\'s sitemap lists that site\'s pages and terms only', function () {
    config(['marketing-toolkit.sitemap.taxonomies' => ['topics']]);
    Collection::make('services')->routes('services/{slug}')->sites(['cothinking'])->taxonomies(['topics'])->save();
    Taxonomy::make('topics')->termTemplate('default')->sites(['default', 'cothinking'])->save();
    Term::make()->taxonomy('topics')->slug('gardens')->dataForLocale('default', ['title' => 'Gardens'])->dataForLocale('cothinking', ['title' => 'Gardens'])->save();

    entryIn('pages', 'about');
    entryOn('cothinking', 'pages', 'hello');
    entryOn('cothinking', 'services', 'websites', ['topics' => ['gardens']]);

    $cothinking = $this->get('https://cothink.test/sitemap.xml')->assertOk()->getContent();
    $default = $this->get('https://example.test/sitemap.xml')->assertOk()->getContent();

    expect($cothinking)->toContain('https://cothink.test/hello', 'https://cothink.test/services/websites', 'https://cothink.test/topics/gardens')
        ->not->toContain('example.test')
        ->and($default)->toContain('https://example.test/about')
        ->not->toContain('cothink.test')
        // No published entry on this site uses the term.
        ->not->toContain('/topics/gardens');
});

test('IndexNow gets one request per domain, each with that domain\'s key file', function () {
    Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
    $key = app(IndexNow::class)->key();

    entryIn('pages', 'about');
    entryOn('cothinking', 'pages', 'hello');
    entryOn('cothinking', 'pages', 'work');
    app()->terminate();

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request) => $request['host'] === 'example.test'
        && $request['keyLocation'] === "https://example.test/{$key}.txt"
        && $request['urlList'] === ['https://example.test/about']);
    Http::assertSent(fn (HttpRequest $request) => $request['host'] === 'cothink.test'
        && $request['keyLocation'] === "https://cothink.test/{$key}.txt"
        && $request['urlList'] === ['https://cothink.test/hello', 'https://cothink.test/work']);

    expect($this->get("https://cothink.test/{$key}.txt")->assertOk()->getContent())->toBe($key);
});

describe('redirects', function () {
    test('a rule applies on its own site; a rule for every site on each; a site\'s own wins', function () {
        redirectRule(['source' => '/old', 'target' => '/everywhere']);
        redirectRule(['site' => 'cothinking', 'source' => '/old', 'target' => '/here']);
        redirectRule(['site' => 'cothinking', 'source' => '/only-there', 'target' => '/there']);
        redirectRule(['site' => 'cothinking', 'source' => '/blog/*', 'target' => '/notes/$1']);
        redirectRule(['source' => '/blog/*', 'target' => '/essays/$1']);

        $this->get('https://cothink.test/old')->assertRedirect('https://cothink.test/here');
        $this->get('https://example.test/old')->assertRedirect('https://example.test/everywhere');
        $this->get('https://cothink.test/only-there')->assertRedirect('https://cothink.test/there');
        $this->get('https://example.test/only-there')->assertNotFound();
        $this->get('https://cothink.test/blog/x')->assertRedirect('https://cothink.test/notes/x');
        $this->get('https://example.test/blog/x')->assertRedirect('https://example.test/essays/x');
    });

    test('one rule per address per site, and loops are looked for on the sites a rule applies on', function () {
        redirectRule(['site' => 'cothinking', 'source' => '/b', 'target' => '/a']);

        expect(Rule::validator(['site' => 'default', 'source' => '/b', 'target' => '/c', 'status' => 301])->passes())->toBeTrue()
            ->and(Rule::validator(['site' => 'cothinking', 'source' => '/b', 'target' => '/c', 'status' => 301])->errors()->first('source'))->toContain('already starts')
            ->and(Rule::validator(['site' => 'elsewhere', 'source' => '/z', 'target' => '/c', 'status' => 301])->errors()->has('site'))->toBeTrue()
            // On the other site nothing leads back.
            ->and(Rule::validator(['site' => 'default', 'source' => '/a', 'target' => '/b', 'status' => 301])->passes())->toBeTrue()
            ->and(Rule::validator(['site' => 'cothinking', 'source' => '/a', 'target' => '/b', 'status' => 301])->errors()->first('target'))->toContain('loop')
            // A rule for every site would loop on cothinking.
            ->and(Rule::validator(['source' => '/a', 'target' => '/b', 'status' => 301])->errors()->first('target'))->toContain('loop');
    });

    test('ignoring letter case, an address is still one per site, matched on its own site', function () {
        config(['marketing-toolkit.redirects.case_sensitive' => false]);
        $this->actingAs(cpUser(['manage marketing toolkit redirects', 'access default site', 'access cothinking site']));
        redirectRule(['site' => 'cothinking', 'source' => '/About', 'target' => '/here']);
        redirectRule(['source' => '/about', 'target' => '/everywhere']);

        expect(Rule::validator(['site' => 'cothinking', 'source' => '/ABOUT', 'target' => '/x', 'status' => 301])->errors()->first('source'))->toContain('already starts')
            ->and(Rule::validator(['site' => 'default', 'source' => '/ABOUT', 'target' => '/x', 'status' => 301])->passes())->toBeTrue()
            ->and(Rule::validator(['site' => 'cothinking', 'source' => '/b', 'target' => '/ABOUT', 'status' => 301])->passes())->toBeTrue();

        redirectRule(['site' => 'cothinking', 'source' => '/here', 'target' => '/b']);
        expect(Rule::validator(['site' => 'cothinking', 'source' => '/B', 'target' => '/ABOUT', 'status' => 301])->errors()->first('target'))->toContain('loop');

        // The site's own rule, though another case, wins over the one for every site in the very case asked.
        $this->get('https://cothink.test/about')->assertRedirect('https://cothink.test/here');
        $this->get('https://example.test/ABOUT')->assertRedirect('https://example.test/everywhere');

        $csv = "source,target,status,active,site\n/ABOUT,/updated,301,1,cothinking\n";
        $result = $this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk()->json();

        expect($result)->toMatchArray(['created' => 0, 'updated' => 1])
            ->and(app(RedirectRepository::class)->onSite('cothinking')->first()->target)->toBe('/updated')
            ->and(app(RedirectRepository::class)->onSite(null)->first()->target)->toBe('/everywhere');
    });

    test('the form offers the sites, and a redirect made from a site\'s 404 starts on that site', function () {
        $this->actingAs(cpUser(['manage marketing toolkit redirects', 'access default site', 'access cothinking site']));

        $this->get(cp_route('mt.redirects.create', ['source' => '/missing', 'site' => 'cothinking']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('values.site', 'cothinking')
                ->where('blueprint.tabs.0.sections.0.fields.4.handle', 'site'));

        $this->postJson(cp_route('mt.redirects.store'), ['site' => 'cothinking', 'source' => '/missing', 'target' => '/found', 'status' => '301', 'active' => true])->assertOk();
        $this->postJson(cp_route('mt.redirects.store'), ['source' => '/missing', 'target' => '/found', 'status' => '301', 'active' => true])->assertOk();

        expect(redirectRules()->pluck('site')->all())->toEqualCanonicalizing(['cothinking', null])
            ->and($this->getJson(cp_route('mt.redirects.listing', ['sort' => 'id']))->json('meta.columns.*.field'))->toContain('site');
    });

    test('CSV carries each rule\'s site', function () {
        $this->actingAs(cpUser(['manage marketing toolkit redirects', 'access default site', 'access cothinking site']));
        redirectRule(['source' => '/one', 'target' => '/1']);
        redirectRule(['site' => 'cothinking', 'source' => '/one', 'target' => '/uno']);

        expect($this->get(cp_route('mt.redirects.export'))->streamedContent())->toBe("source,target,status,active,site\n/one,/1,301,1,\n/one,/uno,301,1,cothinking\n");

        $csv = "source,target,status,active,site\n/one,/eins,301,1,cothinking\n/two,/2,301,1,cothinking\n/three,/3,301,1,nowhere\n";
        $result = $this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk()->json();

        expect($result)->toMatchArray(['created' => 1, 'updated' => 1])
            ->and($result['errors'])->toHaveCount(1)
            ->and(ruleRows(['site', 'source', 'target']))->toBe([
                ['site' => null, 'source' => '/one', 'target' => '/1'],
                ['site' => 'cothinking', 'source' => '/one', 'target' => '/eins'],
                ['site' => 'cothinking', 'source' => '/two', 'target' => '/2'],
            ]);
    });

    test('a user who may work on one site manages only its rules: those for every site apply on sites they can\'t see', function () {
        $this->actingAs(cpUser(['manage marketing toolkit redirects', 'access cothinking site']));
        $theirs = redirectRule(['site' => 'cothinking', 'source' => '/theirs', 'target' => '/a']);
        $everywhere = redirectRule(['source' => '/everywhere', 'target' => '/b']);
        $other = redirectRule(['site' => 'default', 'source' => '/other', 'target' => '/c']);
        $form = fn (array $values = []) => ['source' => '/x', 'target' => '/y', 'status' => '301', 'active' => true, ...$values];

        expect($this->getJson(cp_route('mt.redirects.listing', ['sort' => 'id']))->json('data.*.source'))->toBe(['/theirs'])
            ->and($this->get(cp_route('mt.redirects.export'))->streamedContent())->not->toContain('/other')->not->toContain('/everywhere');

        foreach ([$other, $everywhere] as $redirect) {
            $this->get(cp_route('mt.redirects.edit', $redirect->id))->assertNotFound();
            $this->patchJson(cp_route('mt.redirects.update', $redirect->id), $form(['source' => $redirect->source, 'site' => 'cothinking']))->assertNotFound();
            $this->postJson(cp_route('mt.actions.run'), ['action' => DeleteRecords::handle(), 'selections' => [$redirect->id], 'context' => ['type' => 'redirects'], 'values' => []])->assertNotFound();
        }

        $this->postJson(cp_route('mt.redirects.store'), $form(['site' => 'default']))->assertJsonValidationErrors('site');
        $this->postJson(cp_route('mt.redirects.store'), $form(['site' => null]))->assertJsonValidationErrors(['site' => 'Only someone who may work on every site']);
        $this->patchJson(cp_route('mt.redirects.update', $theirs->id), $form(['source' => '/theirs', 'site' => null]))->assertJsonValidationErrors('site');

        // A new rule's form starts on their site, with no "every site" to choose.
        $this->get(cp_route('mt.redirects.create'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('values.site', 'cothinking')
            ->where('blueprint.tabs.0.sections.0.fields.4.clearable', false));

        $this->get(cp_route('mt.redirects.edit', $theirs->id))->assertOk();
        $this->patchJson(cp_route('mt.redirects.update', $theirs->id), $form(['source' => '/theirs', 'target' => '/a2', 'site' => 'cothinking']))->assertOk();
        $this->postJson(cp_route('mt.actions.run'), ['action' => DeleteRecords::handle(), 'selections' => [$theirs->id], 'context' => ['type' => 'redirects'], 'values' => []])->assertOk();

        // A row for every site (no site) is refused, as for another site.
        $csv = "source,target,status,active,site\n/mine,/1,301,1,cothinking\n/not-mine,/2,301,1,default\n/everyone,/3,301,1,\n/everywhere,/4,301,1,\n";
        $result = $this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk()->json();

        expect($result)->toMatchArray(['created' => 1, 'updated' => 0])
            ->and($result['errors'])->toHaveCount(3)
            ->and(ruleRows(['site', 'source', 'target']))->toBe([
                ['site' => null, 'source' => '/everywhere', 'target' => '/b'],
                ['site' => 'cothinking', 'source' => '/mine', 'target' => '/1'],
                ['site' => 'default', 'source' => '/other', 'target' => '/c'],
            ]);
    });

    test('a user who may work on every site manages the rules for every site', function () {
        $this->actingAs(cpUser(['manage marketing toolkit redirects', 'access default site', 'access cothinking site']));
        $everywhere = redirectRule(['source' => '/everywhere', 'target' => '/b']);

        expect($this->getJson(cp_route('mt.redirects.listing'))->json('data.*.source'))->toBe(['/everywhere']);

        $this->get(cp_route('mt.redirects.create'))->assertInertia(fn (AssertableInertia $page) => $page->where('values.site', null));
        $this->patchJson(cp_route('mt.redirects.update', $everywhere->id), ['source' => '/everywhere', 'target' => '/b2', 'status' => '301', 'active' => true])->assertOk();
        $this->postJson(cp_route('mt.redirects.store'), ['source' => '/new', 'target' => '/n', 'status' => '301', 'active' => true])->assertOk();

        expect(app(RedirectRepository::class)->onSite(null)->pluck('target')->sort()->values()->all())->toBe(['/b2', '/n']);
    });
})->group('redirects');

describe('automatic redirects', function () {
    test('a moved page\'s 301 is for its own site, and only that site\'s rules change', function () {
        redirectRule(['site' => 'default', 'source' => '/older', 'target' => '/about']);
        redirectRule(['site' => 'default', 'source' => '/about-us', 'target' => '/elsewhere']);
        redirectRule(['site' => 'cothinking', 'source' => '/oldest', 'target' => '/about']);
        $entry = entryOn('cothinking', 'pages', 'about');

        Entry::find($entry->id())->syncOriginal()->slug('about-us')->save();

        expect(ruleRows(['site', 'source', 'target', 'automatic']))->toBe([
            ['site' => 'cothinking', 'source' => '/about', 'target' => '/about-us', 'automatic' => true],
            ['site' => 'cothinking', 'source' => '/oldest', 'target' => '/about-us', 'automatic' => false],
            // The default site's page at /about hasn't moved, and nothing changed /about-us there.
            ['site' => 'default', 'source' => '/about-us', 'target' => '/elsewhere', 'automatic' => false],
            ['site' => 'default', 'source' => '/older', 'target' => '/about', 'automatic' => false],
        ]);

        $this->get('https://cothink.test/about')->assertRedirect('https://cothink.test/about-us');
    });

    test('a renamed term leaves a 301 on each site it moved on', function () {
        Taxonomy::make('topics')->termTemplate('default')->sites(['default', 'cothinking'])->save();
        tap(Term::make()->taxonomy('topics')->slug('gardens')->dataForLocale('default', ['title' => 'Gardens'])->dataForLocale('cothinking', ['title' => 'Gardens']))->save();

        Term::find('topics::gardens')->term()->syncOriginal()->slug('gardening')->save();

        expect(ruleRows(['site', 'source', 'target']))->toBe([
            ['site' => 'cothinking', 'source' => '/topics/gardens', 'target' => '/topics/gardening'],
            ['site' => 'default', 'source' => '/topics/gardens', 'target' => '/topics/gardening'],
        ]);
    });

    test('a renamed term of a taxonomy on another site only still leaves its 301', function () {
        Taxonomy::make('topics')->termTemplate('default')->sites(['cothinking'])->save();
        tap(Term::make()->taxonomy('topics')->slug('gardens')->dataForLocale('cothinking', ['title' => 'Gardens']))->save();

        Term::find('topics::gardens')->term()->syncOriginal()->slug('gardening')->save();

        expect(ruleRows(['site', 'source', 'target']))->toBe([
            ['site' => 'cothinking', 'source' => '/topics/gardens', 'target' => '/topics/gardening'],
        ]);
    });

    test('a page moved in one site\'s tree redirects on that site', function () {
        $collection = Collection::make('docs')->routes('{parent_uri}/{slug}')->sites(['default', 'cothinking'])->structureContents(['max_depth' => 3])->save();
        $guide = entryOn('cothinking', 'docs', 'guide');
        $setup = entryOn('cothinking', 'docs', 'setup');
        $collection->structure()->in('cothinking')->tree([['entry' => $guide->id()], ['entry' => $setup->id()]])->save();

        $tree = $collection->structure()->in('cothinking')->syncOriginal();
        $tree->tree([['entry' => $guide->id(), 'children' => [['entry' => $setup->id()]]]])->save();

        expect(ruleRows(['site', 'source', 'target']))->toBe([
            ['site' => 'cothinking', 'source' => '/setup', 'target' => '/guide/setup'],
        ]);
    });
})->group('redirects');

describe('reports', function () {
    beforeEach(fn () => seoGlobal([]));

    test('the command reports on each site in turn, each of its own pages', function () {
        entryIn('pages', 'about');
        entryOn('cothinking', 'pages', 'hello');

        $this->artisan('statamic:mt:report')->assertSuccessful();

        $reports = Report::query()->orderBy('id')->get();

        expect($reports->pluck('site')->all())->toBe(['default', 'cothinking'])
            ->and($reports[0]->pages()->pluck('url')->all())->toBe(['https://example.test/about'])
            ->and($reports[1]->pages()->pluck('url')->all())->toBe(['https://cothink.test/hello']);

        $this->artisan('statamic:mt:report', ['--site' => 'cothinking'])->assertSuccessful();
        $this->artisan('statamic:mt:report', ['--site' => 'nowhere'])->assertFailed();

        expect(Report::query()->orderBy('id')->pluck('site')->all())->toBe(['default', 'cothinking', 'cothinking']);
    });

    test('links are checked against the report\'s own site, its pages and its redirects', function () {
        entryIn('pages', 'only-here');
        entryOn('cothinking', 'pages', 'work');
        redirectRule(['site' => 'cothinking', 'source' => '/old-work', 'target' => '/work']);
        entryOn('cothinking', 'pages', 'hello', ['body' => '<a href="/work">w</a> <a href="/old-work">o</a> <a href="/only-here">h</a> <a href="https://example.test/only-here">x</a> <a href="https://cothink.test/work">c</a>']);

        $runner = app(Runner::class);
        $report = $runner->runToEnd($runner->start(site: 'cothinking'));
        $facts = $report->pages()->where('url', 'https://cothink.test/hello')->sole()->facts();

        expect($facts->brokenLinks)->toBe(['/only-here'])
            ->and($facts->redirectedLinks)->toBe(['/old-work'])
            ->and($facts->externalLinks)->toBe(['https://example.test/only-here'])
            ->and($facts->internalLinks)->toBe(['/work', '/old-work', '/only-here']);
    });

    test('a report running on one site doesn\'t stop another site\'s from starting', function () {
        entryIn('pages', 'about');
        entryOn('cothinking', 'pages', 'hello');
        $runner = app(Runner::class);

        $default = $runner->start(site: 'default');
        $cothinking = $runner->start(site: 'cothinking');

        expect($cothinking->id)->not->toBe($default->id)
            ->and($runner->start(site: 'cothinking')->id)->toBe($cothinking->id);
    });

    test('the control panel runs and lists the selected site\'s reports', function () {
        entryOn('cothinking', 'pages', 'hello');
        Report::query()->create(['site' => 'default', 'settings' => [], 'status' => Report::DONE]);
        $this->actingAs(cpUser(['view marketing toolkit', 'run marketing toolkit reports']));
        session(['statamic.cp.selected-site' => 'cothinking']);

        $this->postJson(cp_route('mt.reports.run'))->assertOk();

        expect(Report::query()->latest('id')->first()->site)->toBe('cothinking');
        $this->get(cp_route('mt.reports.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('reports', 1));
    });

    test('another site\'s report is not found while a site is selected, though one from before there were sites is', function () {
        $other = Report::query()->create(['site' => 'default', 'settings' => [], 'status' => Report::RUNNING, 'pages_total' => 1]);
        $older = Report::query()->create(['site' => null, 'settings' => [], 'status' => Report::DONE]);
        $this->actingAs(cpUser(['view marketing toolkit', 'run marketing toolkit reports']));
        session(['statamic.cp.selected-site' => 'cothinking']);

        $this->get(cp_route('mt.reports.show', $other))->assertNotFound();
        $this->getJson(cp_route('mt.reports.pages', $other))->assertNotFound();
        $this->postJson(cp_route('mt.reports.progress', $other))->assertNotFound();
        $this->get(cp_route('mt.reports.show', $older))->assertOk();

        session(['statamic.cp.selected-site' => 'default']);
        $this->get(cp_route('mt.reports.show', $other))->assertOk();
    });
});

test('the 404 log keeps each site\'s misses apart, and a redirect made from one starts on its site', function () {
    // Kept out of a static cache, so the next visit is counted (and redirected) too.
    $this->get('https://cothink.test/missing')->assertNotFound()->assertHeader('X-Statamic-Uncacheable', 'true');
    $this->get('https://cothink.test/missing')->assertNotFound();
    $this->get('https://example.test/missing')->assertNotFound();

    expect(MissingPath::query()->orderBy('site')->get(['site', 'path', 'hits'])->toArray())->toBe([
        ['site' => 'cothinking', 'path' => '/missing', 'hits' => 2],
        ['site' => 'default', 'path' => '/missing', 'hits' => 1],
    ]);

    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects']));
    session(['statamic.cp.selected-site' => 'cothinking']);
    $listing = $this->getJson(cp_route('mt.404s.listing'));

    expect($listing->json('data.*.site'))->toBe(['CoThinking'])
        ->and($listing->json('meta.columns.*.field'))->toContain('site');

    $row = MissingPath::query()->where('site', 'cothinking')->sole();
    $this->postJson(cp_route('mt.actions.run'), ['action' => CreateRedirect::handle(), 'selections' => [$row->id], 'context' => ['type' => '404s'], 'values' => []])
        ->assertJsonPath('redirect', cp_route('mt.redirects.create', ['source' => '/missing', 'site' => 'cothinking']));
});

test('the overview and the dashboard widget are of the site selected in the control panel', function () {
    seoGlobal(['title_separator' => '|']);
    seoGlobal(['title_separator' => '–'], 'cothinking');
    redirectRule(['source' => '/everywhere', 'target' => '/x']);
    redirectRule(['site' => 'cothinking', 'source' => '/here', 'target' => '/x']);
    redirectRule(['site' => 'default', 'source' => '/there', 'target' => '/x']);
    MissingPath::query()->create(['site' => 'default', 'path' => '/lost', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    MissingPath::query()->create(['site' => 'cothinking', 'path' => '/gone', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    Report::query()->create(['site' => 'default', 'settings' => [], 'status' => Report::DONE, 'score' => 50, 'summary' => ['scored' => 1]]);
    $this->actingAs(cpUser(super: true));
    session(['statamic.cp.selected-site' => 'cothinking']);

    $this->get(cp_route('mt.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('siteName', 'CoThinking')
            ->where('global.separator', ' – ')
            ->where('files.0.url', 'https://cothink.test/sitemap.xml')
            ->where('report.latest', null)
            ->where('redirects.active', 2)
            ->where('notFound.paths', 1)
            ->where('notFound.recent.0.path', '/gone'));

    $props = (new SeoWidget)->component()->toArray()['props'];

    expect($props['report'])->toBeNull()
        ->and(collect($props['notFound'])->pluck('path')->all())->toBe(['/gone']);
})->group('redirects');

describe('the control panel preview and share cards', function () {
    beforeEach(function () {
        seoGlobal(['title_separator' => '|', 'og_background' => '#111111']);
        seoGlobal(['title_separator' => '–', 'og_background' => '#fad03a'], 'cothinking');
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['import' => 'marketing-toolkit::seo']]])->save();
    });

    test('a page is previewed as its own site shows it, from whichever domain the control panel is on', function () {
        $this->actingAs(cpUser(super: true));
        $entry = entryOn('cothinking', 'pages', 'about');

        $this->postJson(cp_route('mt.preview.meta'), ['blueprint' => 'collections.pages.page', 'reference' => $entry->reference(), 'site' => 'cothinking', 'values' => ['title' => 'About', 'slug' => 'about']])
            ->assertOk()
            ->assertJson([
                'title' => 'About – CoThinking',
                'url' => 'https://cothink.test/about',
                'site_name' => 'CoThinking',
                'image' => ['url' => app(SiteSeo::class)->generatedImageUrl($entry), 'generated' => true],
            ]);

        expect(app(SiteSeo::class)->generatedImageUrl($entry))->toStartWith('https://cothink.test/og/about.png?v=');
    });

    test('a card has its site\'s colours and its site\'s mount page title as the label', function () {
        $collection = Collection::make('services')->routes('{mount}/{slug}')->sites(['default', 'cothinking'])->save();
        $mount = entryIn('pages', 'services', ['title' => 'Services']);
        $local = Entry::make()->collection('pages')->locale('cothinking')->origin($mount->id())->slug('services')->data(['title' => 'What I do']);
        $local->save();
        $collection->mount($mount->id())->save();

        $card = app(Generator::class)->card(entryOn('cothinking', 'services', 'websites'));

        expect([$card->label, $card->siteName, $card->background])->toBe(['What I do', 'CoThinking', '#fad03a']);
    });
});

describe('Search Console', function () {
    beforeEach(function () {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $private);
        config(['marketing-toolkit.search_console' => ['credentials' => json_encode(['type' => 'service_account', 'client_email' => 'seo@project.iam.gserviceaccount.com', 'private_key' => $private]), 'property' => null, 'days' => 28]]);

        $row = fn (string $url, int $clicks) => ['keys' => [$url], 'clicks' => $clicks, 'impressions' => 10, 'ctr' => 0.1, 'position' => 2.0];
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3599]),
            'www.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.test/searchAnalytics/query' => Http::response(['rows' => [$row('https://example.test/', 5), $row('https://cothink.test/', 99)]]),
            'www.googleapis.com/webmasters/v3/sites/sc-domain%3Acothink.test/searchAnalytics/query' => Http::response(['rows' => [$row('https://cothink.test/', 7), $row('https://cothink.test/work', 2)]]),
        ]);
    });

    afterEach(fn () => File::delete(resource_path('addons/marketing-toolkit.yaml')));

    test('each site imports its own property, and the overview shows the selected site\'s numbers', function () {
        config(['marketing-toolkit.search_console.property' => ['default' => 'sc-domain:example.test', 'cothinking' => 'sc-domain:cothink.test']]);

        $this->artisan('statamic:mt:search-console')->assertSuccessful();

        expect(SearchStat::query()->orderBy('site')->orderBy('url')->get(['site', 'url', 'clicks'])->toArray())->toBe([
            ['site' => 'cothinking', 'url' => 'https://cothink.test/', 'clicks' => 7],
            ['site' => 'cothinking', 'url' => 'https://cothink.test/work', 'clicks' => 2],
            // A property's pages on another site's domain aren't this site's.
            ['site' => 'default', 'url' => 'https://example.test/', 'clicks' => 5],
        ]);

        $this->actingAs(cpUser(super: true));
        session(['statamic.cp.selected-site' => 'cothinking']);

        $this->get(cp_route('mt.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('search.clicks', 9));
        $this->get(cp_route('mt.search-console.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setup.property', 'sc-domain:cothink.test')
            ->where('setup.property_source', 'env')
            ->where('imported.pages', 2)
            ->where('sites.1', ['name' => 'CoThinking', 'property' => 'sc-domain:cothink.test', 'connected' => true, 'selected' => true]));

        $this->artisan('statamic:mt:search-console', ['--site' => 'default'])->assertSuccessful();
        expect(SearchStat::query()->count())->toBe(3);
    });

    test('sites on one domain, one under another\'s path, each keep their own pages of a shared property', function () {
        multilang();
        config(['marketing-toolkit.search_console.property' => 'https://www.example.test/']);
        $row = fn (string $url, int $clicks) => ['keys' => [$url], 'clicks' => $clicks, 'impressions' => 10, 'ctr' => 0.1, 'position' => 2.0];
        Http::fake(['www.googleapis.com/webmasters/v3/sites/https%3A%2F%2Fwww.example.test%2F/searchAnalytics/query' => Http::response(['rows' => [
            $row('https://www.example.test/', 1),
            $row('https://example.test/about', 2),
            $row('http://example.test/fr', 3),
            $row('https://example.test/fr/a-propos', 4),
            $row('https://example.test/uk/about', 5),
            $row('https://example.test/fresh', 6),
            $row('https://de.example.test/uber', 7),
        ]])]);

        $this->artisan('statamic:mt:search-console')->assertSuccessful();

        expect(SearchStat::query()->orderBy('clicks')->get(['site', 'clicks'])->map(fn ($stat) => [$stat->site, $stat->clicks])->all())->toBe([
            ['default', 1], ['default', 2], ['fr', 3], ['fr', 4], ['uk', 5], ['default', 6], ['de', 7],
        ]);
    });

    test('the control panel sets up the selected site\'s property; the default site\'s stays where it was', function () {
        $this->actingAs(cpUser(super: true));
        session(['statamic.cp.selected-site' => 'cothinking']);

        $this->get(cp_route('mt.search-console.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('setup.configured', false)
            ->where('setup.suggested_property', 'sc-domain:cothink.test'));

        $this->postJson(cp_route('mt.search-console.property'), ['property' => 'sc-domain:cothink.test'])->assertOk();
        $this->postJson(cp_route('mt.search-console.import'))->assertOk()->assertJson(['ok' => true, 'message' => 'Imported 2 pages.']);

        $settings = Addon::get(Package::NAME)->settings();

        expect($settings->get(Connection::SITES_SETTING))->toBe(['cothinking' => 'sc-domain:cothink.test'])
            ->and($settings->get(Connection::SETTING))->toBeNull()
            ->and(app(Client::class)->configured('default'))->toBeFalse()
            ->and(app(Client::class)->configured('cothinking'))->toBeTrue()
            ->and(SearchStat::query()->pluck('site')->unique()->all())->toBe(['cothinking']);

        // The command imports the sites that have a property.
        $this->artisan('statamic:mt:search-console')->assertSuccessful();
        Http::assertSentCount(3);
    });
});
