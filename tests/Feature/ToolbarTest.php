<?php

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use JothamLec\MarketingToolkit\Reports\Report;
use JothamLec\MarketingToolkit\SearchConsole\Client as SearchConsoleClient;
use JothamLec\MarketingToolkit\SearchConsole\SearchStat;
use JothamLec\MarketingToolkit\Toolbar\Toolbar;
use Statamic\CP\Color;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Preference;
use Statamic\Facades\Token;
use Statamic\Facades\User;
use Statamic\StaticCaching\Cacher;
use Statamic\Tokens\Handlers\LivePreview;

beforeEach(function () {
    // Its own layout, with <s:mt:body />, so other tests' pages stay as they are.
    entryIn('pages', 'about', ['layout' => 'toolbar-layout']);
});

function toolbarCookie(TestResponse $response): ?Symfony\Component\HttpFoundation\Cookie
{
    // A test's requests share one cookie jar, which a real request starts afresh.
    Cookie::flushQueuedCookies();

    return collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === Toolbar::COOKIE);
}

test('every visitor gets the same guard, which names the script and the endpoint by path', function () {
    $guest = $this->get('https://example.test/about')->assertOk()->getContent();

    $this->actingAs(cpUser(super: true));
    $signedIn = $this->get('https://example.test/about')->assertOk()->getContent();

    expect($guest)->toBe($signedIn)
        ->toContain('mt_toolbar=1')
        ->toContain('self===top')
        ->toContain('data-src="/vendor/statamic-marketing-toolkit/build/toolbar.js?v=')
        ->toContain('data-endpoint="/!/marketing-toolkit/toolbar"')
        ->not->toContain('editor@example.test')
        ->not->toContain('super@example.test');
});

test('the guard is left out with the toolbar off, and in Live Preview', function () {
    expect(renderAt('/', '<s:mt:body />'))->toContain('mt_toolbar=1');

    $token = Token::make(null, LivePreview::class);
    $token->save();
    expect(renderAt('/?token='.$token->token(), '<s:mt:body />'))->not->toContain('mt_toolbar');

    config(['marketing-toolkit.toolbar.enabled' => false]);
    expect(renderAt('/', '<s:mt:body />'))->toBe('');
});

test('<s:mt:toolbar /> prints the guard alone, with the CSP nonce', function () {
    Vite::useCspNonce('abc123');

    expect(trim(renderAt('/', '<s:mt:toolbar />')))->toStartWith('<script nonce="abc123" data-src=')
        ->toEndWith('>'.Toolbar::SCRIPT.'</script>');
});

test('the guard\'s code is the same in every version, so a Content Security Policy can allow it by the hash the docs give', function () {
    preg_match('#<script[^>]*>(.*?)</script>#', renderAt('/', '<s:mt:toolbar />'), $match);
    $hash = "'sha256-".base64_encode(hash('sha256', $match[1], true))."'";

    expect($match[1])->toBe(Toolbar::SCRIPT)->not->toContain('?v=')
        ->and(file_get_contents(__DIR__.'/../../docs/configuration.md'))->toContain($hash);
});

test('a page cached while a control panel user is signed in holds nothing of theirs', function () {
    config(['statamic.static_caching.strategy' => 'half']);
    $this->actingAs(cpUser(super: true));

    $first = $this->get('https://example.test/about')->assertOk()->getContent();
    Auth::logout();
    $cached = $this->get('https://example.test/about')->assertOk()->getContent();

    expect($cached)->toBe($first)->not->toContain('super@example.test');
});

test('signing in sets the marker cookie for a control panel user, and not for anyone else', function () {
    $user = cpUser(super: true);
    event(new Login('web', $user, false));
    expect(Cookie::queued(Toolbar::COOKIE)?->getValue())->toBe('1')
        ->and(Cookie::queued(Toolbar::COOKIE)->isHttpOnly())->toBeFalse()
        ->and(Cookie::queued(Toolbar::COOKIE)->getSameSite())->toBe('lax');
});

test('a user who may not use the control panel gets no marker cookie', function () {
    $member = User::make()->email('member@example.test');
    $member->save();

    event(new Login('web', $member, false));

    expect(Cookie::queued(Toolbar::COOKIE))->toBeNull();
});

test('a control panel request sets the cookie once, and takes it away once the toolbar is off', function () {
    $user = cpUser(super: true);
    $this->actingAs($user);

    expect(toolbarCookie($this->get(cp_route('dashboard')))?->getValue())->toBe('1')
        ->and(toolbarCookie($this->withUnencryptedCookie(Toolbar::COOKIE, '1')->get(cp_route('dashboard'))))->toBeNull();

    config(['marketing-toolkit.toolbar.enabled' => false]);
    $forgotten = toolbarCookie($this->withUnencryptedCookie(Toolbar::COOKIE, '1')->get(cp_route('dashboard')));

    expect($forgotten?->getValue())->toBe('')
        ->and($forgotten->getExpiresTime())->toBeLessThan(time());
});

test('signing out removes the marker cookie', function () {
    event(new Logout('web', cpUser(super: true)));

    expect(Cookie::queued(Toolbar::COOKIE)?->getExpiresTime())->toBeLessThan(time());
});

/**
 * What the toolbar's endpoint answers about $url (a path on the default site, or a full address).
 *
 * @param  array<string, mixed>  $query
 */
function toolbarFor(string $url, array $query = []): TestResponse
{
    return test()->getJson('https://example.test/!/marketing-toolkit/toolbar?'.http_build_query(['url' => absoluteTestUrl($url), ...$query]));
}

/**
 * A finished report on $site, with a row for $entry.
 *
 * @param  array<string, array<string, mixed>>  $results
 */
function reportWith(Statamic\Contracts\Entries\Entry $entry, array $results = [], ?string $site = null, int $score = 72, string $finished = '2026-10-01 12:00:00'): Report
{
    $report = Report::query()->create([
        'site' => $site,
        'settings' => [],
        'status' => Report::DONE,
        'score' => $score,
        'finished_at' => $finished,
        'summary' => ['rules' => [
            'title_length' => ['label' => 'marketing-toolkit::reports.rules.title_length', 'weight' => 2, 'fail' => 1, 'warn' => 0],
            'description_length' => ['label' => 'marketing-toolkit::reports.rules.description_length', 'weight' => 3, 'fail' => 0, 'warn' => 1],
            'broken_links' => ['label' => 'marketing-toolkit::reports.rules.broken_links', 'weight' => 5, 'fail' => 1, 'warn' => 0],
        ]],
    ]);
    $report->pages()->create([
        'url' => $entry->absoluteUrl(), 'content_type' => 'entry', 'content_id' => $entry->id(),
        'score' => $score, 'checked' => true, 'results' => $results,
    ]);

    return $report;
}

test('without a user who gets the toolbar, the endpoint answers 401 and expires the cookie; nothing it says is cached', function () {
    $response = toolbarFor('/about')->assertUnauthorized()
        ->assertJson(['message' => 'You’re no longer signed in, so the toolbar has closed.']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and(toolbarCookie($response)?->getExpiresTime())->toBeLessThan(time());

    $this->actingAs(cpUser(super: true));
    expect(toolbarFor('/about')->assertOk()->headers->get('Cache-Control'))->toContain('no-store');
});

test('with the toolbar off the endpoint answers 404, and a foreign address 422', function () {
    $this->actingAs(cpUser(super: true));

    expect(toolbarFor('https://evil.test/about')->assertStatus(422)->headers->get('Cache-Control'))->toContain('no-store');
    toolbarFor('javascript:alert(1)')->assertStatus(422);
    toolbarFor('https://example.test.evil.test/about')->assertStatus(422);

    config(['marketing-toolkit.toolbar.enabled' => false]);
    expect(toolbarFor('/about')->assertNotFound()->headers->get('Cache-Control'))->toContain('no-store');
});

test('a user who may only use the control panel gets the bar and the basics, and nothing behind another permission', function () {
    config(['statamic.static_caching.strategy' => 'half']);
    reportWith(Entry::findByUri('/about'));
    $this->actingAs(cpUser(['view pages entries']));

    toolbarFor('/about')->assertOk()
        ->assertJsonPath('page.type', 'entry')
        ->assertJsonPath('page.title', 'About')
        ->assertJsonPath('page.edit_url', null)
        ->assertJsonPath('page.seo_url', null)
        ->assertJsonPath('seo', null)
        ->assertJsonPath('preview', null)
        ->assertJsonPath('redirects', null)
        ->assertJsonPath('tracking', null)
        ->assertJsonPath('more.cache', false)
        ->assertJsonPath('user.color_mode', 'auto')
        ->assertJsonPath('user.labels.open', 'Open the Marketing Toolkit toolbar');
});

test('content the user may not view in the control panel is no content here: not its title, status, SEO or report', function () {
    entryIn('pages', 'secret-plan')->published(false)->save();
    reportWith(Entry::findByUri('/about'));
    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects']));

    foreach (['/secret-plan', '/about'] as $url) {
        toolbarFor($url)->assertOk()
            ->assertJsonPath('page.type', null)
            ->assertJsonPath('page.title', null)
            ->assertJsonPath('page.status', null)
            ->assertJsonPath('seo', null)
            ->assertJsonPath('preview', null);
    }
});

test('on a site the user may not work on, the toolbar shows nothing of that site: its content, report, redirects or tracking', function () {
    multilang();
    $about = Entry::findByUri('/about');
    reportWith(translationOf($about, 'fr', 'a-propos'), site: 'fr');
    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects', 'view pages entries', 'access default site']));

    toolbarFor('https://example.test/fr/a-propos')->assertOk()
        ->assertJsonPath('site.handle', 'fr')
        ->assertJsonPath('page.title', null)
        ->assertJsonPath('seo', null)
        ->assertJsonPath('redirects', null)
        ->assertJsonPath('tracking', null);

    toolbarFor('/about')->assertOk()->assertJsonPath('page.title', 'About');
});

test('the toolbar takes the user\'s control panel theme, with its references filled in and nothing that could break out of a style', function () {
    $user = cpUser(super: true);
    $user->setPreference('theme', ['colors' => [
        'ui-accent-bg' => 'oklch(0.5 0.2 30)',
        'ui-accent-text' => 'var(--theme-color-ui-accent-bg)',
        'content-bg' => 'red; } body { display: none',
        'dark-ui-accent-text' => '#fbbf24',
    ]])->save();
    $this->actingAs($user);

    $theme = toolbarFor('/about')->assertOk()->json('user.theme');

    expect($theme['light'])->toMatchArray(['accent' => 'oklch(0.5 0.2 30)', 'link' => 'oklch(0.5 0.2 30)', 'bg' => Color::Zinc[100]])
        ->and($theme['light'])->not->toHaveKey('surface')
        ->and($theme['dark'])->toMatchArray(['accent' => 'oklch(0.5 0.2 30)', 'link' => '#fbbf24']);
});

test('each permission brings its panel', function () {
    config(['statamic.static_caching.strategy' => 'half']);
    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects', 'access cache utility', 'edit pages entries']));

    toolbarFor('/about')->assertOk()
        ->assertJsonPath('page.edit_url', fn ($url) => str_contains($url, '/cp/collections/pages/entries/'))
        ->assertJsonPath('seo.messages.0', 'No report has been run on this site yet. Run one to score every page.')
        ->assertJsonPath('seo.run_url', null)
        ->assertJsonPath('preview.title', 'About')
        ->assertJsonPath('redirects.messages.0', 'No redirects send visitors to this page.')
        ->assertJsonPath('tracking.messages.0', 'No tracking tags are set up in Marketing settings.')
        ->assertJsonPath('more.cache', true);
});

test('"SEO" opens the tab that holds the seo field, by its handle in the blueprint, whatever the site named it', function () {
    Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => [
        'main' => ['sections' => [['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]]],
        'search_and_social' => ['sections' => [['fields' => [['import' => 'marketing-toolkit::seo']]]]],
    ]])->save();
    $this->actingAs(cpUser(super: true));

    toolbarFor('/about')->assertJsonPath('page.seo_url', fn ($url) => str_ends_with($url, '#search_and_social'));
});

test('a page in the latest report: its score, its checks worst first, each with where to fix it', function () {
    $entry = Entry::findByUri('/about');
    reportWith($entry, [
        'description_length' => ['status' => 'warn', 'message' => 'Short description.'],
        'title_length' => ['status' => 'fail', 'message' => 'Long title.'],
        'broken_links' => ['status' => 'fail', 'message' => 'Two broken links.'],
        'canonical' => ['status' => 'pass', 'message' => ''],
    ]);
    $entry->set('updated_at', Carbon\Carbon::parse('2026-09-30')->timestamp)->saveQuietly();
    $this->actingAs(cpUser(super: true));

    $seo = toolbarFor('/about')->assertOk()->json('seo');

    expect($seo['score'])->toBe(72)
        ->and($seo['messages'])->toBe(['From the report of October 1, 2026.'])
        ->and(array_column($seo['issues'], 'message'))->toBe(['Two broken links.', 'Long title.', 'Short description.'])
        ->and($seo['issues'][0]['url'])->toContain('/cp/marketing-toolkit/reports/')
        ->and($seo['issues'][1]['url'])->toContain('/cp/collections/pages/entries/')
        ->and($seo['run_url'])->toBe(cp_route('mt.reports.index'));
});

test('a page saved after the report says its score may have changed', function () {
    $entry = Entry::findByUri('/about');
    reportWith($entry, finished: '2026-10-01 12:00:00');
    $entry->set('updated_at', Carbon\Carbon::parse('2026-10-03 09:00:00')->timestamp)->saveQuietly();
    $this->actingAs(cpUser(super: true));

    toolbarFor('/about')->assertJsonPath('seo.messages.0', 'This page was saved on October 3, 2026, after the report of October 1, 2026, so its score may have changed.');
});

test('a page saved later on the day of the report says when each happened', function () {
    $entry = Entry::findByUri('/about');
    reportWith($entry, finished: '2026-10-01 09:15:00');
    $entry->set('updated_at', Carbon\Carbon::parse('2026-10-01 16:40:00')->timestamp)->saveQuietly();
    $this->actingAs(cpUser(super: true));

    toolbarFor('/about')->assertJsonPath('seo.messages.0', 'This page was saved on October 1, 2026 4:40 PM, after the report of October 1, 2026 9:15 AM, so its score may have changed.');
});

test('a page not in the report, a page every check passes, and a noindex page', function () {
    $this->actingAs(cpUser(super: true));
    $report = reportWith(entryIn('pages', 'contact'), score: 100);

    toolbarFor('/about')->assertJsonPath('seo.score', null)
        ->assertJsonPath('seo.messages.0', 'This page has no score yet, because it wasn’t in the latest report. The next report will check it.');

    toolbarFor('/contact')->assertJsonPath('seo.score', 100)->assertJsonPath('seo.messages.1', 'Every check passes on this page.');

    $report->pages()->update(['facts' => json_encode(['robots' => 'noindex, follow']), 'score' => null]);
    toolbarFor('/contact')->assertJsonPath('seo.messages.1', 'This page is hidden from search engines, so it isn’t scored.');
});

test('an address without content shows no score and no preview; a missing one offers a redirect', function () {
    $this->actingAs(cpUser(super: true));

    toolbarFor('/search?q=x', ['status' => 200])->assertOk()
        ->assertJsonPath('page.type', null)
        ->assertJsonPath('page.missing', false)
        ->assertJsonPath('seo', null)
        ->assertJsonPath('preview', null);

    MissingPath::query()->create(['path' => '/old-page', 'hits' => 7, 'first_seen_at' => '2026-09-01 10:00:00', 'last_seen_at' => now()]);

    toolbarFor('/old-page')->assertOk()
        ->assertJsonPath('page.missing', true)
        ->assertJsonPath('redirects.messages', [
            'Statamic answers this address with a 404.',
            'The 404 log has counted 7 visits since September 1, 2026.',
        ])
        ->assertJsonPath('redirects.create_url', cp_route('mt.redirects.create', ['source' => '/old-page']));

    // As the browser saw it, without a row in the log.
    toolbarFor('/never-seen', ['status' => 404])->assertJsonPath('page.missing', true)
        ->assertJsonPath('redirects.messages', ['Statamic answers this address with a 404.']);
});

test('a draft\'s address is a missing page, with an edit link to the draft', function () {
    entryIn('pages', 'coming-soon', ['published' => false])->published(false)->save();
    $this->actingAs(cpUser(super: true));

    toolbarFor('/coming-soon', ['status' => 404])->assertOk()
        ->assertJsonPath('page.status', 'draft')
        ->assertJsonPath('page.missing', true)
        ->assertJsonPath('page.edit_url', fn ($url) => str_contains($url, '/cp/collections/pages/entries/'))
        ->assertJsonPath('redirects.messages.1', 'A draft has this address, so visitors get a 404 until it is published.');
});

test('redirects to this page, and one from its address that never applies', function () {
    redirectRule(['source' => '/about-us', 'target' => '/about', 'hits' => 4]);
    redirectRule(['source' => '/company', 'target' => 'https://example.test/about', 'hits' => 2]);
    redirectRule(['source' => '/about', 'target' => '/elsewhere']);
    $this->actingAs(cpUser(['manage marketing toolkit redirects', 'view pages entries']));

    toolbarFor('/about')->assertOk()
        ->assertJsonPath('redirects.messages', [
            '2 redirects send visitors here, and they have been used 6 times.',
            'A redirect from this address never applies, because a page exists here.',
        ])
        ->assertJsonPath('redirects.to_here.0.source', '/about-us')
        ->assertJsonPath('redirects.ignored.source', '/about');
})->group('redirects');

test('tracking: which tags load, why none do, and Consent Mode', function () {
    seoGlobal(['gtm_id' => 'GTM-ABC1234', 'meta_pixel_id' => '123456789012', 'consent_mode' => true, 'consent_regions' => ['EEA']]);
    $this->actingAs(cpUser(super: true));

    toolbarFor('/about')->assertJsonPath('tracking.messages', [
        'These tags load on this page: Google Tag Manager and Meta Pixel.',
        'Consent Mode is on, and visitors in the EEA, the UK and Switzerland are denied until they agree.',
        'Form submissions are sent to these tools as leads.',
        'Google Tag Manager and Meta Pixel are both set, so those tools count each visit twice. Move them into Google Tag Manager.',
    ])->assertJsonPath('tracking.consent', true);

    $this->app['env'] = 'local';
    toolbarFor('/about')->assertJsonPath('tracking.messages.0', 'No tags load here, because this isn’t the production environment.');
});

test('Search Console: one sentence of this page\'s numbers', function () {
    config(['marketing-toolkit.search_console.credentials' => '{}']);
    app()->instance(SearchConsoleClient::class, Mockery::mock(SearchConsoleClient::class, ['configured' => true]));
    SearchStat::query()->create(['url' => 'https://example.test/about', 'clicks' => 1234, 'impressions' => 56789, 'ctr' => 0.02, 'position' => 8.25, 'from' => '2026-09-01', 'to' => '2026-09-28', 'fetched_at' => now()]);
    $this->actingAs(cpUser(super: true));

    toolbarFor('/about')->assertJsonPath('seo.search', 'In the last 28 days, this page had 1,234 clicks from 56,789 impressions, at an average position of 8.3.');
    toolbarFor(entryIn('pages', 'new')->absoluteUrl())->assertJsonPath('seo.search', 'Search Console has no numbers for this page yet.');
});

test('the preview: the search result and share card, and the indexing facts', function () {
    $this->actingAs(cpUser(super: true));
    entryIn('pages', 'hidden', ['seo' => ['noindex' => true]]);
    entryIn('pages', 'copy', ['seo' => ['canonical' => 'https://example.test/about']]);

    toolbarFor('/about')->assertJsonPath('preview.url', 'https://example.test/about')
        ->assertJsonPath('preview.facts', ['This page is in the sitemap.']);
    toolbarFor('/hidden')->assertJsonPath('preview.facts', ['Search engines are asked not to list this page, because its SEO tab says so.', 'This page isn’t in the sitemap.']);
    toolbarFor('/copy')->assertJsonPath('preview.facts.0', 'This page names https://example.test/about as its main address, so search engines will show that address instead.');
});

test('a translation without SEO of its own gives its origin\'s noindex as the reason, as its head does', function () {
    multilang();
    $origin = entryIn('pages', 'hidden', ['seo' => ['noindex' => true]]);
    translationOf($origin, 'fr', 'cache');
    $this->actingAs(cpUser(super: true));

    toolbarFor('https://example.test/fr/cache')->assertJsonPath('preview.facts.0', 'Search engines are asked not to list this page, because its SEO tab says so.');
});

test('on a multi-site install: the page on its own site, that site\'s report, links that select the site, and its other sites', function () {
    multilang();
    $about = Entry::findByUri('/about');
    $french = translationOf($about, 'fr', 'a-propos');
    $report = reportWith($french, site: 'fr', score: 61);
    reportWith($about, site: 'default', score: 99);
    $this->actingAs(cpUser(super: true));

    $data = toolbarFor('https://example.test/fr/a-propos')->assertOk()->json();

    expect($data['site']['handle'])->toBe('fr')
        ->and($data['page']['title'])->toBe('A propos')
        ->and($data['seo']['score'])->toBe(61)
        ->and($data['seo']['report_url'])->toBe(cp_route('mt.toolbar.go', ['site' => 'fr', 'to' => '/cp/marketing-toolkit/reports/'.$report->id]))
        ->and(collect($data['sites'])->pluck('handle')->all())->toBe(['default', 'uk', 'de'])
        ->and($data['sites'][0]['url'])->toBe('https://example.test/about')
        ->and($data['sites'][1]['missing'])->toBe('This page doesn’t exist in Acme yet.');

    $this->get($data['seo']['report_url'])->assertRedirect('/cp/marketing-toolkit/reports/'.$report->id);
    expect(session('statamic.cp.selected-site'))->toBe('fr');
});

test('toolbar/go opens only control panel paths', function () {
    multilang();
    $this->actingAs(cpUser(super: true));

    foreach (['https://evil.test/cp', '//evil.test/cp/x', '/cp//evil.test', '/\\evil.test', '/about', '/cpanel'] as $to) {
        $this->get(cp_route('mt.toolbar.go', ['site' => 'fr', 'to' => $to]))->assertNotFound();
    }

    $this->get(cp_route('mt.toolbar.go', ['site' => 'nowhere', 'to' => '/cp/dashboard']))->assertForbidden();
    $this->get(cp_route('mt.toolbar.go', ['site' => 'fr', 'to' => '/cp/dashboard']))->assertRedirect('/cp/dashboard');
});

test('refreshing this page\'s cache needs the cache utility\'s permission, and clears that page alone', function () {
    config(['statamic.static_caching.strategy' => 'half']);
    $cacher = Mockery::mock(Cacher::class);
    $cacher->shouldReceive('invalidateUrls')->once()->with(['https://example.test/about']);
    app()->instance(Cacher::class, $cacher);

    $this->actingAs(cpUser());
    $this->postJson('https://example.test/!/marketing-toolkit/toolbar/cache', ['url' => 'https://example.test/about'])->assertForbidden();

    $this->actingAs(cpUser(['access cache utility']));
    $this->postJson('https://example.test/!/marketing-toolkit/toolbar/cache', ['url' => 'https://evil.test/about'])->assertStatus(422);
    $this->postJson('https://example.test/!/marketing-toolkit/toolbar/cache', ['url' => 'https://example.test/about?page=2#top'])->assertNoContent();
});

test('the guard loads the built script, which ships with the addon and is published with its other assets', function () {
    preg_match('#data-src="/vendor/statamic-marketing-toolkit/build/(toolbar\.js)\?v=#', renderAt('/', '<s:mt:toolbar />'), $match);

    expect($match[1] ?? null)->toBe('toolbar.js')
        ->and(__DIR__.'/../../resources/dist/build/toolbar.js')->toBeFile();
});

test('the toolbar\'s corner, shortcut and hiding are the browser\'s: no preferences, nothing to post', function () {
    $this->actingAs(cpUser(super: true));
    $this->get(cp_route('preferences.user.edit'))->assertOk();

    expect(Preference::tabs()->has('marketing-toolkit'))->toBeFalse()
        ->and(Route::has('statamic.mt.toolbar.hide'))->toBeFalse()
        ->and(toolbarFor('/about')->json('user'))->not->toHaveKeys(['position', 'shortcut', 'hidden']);
});
