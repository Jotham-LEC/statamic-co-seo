<?php

namespace JothamLec\MarketingToolkit\Boost\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JothamLec\MarketingToolkit\Reports\Report;
use JothamLec\MarketingToolkit\Reports\Result;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Site;

/**
 * A Laravel Boost tool: the latest finished Marketing Toolkit SEO report, as the control panel's
 * Marketing → Reports shows it: the score, and each failing or warning check with the pages it
 * flagged. It reads only; run a report with `php please mt:report`.
 */
#[IsReadOnly]
class MarketingToolkitReport extends Tool
{
    protected string $description = 'Show the latest finished SEO report of the Marketing Toolkit (Statamic addon): the site\'s score out of 100 and each check that failed or warned, with the pages it flagged and why. Use it to find and fix SEO problems (missing descriptions, titles too long, no share image, broken links, several h1s…). Run a fresh report first with `php please mt:report` when content or templates changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A site handle; the default site when left out.'),
            'limit' => $schema->integer()->description('How many pages to list per check (default 20).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $site = (string) ($request->get('site') ?: Site::default()->handle());
        $limit = max(1, (int) ($request->get('limit') ?: 20));
        $report = Report::latestDone($site);

        if (! $report) {
            return Response::text("No finished report for the site [{$site}] yet. Run `php please mt:report`, or Run report under Marketing → Reports.");
        }

        $checks = [];
        foreach ($report->pages()->where('checked', true)->get() as $page) {
            foreach ((array) $page->results as $rule => $result) {
                if ($result['status'] === Result::PASS) {
                    continue;
                }
                $checks[$rule]['status'][$result['status']] = true;
                $checks[$rule]['pages'][] = ['url' => $page->url, 'title' => $page->title, 'status' => $result['status'], 'message' => __($result['message'], $result['params'] ?? [])];
            }
        }

        return Response::json([
            'site' => $site,
            'score' => $report->score,
            'finished_at' => $report->finished_at?->toIso8601String(),
            'pages_scored' => $report->scoredPages(),
            'checks' => collect($checks)->map(fn ($check, $rule) => [
                'check' => $rule,
                'status' => isset($check['status'][Result::FAIL]) ? Result::FAIL : Result::WARN,
                'pages_flagged' => count($check['pages']),
                'pages' => array_slice($check['pages'], 0, $limit),
            ])->sortByDesc('pages_flagged')->values()->all(),
        ]);
    }
}
