<?php

namespace JothamLec\MarketingToolkit\Boost\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\SiteSeo;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Structures\Page;

/**
 * A Laravel Boost tool (registered with Boost's MCP server when Boost is installed): what the
 * Marketing Toolkit prints for a page, worked out by the same rules the page's
 * <s:mt:head /> uses. It reads only.
 */
#[IsReadOnly]
class MarketingToolkitPageSeo extends Tool
{
    protected string $description = 'Show what the Marketing Toolkit (Statamic addon) prints in a page\'s <head>: title, description, canonical, robots, share image (uploaded or generated card), hreflang alternates, JSON-LD node types, and whether the page is in the sitemap. Use it to check SEO changes (collection rules in config/marketing-toolkit.php, SEO fields, a SiteSeo subclass) instead of reading the rendered HTML.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The page\'s path, such as "/" or "/blog/my-post", or its full URL.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $url = (string) $request->get('path');
        $url = str_starts_with($url, 'http') ? $url : url('/'.ltrim($url, '/'));
        $site = Site::findByUrl($url) ?? Site::default();
        $uri = '/'.trim($site->relativePath($url), '/');

        $content = Entry::findByUri($uri, $site->handle());
        $content = $content instanceof Page ? $content->entry() : $content;
        $content ??= $uri === '/' ? null : Term::findByUri($uri, $site->handle());

        if (! $content) {
            return Response::error("No entry or taxonomy term is at {$uri} on the site [{$site->handle()}].");
        }

        $seo = app(SiteSeo::class);
        $meta = $seo->meta(Context::make($content, HttpRequest::create($url)));

        return Response::json([
            'content' => ['type' => $content instanceof \Statamic\Contracts\Entries\Entry ? 'entry' : 'term', 'id' => $content->id(), 'site' => $site->handle()],
            'title' => $meta->title,
            'og_title' => $meta->ogTitle,
            'description' => $meta->description,
            'canonical' => $meta->canonical,
            'robots' => $meta->robots,
            'url' => $meta->url,
            'image' => $meta->image,
            'alternates' => $meta->alternates,
            'json_ld_types' => collect($meta->graph)->pluck('@type')->flatten()->unique()->values()->all(),
            'in_sitemap' => $seo->inSitemap($content),
            'protected' => $seo->isProtected($content),
        ]);
    }
}
