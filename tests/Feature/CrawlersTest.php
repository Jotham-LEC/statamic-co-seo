<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Http;
use JothamLec\MarketingToolkit\IndexNow\IndexNow;
use JothamLec\MarketingToolkit\TextFiles\TextFiles;
use Statamic\Facades\Entry;

describe('snippets', function () {
    beforeEach(fn () => seoGlobal([]));

    test('a page can keep its text out of results and AI answers, or cap what is quoted', function () {
        expect(metaFor(entryIn('pages', 'a', ['seo' => ['nosnippet' => true]]))->robots)
            ->toBe('nosnippet, max-image-preview:large, max-video-preview:-1')
            ->and(metaFor(entryIn('pages', 'b', ['seo' => ['max_snippet' => 50]]))->robots)
            ->toBe('max-snippet:50, max-image-preview:large, max-video-preview:-1')
            ->and(metaFor(entryIn('pages', 'c', ['seo' => ['nofollow' => true, 'max_snippet' => 0]]))->robots)
            ->toBe('nofollow, max-snippet:0, max-image-preview:large, max-video-preview:-1')
            ->and(metaFor(entryIn('pages', 'd'))->robots)
            ->toBe('max-snippet:-1, max-image-preview:large, max-video-preview:-1');
    });
});

describe('AI crawlers in robots.txt', function () {
    test('are let in until the brand global turns them away', function () {
        seoGlobal([]);

        expect($this->get('/robots.txt')->getContent())->not->toContain('GPTBot');
    });

    test('training and search crawlers are turned away separately', function () {
        seoGlobal(['allow_ai_training' => false, 'allow_ai_search' => true]);

        $robots = $this->get('/robots.txt')->getContent();

        expect($robots)->toContain("User-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: Google-Extended\nUser-agent: Applebot-Extended\nUser-agent: CCBot\nDisallow: /")
            ->not->toContain('OAI-SearchBot');

        seoGlobal(['allow_ai_training' => true, 'allow_ai_search' => false]);

        expect($this->get('/robots.txt')->getContent())->toContain("User-agent: OAI-SearchBot\nUser-agent: Claude-SearchBot\nUser-agent: PerplexityBot\nDisallow: /")
            ->not->toContain('GPTBot');
    });
});

describe('Content signals in robots.txt', function () {
    test('say what the AI switches allow, in the group for every crawler', function () {
        seoGlobal([]);

        expect($this->get('/robots.txt')->getContent())->toStartWith("User-agent: *\nDisallow: /cp/\nContent-Signal: search=yes, ai-input=yes, ai-train=yes\n");

        seoGlobal(['allow_ai_training' => false, 'allow_ai_search' => true]);

        expect($this->get('/robots.txt')->getContent())->toContain('Content-Signal: search=yes, ai-input=yes, ai-train=no');

        seoGlobal(['allow_ai_training' => true, 'allow_ai_search' => false]);

        expect($this->get('/robots.txt')->getContent())->toContain('Content-Signal: search=yes, ai-input=no, ai-train=yes');
    });

    test('are left out when the site turns them off, for a CDN that adds its own', function () {
        seoGlobal(['content_signals' => false, 'allow_ai_training' => false]);

        expect($this->get('/robots.txt')->getContent())->not->toContain('Content-Signal')
            ->toContain('User-agent: GPTBot');
    });

    test('are left out of a copy of the site kept out of search engines', function () {
        expect(app(TextFiles::class)->robots(true, 'https://example.test/sitemap.xml'))
            ->toBe("User-agent: *\nDisallow: /\n");
    });
});

describe('IndexNow', function () {
    beforeEach(function () {
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
        seoGlobal([]);
    });

    test('is told, once the request is answered, about published content that changed or went away', function () {
        $about = entryIn('pages', 'about');
        entryIn('pages', 'contact');
        $draft = tap(Entry::make()->collection('pages')->slug('draft')->data(['title' => 'Draft'])->published(false))->save();
        $about->delete();
        // A deleted draft's address was never public: engines aren't told it exists.
        $draft->delete();

        app()->terminate();

        $key = app(IndexNow::class)->key();
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.indexnow.org/indexnow'
            && $request['host'] === 'example.test'
            && $request['key'] === $key
            && $request['keyLocation'] === "https://example.test/{$key}.txt"
            && $request['urlList'] === ['https://example.test/about', 'https://example.test/contact']);
    });

    test('is told about a page that was unpublished, and about the old address of one that moved', function () {
        $about = entryIn('pages', 'about');
        $team = entryIn('pages', 'team');
        app()->terminate();
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

        Entry::find($about->id())->published(false)->save();
        Entry::find($team->id())->slug('people')->save();
        app()->terminate();

        Http::assertSent(fn (HttpRequest $request) => collect($request['urlList'])->sort()->values()->all()
            === ['https://example.test/about', 'https://example.test/people', 'https://example.test/team']);
    });

    test('a queue worker sends what each job changed once the job is done, not when the worker stops', function () {
        entryIn('pages', 'about');

        event(new JobProcessed('redis', Mockery::mock(Job::class)));

        Http::assertSent(fn (HttpRequest $request) => $request['urlList'] === ['https://example.test/about']);
        expect(queuedForIndexNow())->toBe([]);
    });

    test('serves its key, and stays quiet outside production or when turned off', function () {
        $key = app(IndexNow::class)->key();

        expect($this->get("/{$key}.txt")->assertOk()->getContent())->toBe($key)
            ->and(strlen($key))->toBe(32);

        $this->app['env'] = 'staging';
        entryIn('pages', 'about');
        app()->terminate();

        $this->app['env'] = 'production';
        config(['marketing-toolkit.indexnow.enabled' => false]);
        entryIn('pages', 'contact');
        app()->terminate();

        Http::assertNothingSent();
    });

    test('a failure is logged, not shown', function () {
        Http::fake(['api.indexnow.org/*' => Http::response('', 500)]);
        entryIn('pages', 'about');

        app()->terminate();

        Http::assertSentCount(1);
    });
});
