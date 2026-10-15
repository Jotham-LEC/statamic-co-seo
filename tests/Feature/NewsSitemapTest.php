<?php

use JothamLec\MarketingToolkit\Sitemap\NewsSitemap;
use JothamLec\MarketingToolkit\SiteSeo;

/**
 * @return list<string>
 */
function newsLocs(string $xml): array
{
    $sitemap = simplexml_load_string($xml);

    expect($sitemap)->not->toBeFalse();

    return array_map('strval', iterator_to_array($sitemap->xpath('//*[local-name()="loc"]'), false));
}

test('answers 404 until a collection is named', function () {
    entryIn('essays', 'fresh', date: now()->subHour()->format('Y-m-d-Hi'));

    $this->get('/news-sitemap.xml')->assertNotFound();
});

test('lists the last two days of published entries, newest first, and none dated later than now', function () {
    config(['marketing-toolkit.sitemap.news.collections' => ['essays']]);
    // The test collection's dates have no time, so each is midnight.
    entryIn('essays', 'older', date: now()->subDay()->format('Y-m-d'));
    entryIn('essays', 'newer', date: now()->format('Y-m-d'));
    entryIn('essays', 'stale', date: now()->subDays(3)->format('Y-m-d'));
    entryIn('essays', 'scheduled', date: now()->addDay()->format('Y-m-d'));
    entryIn('essays', 'draft', date: now()->subHour()->format('Y-m-d-Hi'))->published(false)->save();
    entryIn('essays', 'hidden', ['seo' => ['noindex' => true]], now()->subHour()->format('Y-m-d-Hi'));
    entryIn('pages', 'not-news');

    $response = $this->get('/news-sitemap.xml')->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

    expect(newsLocs($response->getContent()))->toBe([
        'https://example.test/essays/newer',
        'https://example.test/essays/older',
    ]);
});

test('names the publication, its language, the date and the title', function () {
    config(['marketing-toolkit.sitemap.news.collections' => ['essays']]);
    $entry = entryIn('essays', 'story', ['title' => 'A <b>story</b> & more'], now()->subHour()->format('Y-m-d-Hi'));

    $xml = $this->get('/news-sitemap.xml')->getContent();

    expect($xml)
        ->toContain('<news:name>Acme</news:name>')
        ->toContain('<news:language>en</news:language>')
        ->toContain('<news:publication_date>'.$entry->date()->toAtomString().'</news:publication_date>')
        ->toContain('<news:title>A &lt;b&gt;story&lt;/b&gt; &amp; more</news:title>');

    config(['marketing-toolkit.sitemap.news.publication' => 'Acme Daily']);

    expect($this->get('/news-sitemap.xml')->getContent())->toContain('<news:name>Acme Daily</news:name>');
});

test('answers 404 while the sitemap is off', function () {
    config(['marketing-toolkit.sitemap.news.collections' => ['essays'], 'marketing-toolkit.sitemap.enabled' => false]);

    $this->get('/news-sitemap.xml')->assertNotFound();
});

test('is listed in robots.txt', function () {
    expect(app(SiteSeo::class)->robotsTxt())->not->toContain('news-sitemap.xml');

    config(['marketing-toolkit.sitemap.news.collections' => ['essays']]);

    expect(app(SiteSeo::class)->robotsTxt())->toContain("Sitemap: https://example.test/sitemap.xml\nSitemap: https://example.test/news-sitemap.xml\n");
});

test('gives Google News the language codes it takes', function (string $locale, string $language) {
    expect(NewsSitemap::language($locale))->toBe($language);
})->with([
    ['en_US', 'en'],
    ['fr_FR.UTF-8', 'fr'],
    ['zh_CN', 'zh-cn'],
    ['zh-Hant-TW', 'zh-tw'],
    ['zh_TW', 'zh-tw'],
]);
