<?php

namespace JothamLec\MarketingToolkit\TextFiles;

use Closure;
use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\Settings;
use JothamLec\MarketingToolkit\Sitemap\Sitemap;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Sites;
use JothamLec\MarketingToolkit\Support\Text;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;

/**
 * This class builds robots.txt, llms.txt, and ads.txt. SiteSeo's robotsTxt(),
 * llmsTxt(), and adsTxt() call it, and a site overrides those, not this class.
 */
final class TextFiles
{
    public function __construct(private Settings $settings, private Sitemap $sitemap) {}

    /**
     * Builds robots.txt from the paths the Brand global disallows (the control
     * panel, unless it names others), its Content-Signal line, the AI crawlers
     * it turns away, its extra lines, and the sitemap. Everything is disallowed
     * on a copy of the site that is kept out of search engines ($hidden).
     */
    public function robots(bool $hidden, string $sitemapUrl, ?string $newsSitemapUrl = null): string
    {
        if ($hidden) {
            return "User-agent: *\nDisallow: /\n";
        }

        $lines = ['User-agent: *'];

        $disallow = $this->settings->list('robots_disallow') ?: ['/'.trim((string) config('statamic.cp.route'), '/').'/'];

        foreach ($disallow as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        if ($this->settings->bool('content_signals', true)) {
            $lines[] = $this->contentSignal();
        }

        array_push($lines, ...$this->aiCrawlerRules());

        if ($extra = $this->settings->string('robots_extra')) {
            $lines[] = '';
            $lines[] = trim($extra);
        }

        if (Features::on('sitemap')) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.$sitemapUrl;

            if ($newsSitemapUrl !== null) {
                $lines[] = 'Sitemap: '.$newsSitemapUrl;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Builds /llms.txt (see llmstxt.org). It lists the site's name and
     * description, then, for each collection the sitemap lists, its pages as
     * Markdown links with their descriptions, most recently changed first. It
     * is for AI assistants that read a site's summary before its pages.
     * llms.txt is only read at a domain's root, so, like the sitemap, it lists
     * every site on the domain, and a site under a folder (/fr/) gets its own
     * sections, named after it.
     *
     * @param  int  $perCollection  the maximum number of pages listed per collection
     * @param  Closure(Context): ?string  $describe  returns a page's description
     */
    public function llms(SiteSeo $seo, int $perCollection, Closure $describe): string
    {
        $lines = ['# '.$this->settings->siteName(), ''];

        if ($description = $this->settings->string('default_description')) {
            $lines = [...$lines, '> '.Text::plain($description), ''];
        }

        $sites = $seo->sitemapSites();

        foreach ($sites as $site) {
            $sections = Sites::as($site, fn () => $this->llmsSections($seo, $perCollection, $describe, named: count($sites) > 1));
            $lines = [...$lines, ...$sections];
        }

        return implode("\n", $lines);
    }

    /**
     * Builds /ads.txt from the lines in Marketing settings → Crawlers, for a
     * site that sells ad space. It returns null when there are none.
     */
    public function ads(): ?string
    {
        $text = trim((string) $this->settings->string('ads_txt'));

        return $text === '' ? null : $text."\n";
    }

    /**
     * Returns the current site's sections of llms.txt, one per collection.
     * Each section is named with the site when the domain has several.
     *
     * @param  Closure(Context): ?string  $describe
     * @return list<string>
     */
    private function llmsSections(SiteSeo $seo, int $perCollection, Closure $describe, bool $named): array
    {
        $lines = [];
        $site = Site::current();
        $collections = $this->sitemap->collections([$site->handle()])->sortBy(fn ($collection) => $collection->title());

        foreach ($collections as $collection) {
            $entries = Entries::query()
                ->where('collection', $collection->handle())
                ->where('site', $site->handle())
                ->whereStatus('published')
                ->get()
                ->filter(fn (Entry $entry) => $seo->inSitemap($entry))
                // Newest first; pages changed in the same second (an import, a migration)
                // by title, so the list doesn't change order from one build to the next.
                ->sortBy([
                    fn (Entry $a, Entry $b) => $b->lastModified()?->timestamp <=> $a->lastModified()?->timestamp,
                    fn (Entry $a, Entry $b) => strnatcasecmp((string) $a->value('title'), (string) $b->value('title')),
                ])
                ->take($perCollection);

            if ($entries->isEmpty()) {
                continue;
            }

            $lines[] = '## '.$collection->title().($named ? ' ('.$site->name().')' : '');
            $lines[] = '';

            foreach ($entries as $entry) {
                $description = $describe(Context::make($entry));
                $description = $description === null ? null : Text::limit(Text::plain($description), 200);
                $title = str_replace(['[', ']'], ['(', ')'], (string) $entry->value('title'));

                $lines[] = '- ['.$title.']('.$entry->absoluteUrl().')'.($description ? ': '.$description : '');
            }

            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Builds the Content-Signal line (contentsignals.org) from the AI switches:
     * search is always allowed, ai-input (pages used in AI answers) follows AI
     * search, and ai-train follows AI training. It sits in the `User-agent: *`
     * group, so it covers every crawler.
     */
    private function contentSignal(): string
    {
        $signal = fn (string $setting) => $this->settings->bool($setting, true) ? 'yes' : 'no';

        return 'Content-Signal: search=yes, ai-input='.$signal('allow_ai_search').', ai-train='.$signal('allow_ai_training');
    }

    /**
     * Returns the robots.txt groups for the AI crawlers the Brand global turns
     * away, which are those that gather training data and those that index
     * for AI search answers. Fetchers a person sends (ChatGPT-User and
     * Perplexity-User) don't all read robots.txt, so they aren't listed.
     *
     * @return list<string>
     */
    private function aiCrawlerRules(): array
    {
        $agents = [
            ...($this->settings->bool('allow_ai_training', true) ? [] : SiteSeo::AI_TRAINING_AGENTS),
            ...($this->settings->bool('allow_ai_search', true) ? [] : SiteSeo::AI_SEARCH_AGENTS),
        ];

        if ($agents === []) {
            return [];
        }

        return ['', ...array_map(fn (string $agent) => 'User-agent: '.$agent, $agents), 'Disallow: /'];
    }
}
