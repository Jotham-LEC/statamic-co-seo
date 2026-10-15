<?php

namespace JothamLec\MarketingToolkit\Sitemap;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;

/**
 * This class collects the articles a Google News sitemap lists: the published
 * entries of the collections `marketing-toolkit.sitemap.news.collections`
 * names, dated in the last two days, since Google News takes no older ones.
 * An entry dated later than now is left out, even where the collection shows
 * future entries, because Google would date it in the future.
 */
final class NewsSitemap
{
    public const int HOURS = 48;

    /** Whether any collection is listed, without which /news-sitemap.xml answers 404. */
    public static function enabled(): bool
    {
        return (array) config('marketing-toolkit.sitemap.news.collections') !== [];
    }

    /**
     * @return Collection<int, array{loc: string, name: string, language: string, date: string, title: string}>
     */
    public function urls(SiteSeo $seo): Collection
    {
        $collections = (array) config('marketing-toolkit.sitemap.news.collections');

        if ($collections === []) {
            return collect();
        }

        // Entry dates are stored in UTC and query bindings are not converted, so the window is in UTC too.
        return Entries::query()
            ->whereIn('collection', $collections)
            ->whereIn('site', $seo->sitemapSites())
            ->whereStatus('published')
            ->where('date', '<=', now('UTC'))
            ->where('date', '>=', now('UTC')->subHours(self::HOURS))
            ->orderBy('date', 'desc')
            ->get()
            ->filter(fn (Entry $entry) => $seo->inSitemap($entry))
            ->map(fn (Entry $entry) => Sites::as($entry->locale(), fn () => [
                'loc' => (string) $entry->absoluteUrl(),
                'name' => (string) (config('marketing-toolkit.sitemap.news.publication') ?? $seo->settings()->siteName()),
                'language' => self::language((string) $entry->site()->locale()),
                'date' => $entry->date()->toAtomString(),
                'title' => (string) $entry->value('title'),
            ]))
            ->values();
    }

    /**
     * Google News takes a language as its two-letter code, except Chinese,
     * which it takes as zh-cn or zh-tw.
     */
    public static function language(string $locale): string
    {
        $locale = Str::lower(str_replace('_', '-', Str::before($locale, '.')));

        return match (true) {
            str_starts_with($locale, 'zh-cn'), str_starts_with($locale, 'zh-hans') => 'zh-cn',
            str_starts_with($locale, 'zh-tw'), str_starts_with($locale, 'zh-hant') => 'zh-tw',
            default => Str::substr($locale, 0, 2),
        };
    }
}
