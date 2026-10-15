<?php

namespace JothamLec\MarketingToolkit;

use Closure;
use Illuminate\Support\Collection;
use JothamLec\MarketingToolkit\Concerns\BuildsMeta;
use JothamLec\MarketingToolkit\Concerns\BuildsSchema;
use JothamLec\MarketingToolkit\Concerns\InteractsWithContent;
use JothamLec\MarketingToolkit\Concerns\ResolvesAlternates;
use JothamLec\MarketingToolkit\Sitemap\NewsSitemap;
use JothamLec\MarketingToolkit\Sitemap\Sitemap;
use JothamLec\MarketingToolkit\Support\Fields;
use JothamLec\MarketingToolkit\TextFiles\TextFiles;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Site;
use WeakMap;

/**
 * This class holds the SEO rules. Each public method works out one value for
 * one page. A project extends this class, binds its subclass in its place in a
 * service provider (`$this->app->bind(SiteSeo::class, Seo::class)`), and
 * overrides only the rules it needs to change. Every method receives the
 * Context, so a rule can look at the entry, the term, the request, and the
 * template's overrides. The traits only split the class into parts, so you
 * override their methods on the subclass, as you would any other. The sitemap
 * and the text files are built by Sitemap\Sitemap and TextFiles\TextFiles,
 * which call the hooks below.
 *
 * The methods marked `@api` (those that docs/developers.md lists) keep their
 * names and signatures until the next major version. The others are internal
 * and may change in any release.
 */
class SiteSeo
{
    use BuildsMeta;
    use BuildsSchema;
    use InteractsWithContent;
    use ResolvesAlternates;

    /**
     * These are the crawlers and robots.txt tokens used for AI training by
     * OpenAI, Anthropic, Google (for Gemini, which leaves Search unaffected),
     * Apple, and Common Crawl, whose open dataset AI developers train on.
     */
    public const array AI_TRAINING_AGENTS = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot'];

    /** These crawlers index pages for AI search answers (ChatGPT, Claude, and Perplexity). */
    public const array AI_SEARCH_AGENTS = ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot'];

    protected Settings $settings;

    /** @var WeakMap<Context, array<string, mixed>>|null the values several rules need, kept per page */
    private ?WeakMap $worked = null;

    public function __construct()
    {
        // The settings come from the container, so a site or a test can bind its own.
        $this->settings = app(Settings::class);
    }

    /**
     * Returns every URL the sitemap lists, sorted by address. Each URL carries
     * its other languages (hreflang code => address) when it has any.
     *
     * @return Collection<int, array{loc: string, lastmod: ?string, alternates?: array<string, string>}>
     */
    public function sitemapUrls(): Collection
    {
        return app(Sitemap::class)->urls($this);
    }

    /**
     * Returns the sites one sitemap lists, which are the current site and the others on its domain.
     *
     * @return list<string>
     *
     * @api
     */
    public function sitemapSites(): array
    {
        return app(Sitemap::class)->sites();
    }

    /**
     * Returns URLs that are not entries or terms, such as controller pages. It is empty by default.
     *
     * @return list<array{loc: string, lastmod: ?string}>
     *
     * @api
     */
    public function additionalSitemapUrls(): array
    {
        return [];
    }

    /**
     * Determines whether a page is listed in the sitemap and llms.txt. It is
     * listed when it has an address, is its own canonical address, and isn't a
     * redirect, noindexed, left out of the sitemap, or protected.
     *
     * @api
     */
    public function inSitemap(Entry|Term $content): bool
    {
        // This reads a translation's own group, or its origin's group when it has none.
        $seo = Fields::value($content, 'seo');
        $seo = is_array($seo) ? $seo : [];
        $canonical = $seo['canonical'] ?? null;

        // Only canonical addresses are listed, so a page naming another (here or elsewhere) as canonical is left out.
        return $content->url() !== null
            && ! ($content instanceof Entry && $content->isRedirect())
            && ! ($seo['noindex'] ?? false)
            && ($seo['sitemap'] ?? true) !== false
            && ! $this->isProtected($content)
            && (blank($canonical) || rtrim((string) $canonical, '/') === rtrim((string) $content->absoluteUrl(), '/'));
    }

    /**
     * Determines whether a term has published entries, which makes its page
     * worth listing and checking. Statamic counts only the entries of the
     * collections the taxonomy is attached to, so override this method for a
     * taxonomy that isn't attached (where the entries name their terms in a
     * `terms` field), or to count only some entries. Only the current site's
     * entries count, although Statamic would count every site's, because the
     * sitemap and each report ask on the site they belong to.
     *
     * @api
     */
    public function termHasEntries(Term $term): bool
    {
        return $term->queryEntries()->where('site', Site::current()->handle())->whereStatus('published')->count() > 0;
    }

    /** @api */
    public function robotsTxt(): string
    {
        return app(TextFiles::class)->robots(
            $this->hiddenOutsideProduction(),
            $this->absolute('/sitemap.xml'),
            NewsSitemap::enabled() ? $this->absolute('/news-sitemap.xml') : null,
        );
    }

    /**
     * Builds /llms.txt (see llmstxt.org). It lists the site's name and
     * description, then its pages by collection as Markdown links with their
     * descriptions.
     *
     * @api
     */
    public function llmsTxt(): string
    {
        // This uses a page's own SEO description, or else one from its content, but never the site's default,
        // which every page would repeat.
        return app(TextFiles::class)->llms($this, $this->llmsPerCollection(), fn (Context $context) => $context->seo()['description'] ?? $this->contentDescription($context));
    }

    /** @api */
    protected function llmsPerCollection(): int
    {
        return 100;
    }

    /**
     * Builds /ads.txt from the lines in Marketing settings → Crawlers, for a site that sells ad space.
     */
    public function adsTxt(): ?string
    {
        return app(TextFiles::class)->ads();
    }

    /**
     * Returns $work's result for this page, working it out the first time it is
     * asked for. The result is keyed by the Context itself rather than by
     * Laravel's once(), which keys objects by an ID that PHP reuses, so a
     * page's values are released when its Context is.
     */
    private function once(Context $context, string $key, Closure $work): mixed
    {
        $this->worked ??= new WeakMap;
        $values = $this->worked[$context] ?? [];

        if (! array_key_exists($key, $values)) {
            $values[$key] = $work();
            $this->worked[$context] = $values;
        }

        return $values[$key];
    }
}
