<?php

use JothamLec\MarketingToolkit\Http\Middleware\AdvertiseLlmsTxt;

beforeEach(function () {
    seoGlobal([]);
    entryIn('pages', 'about', [
        'title' => 'About us',
        'body' => '<h2>Who we are</h2><p>We make <strong>things</strong>.</p>',
        'seo' => ['description' => 'Who we are, and what we make.'],
        'layout' => 'markdown-layout',
    ]);
});

describe('A page asked for as Markdown', function () {
    test('is its main content as Markdown, under front matter, without the nav, footer or controls', function () {
        $response = $this->get('https://example.test/about', ['Accept' => 'text/markdown'])->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertHeader('Vary', 'Accept')
            ->assertHeader('Link', '<https://example.test/about>; rel="canonical"');

        $markdown = $response->getContent();

        expect($response->headers->get('x-markdown-tokens'))->toBe((string) (int) ceil(strlen($markdown) / 4))
            ->and($markdown)->toStartWith("---\ntitle: 'About us'\ndescription: 'Who we are, and what we make.'\nurl: 'https://example.test/about'\n---\n\n# About us\n")
            ->toContain("## Who we are\n\nWe make **things**.")
            ->toContain('Read [the first essay](https://example.test/essays/first) or [another site](https://elsewhere.test/page).')
            ->toContain('![A chart](https://example.test/assets/chart.png)')
            ->toContain('Tab panel text')
            ->toContain('## Smarter decisions by design')
            ->toContain('Make smarter choices, [talk to us](https://example.test/contact).')
            ->toContain("```\nkeep    this\n```")
            ->toContain('### Which markets do you cover?')
            ->toContain('Sales & share, in 20 countries &lt;br&gt; and more.')
            ->toContain('[Share on Social](https://social.test/share)')
            ->not->toContain('icon.test')
            ->not->toContain('Next slide')
            ->toContain("| Year | Sales |\n|---|---|\n| 2025 | 12 |")
            ->not->toContain('Site menu')
            ->not->toContain('Site footer')
            ->not->toContain('Hidden text')
            ->not->toContain('Decorative text')
            ->not->toContain('Icon text')
            ->not->toContain('Subscribe')
            ->not->toContain('Script text')
            ->not->toContain('color: red')
            ->not->toContain('<');
    });

    test('carries the Content-Signal header that robots.txt prints, unless content signals are off', function () {
        seoGlobal(['allow_ai_training' => false]);

        $this->get('https://example.test/about', ['Accept' => 'text/markdown'])
            ->assertHeader('Content-Signal', 'search=yes, ai-input=yes, ai-train=no');

        seoGlobal(['content_signals' => false]);

        $this->get('https://example.test/about', ['Accept' => 'text/markdown'])
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertHeaderMissing('Content-Signal');
    });

    test('is HTML when the module is switched off', function () {
        config(['marketing-toolkit.markdown.enabled' => false]);

        $response = $this->get('https://example.test/about', ['Accept' => 'text/markdown'])->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('text/html')
            ->and($response->getContent())->toContain('<main');
    });

    test('is a 404 as HTML when the page is missing', function () {
        $response = $this->get('https://example.test/missing', ['Accept' => 'text/markdown'])->assertNotFound();

        expect($response->headers->get('Content-Type'))->not->toStartWith('text/markdown');
    });
});

describe('A browser', function () {
    test('gets HTML, marked as varying by Accept', function () {
        $response = $this->get('https://example.test/about', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])->assertOk()
            ->assertHeader('Vary', 'Accept')
            ->assertHeaderMissing('x-markdown-tokens');

        expect($response->headers->get('Content-Type'))->toStartWith('text/html');
    });

    test('that ranks HTML above Markdown gets HTML', function () {
        $response = $this->get('https://example.test/about', ['Accept' => 'text/html, text/markdown;q=0.5'])->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith('text/html');
    });

    test('never gets a Markdown copy from the static cache, nor an agent an HTML one', function () {
        config(['statamic.static_caching.strategy' => 'half']);

        $this->get('https://example.test/about', ['Accept' => 'text/markdown'])->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $html = $this->get('https://example.test/about', ['Accept' => 'text/html']);

        expect($html->headers->get('Content-Type'))->toStartWith('text/html')
            ->and($html->getContent())->toContain('<main');

        $this->get('https://example.test/about', ['Accept' => 'text/markdown'])->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
    });
});

describe('The home page', function () {
    test('links to llms.txt, and no other page does', function () {
        entryIn('home', 'home');

        $this->get('https://example.test/')->assertOk()->assertHeader('Link', AdvertiseLlmsTxt::LINK);
        $this->get('https://example.test/about')->assertOk()->assertHeaderMissing('Link');
    });

    test('doesn\'t link to llms.txt while the site serves none', function () {
        config(['marketing-toolkit.llms_txt.enabled' => false]);
        entryIn('home', 'home');

        $this->get('https://example.test/')->assertOk()->assertHeaderMissing('Link');
    });
});
