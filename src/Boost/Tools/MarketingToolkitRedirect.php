<?php

namespace JothamLec\MarketingToolkit\Boost\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Site;

/**
 * A Laravel Boost tool: which Marketing Toolkit redirect, if any, answers a path, as a visitor
 * would get it. It reads only: redirects are managed under Marketing → Redirects (or by CSV
 * import), not in routes.
 */
#[IsReadOnly]
class MarketingToolkitRedirect extends Tool
{
    protected string $description = 'Look up which redirect of the Marketing Toolkit (Statamic addon) answers a path: its status (301, 302, 410), its target, and whether it was added automatically when a page moved. Redirects apply only to paths the site would answer with a 404. Manage redirects under Marketing → Redirects or by CSV import, not with routes in the app.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The path as requested, such as "/old-page" or "/blog/2020/post?ref=x".')->required(),
            'site' => $schema->string()->description('A site handle; the default site when left out.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $site = (string) ($request->get('site') ?: Site::default()->handle());
        [$path, $query] = array_pad(explode('?', (string) $request->get('path'), 2), 2, '');
        $match = app(Matcher::class)->match('/'.ltrim($path, '/'), $query, $site);

        if (! $match) {
            return Response::text("No redirect answers {$path} on the site [{$site}].");
        }

        $rule = Redirect::query()->find($match['id']);

        return Response::json([
            'status' => $match['status'],
            'target' => $match['target'],
            'rule' => $rule ? ['source' => $rule->source, 'automatic' => (bool) $rule->automatic, 'active' => (bool) $rule->active, 'hits' => $rule->hits] : null,
        ]);
    }
}
