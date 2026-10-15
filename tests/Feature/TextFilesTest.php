<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;

test('llms.txt lists each collection\'s pages with their descriptions, without hidden ones', function () {
    seoGlobal(['default_description' => 'We make <b>things</b>.']);
    Collection::findByHandle('pages')->title('Pages')->save();
    entryIn('pages', 'about', ['title' => 'About [us]', 'seo' => ['description' => 'Who we are.']]);
    entryIn('pages', 'team', ['title' => 'Team']);
    entryIn('pages', 'hidden', ['seo' => ['noindex' => true]]);

    $text = $this->get('https://example.test/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertHeaderMissing('Set-Cookie')->getContent();

    expect($text)->toStartWith("# Acme\n\n> We make things.\n")
        ->toContain("## Pages\n")
        ->toContain('- [About (us)](https://example.test/about): Who we are.')
        ->toContain('- [Team](https://example.test/team)')
        ->not->toContain('hidden');
});

test('llms.txt lists pages changed in the same second by title, newest first otherwise', function () {
    Collection::findByHandle('pages')->title('Pages')->save();
    $sameSecond = Carbon::parse('2026-10-01 09:00:00')->timestamp;
    entryIn('pages', 'cherry', ['updated_at' => $sameSecond]);
    entryIn('pages', 'apple', ['updated_at' => $sameSecond]);
    entryIn('pages', 'banana', ['updated_at' => $sameSecond]);
    entryIn('pages', 'zucchini', ['updated_at' => Carbon::parse('2026-10-02 09:00:00')->timestamp]);

    $text = $this->get('https://example.test/llms.txt')->assertOk()->getContent();

    expect(array_values(array_filter(explode("\n", $text), fn (string $line) => str_starts_with($line, '- '))))
        ->sequence(
            fn ($line) => $line->toStartWith('- [Zucchini]'),
            fn ($line) => $line->toStartWith('- [Apple]'),
            fn ($line) => $line->toStartWith('- [Banana]'),
            fn ($line) => $line->toStartWith('- [Cherry]'),
        );
});

test('llms.txt at the domain\'s root lists the sites in its folders too', function () {
    multilang();
    seoGlobal([]);
    entryIn('pages', 'about');
    entryOn('fr', 'pages', 'a-propos');

    $text = $this->get('https://example.test/llms.txt')->assertOk()->getContent();

    expect($text)->toContain('https://example.test/about')->toContain('https://example.test/fr/a-propos')->toContain('## Pages (');
});

test('llms.txt is forgotten when Brand is saved', function () {
    seoGlobal(['default_description' => 'We make gardens.']);
    $this->get('https://example.test/llms.txt')->assertSee('We make gardens.');

    GlobalSet::findByHandle('seo')->in('default')->set('default_description', 'We make parks.')->save();

    $this->get('https://example.test/llms.txt')->assertSee('We make parks.')->assertDontSee('We make gardens.');
});

test('llms.txt is cached, and forgotten when content is saved', function () {
    seoGlobal([]);
    entryIn('pages', 'about');
    $this->get('https://example.test/llms.txt')->assertSee('/about');

    entryIn('pages', 'team');

    $this->get('https://example.test/llms.txt')->assertSee('/team');
});

test('llms.txt follows a change to the config, without waiting for a save', function () {
    seoGlobal([]);
    entryIn('pages', 'about');
    entryIn('essays', 'first');
    $this->get('https://example.test/llms.txt')->assertSee('/first');

    config(['marketing-toolkit.sitemap.exclude_collections' => ['essays']]);

    $this->get('https://example.test/llms.txt')->assertSee('/about')->assertDontSee('/first');
});

test('ads.txt serves the lines from SEO & brand, and nothing until there are some', function () {
    seoGlobal([]);
    $this->get('https://example.test/ads.txt')->assertNotFound();

    seoGlobal(['ads_txt' => "google.com, pub-123, DIRECT, f08c47fec0942fa0\n"]);
    $this->get('https://example.test/ads.txt')->assertOk()->assertContent("google.com, pub-123, DIRECT, f08c47fec0942fa0\n");
});

/**
 * A site's 404 page often reads Statamic's cascade ($site, globals). Laravel's
 * abort(404) renders it without one, so it failed and answered a bare error
 * page, logging an error for every crawler that asked for /ads.txt.
 */
test('a file that has nothing to serve answers with the site\'s own 404 page', function () {
    $views = sys_get_temp_dir().'/mt-404-'.uniqid();
    File::ensureDirectoryExists($views.'/errors');
    File::put($views.'/errors/404.blade.php', 'Nothing here on {{ $site->handle() }}.');
    View::getFinder()->prependLocation($views);

    $this->get('https://example.test/ads.txt')->assertNotFound()->assertSee('Nothing here on default.');
    $this->get('https://example.test/sitemap_999.xml')->assertNotFound()->assertSee('Nothing here on default.');

    File::deleteDirectory($views);
});
