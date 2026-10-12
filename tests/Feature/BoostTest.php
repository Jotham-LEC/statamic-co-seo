<?php

use JothamLec\MarketingToolkit\Boost\Boost;
use JothamLec\MarketingToolkit\Boost\Tools\MarketingToolkitPageSeo;
use JothamLec\MarketingToolkit\Boost\Tools\MarketingToolkitRedirect;
use JothamLec\MarketingToolkit\Boost\Tools\MarketingToolkitReport;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Reports\Report;
use Laravel\Mcp\Request;

/** Runs a tool as Boost's MCP server would, and returns its text. */
function boostTool(string $tool, array $arguments): string
{
    $response = app($tool)->handle(new Request($arguments));

    return (string) $response->content();
}

test('the tools join Boost\'s MCP server, named for the addon, unless switched off', function () {
    config(['boost.mcp.tools.include' => ['App\\Mcp\\Mine']]);
    Boost::register();

    expect(config('boost.mcp.tools.include'))->toBe(['App\\Mcp\\Mine', ...Boost::TOOLS])
        ->and(collect(Boost::TOOLS)->map(fn ($tool) => app($tool)->name())->all())
        ->toBe(['marketing-toolkit-page-seo', 'marketing-toolkit-report', 'marketing-toolkit-redirect']);

    config(['boost.mcp.tools.include' => [], 'marketing-toolkit.boost.tools' => false]);
    Boost::register();
    expect(config('boost.mcp.tools.include'))->toBe([]);
});

test('every tool only reads', function () {
    foreach (Boost::TOOLS as $tool) {
        expect(app($tool)->annotations())->toHaveKey('readOnlyHint', true);
    }
});

test('the page tool shows what a page\'s head prints', function () {
    entryIn('pages', 'about', ['description' => 'Who we are.']);

    $seo = json_decode(boostTool(MarketingToolkitPageSeo::class, ['path' => '/about']), true);

    expect($seo)->toMatchArray(['title' => 'About', 'description' => 'Who we are.', 'canonical' => 'https://example.test/about', 'in_sitemap' => true])
        ->and($seo['json_ld_types'])->toContain('WebPage');
    expect(boostTool(MarketingToolkitPageSeo::class, ['path' => '/nothing']))->toContain('No entry or taxonomy term is at /nothing');
});

test('the redirect tool finds the rule that answers a path', function () {
    Redirect::query()->create(['source' => '/old/*', 'target' => '/new/$1', 'status' => 301]);

    $match = json_decode(boostTool(MarketingToolkitRedirect::class, ['path' => '/old/page']), true);

    expect($match)->toMatchArray(['status' => 301, 'target' => '/new/page'])
        ->and($match['rule']['source'])->toBe('/old/*')
        ->and(boostTool(MarketingToolkitRedirect::class, ['path' => '/elsewhere']))->toContain('No redirect answers /elsewhere');
});

test('the report tool lists the latest report\'s failing checks', function () {
    expect(boostTool(MarketingToolkitReport::class, []))->toContain('No finished report');

    $report = Report::query()->create(['site' => 'default', 'status' => Report::DONE, 'score' => 80, 'pages_total' => 1, 'pages_done' => 1, 'settings' => [], 'finished_at' => now()]);
    $report->pages()->create(['url' => 'https://example.test/about', 'content_type' => 'entry', 'content_id' => 'x', 'title' => 'About', 'in_sitemap' => true, 'checked' => true, 'score' => 80,
        'results' => ['title_length' => ['status' => 'pass', 'message' => 'ok'], 'og_image' => ['status' => 'fail', 'message' => 'No share image.']]]);

    $summary = json_decode(boostTool(MarketingToolkitReport::class, []), true);

    expect($summary['score'])->toBe(80)
        ->and($summary['checks'])->toHaveCount(1)
        ->and($summary['checks'][0])->toMatchArray(['check' => 'og_image', 'status' => 'fail', 'pages_flagged' => 1])
        ->and($summary['checks'][0]['pages'][0]['message'])->toBe('No share image.');
});

test('the guidelines and skills are where Boost looks for a package\'s own', function () {
    $root = dirname(__DIR__, 2).'/resources/boost';

    expect("{$root}/guidelines/core.blade.php")->toBeFile();
    foreach (['marketing-toolkit-seo-rules', 'marketing-toolkit-extend'] as $skill) {
        expect(file_get_contents("{$root}/skills/{$skill}/SKILL.md"))->toStartWith("---\nname: {$skill}\n");
    }
});
