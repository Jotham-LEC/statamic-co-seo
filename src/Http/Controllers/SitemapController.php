<?php

namespace JothamLec\MarketingToolkit\Http\Controllers;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use JothamLec\MarketingToolkit\Sitemap\NewsSitemap;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Serves /sitemap.xml, which lists every published page that search engines should index. Up to
 * `marketing-toolkit.sitemap.per_page` URLs it is one <urlset>, and past that it becomes an
 * index of /sitemap_{n}.xml. It is cached until an entry, term, tree, collection
 * or taxonomy is saved (JothamLec\MarketingToolkit\Listeners\FlushSitemap),
 * and at most until the next dated entry is published or expires.
 */
final class SitemapController
{
    private const string CACHE_KEY = 'mt:sitemap';

    public function index(Request $request, SiteSeo $seo): Response
    {
        $urls = $this->urls($request, $seo);
        $perPage = $this->perPage();

        if ($urls->count() <= $perPage) {
            return $this->xml(view('marketing-toolkit::sitemap', ['urls' => $urls])->render());
        }

        $pages = range(1, (int) ceil($urls->count() / $perPage));

        return $this->xml(view('marketing-toolkit::sitemap-index', [
            'pages' => array_map(fn (int $page) => $seo->absolute('/sitemap_'.$page.'.xml'), $pages),
        ])->render());
    }

    public function page(Request $request, SiteSeo $seo, string $page): Response
    {
        // The page is taken as text, because a number too big for an int would fail the type with a 500, not a 404.
        throw_if(strlen($page) > 9 || (int) $page < 1, NotFoundHttpException::class);

        $chunk = $this->urls($request, $seo)->forPage((int) $page, $this->perPage());

        throw_if($chunk->isEmpty(), NotFoundHttpException::class);

        return $this->xml(view('marketing-toolkit::sitemap', ['urls' => $chunk])->render());
    }

    /**
     * Answers /news-sitemap.xml, the Google News sitemap. It isn't cached: it
     * lists two days of articles, and the window moves on its own.
     */
    public function news(SiteSeo $seo): Response
    {
        throw_unless(Features::on('sitemap') && NewsSitemap::enabled(), NotFoundHttpException::class);

        return $this->xml(view('marketing-toolkit::news-sitemap', ['urls' => app(NewsSitemap::class)->urls($seo)])->render());
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: ?string, alternates?: array<string, string>}>
     */
    private function urls(Request $request, SiteSeo $seo): Collection
    {
        throw_unless(Features::on('sitemap'), NotFoundHttpException::class);

        $build = fn () => $seo->sitemapUrls();

        // It is cached only on a host that the install names, because the addresses may come from
        // the Host header (Sites::trustsHost).
        return Sites::trustsHost($request) ? self::remember(self::cacheKey(Site::current()->handle()), $build) : $build();
    }

    /**
     * Returns the cached value under $key, or builds and caches it until
     * cachedUntil(). The date is only looked up when the value is built, so a
     * cached sitemap costs one cache read.
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public static function remember(string $key, Closure $build): mixed
    {
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $build();
        Cache::put($key, $value, self::cachedUntil());

        return $value;
    }

    /**
     * Returns when the sitemap and llms.txt stop being right by themselves. This is
     * the next date of an entry in a collection that hides future or past dates,
     * when that entry is published or expires. Statamic's scheduler flushes them
     * then too (EntryScheduleReached), but only where the scheduler runs. It
     * returns null when there is no such date, so they are cached until the next save.
     */
    public static function cachedUntil(): ?CarbonInterface
    {
        $collections = Collections::all()
            ->filter(fn ($collection) => $collection->dated() && in_array('private', [$collection->futureDateBehavior(), $collection->pastDateBehavior()], true))
            ->map->handle()
            ->values()
            ->all();

        if ($collections === []) {
            return null;
        }

        return Entry::query()->whereIn('collection', $collections)->where('date', '>', now())->orderBy('date')->first()?->date();
    }

    /**
     * Builds a key per site and per `marketing-toolkit.sitemap` and `.hreflang`
     * settings (the rows carry each page's other languages), so a deploy or a
     * switch under Features that changes them doesn't serve the old list until
     * the next save.
     */
    public static function cacheKey(string $site): string
    {
        return self::CACHE_KEY.':'.$site.':'.md5(serialize([config('marketing-toolkit.sitemap'), config('marketing-toolkit.hreflang')]));
    }

    private function perPage(): int
    {
        return max(1, (int) config('marketing-toolkit.sitemap.per_page'));
    }

    private function xml(string $body): Response
    {
        return response($body)->header('Content-Type', 'text/xml; charset=UTF-8');
    }
}
