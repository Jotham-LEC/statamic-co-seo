<?php

namespace JothamLec\MarketingToolkit\Toolbar;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use JothamLec\MarketingToolkit\Preview\MetaPayload;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Reports\Report;
use JothamLec\MarketingToolkit\Reports\ReportPage;
use JothamLec\MarketingToolkit\Reports\Result;
use JothamLec\MarketingToolkit\SearchConsole\Client;
use JothamLec\MarketingToolkit\SearchConsole\SearchStat;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Fields;
use JothamLec\MarketingToolkit\Support\Permissions;
use JothamLec\MarketingToolkit\Support\Sites;
use JothamLec\MarketingToolkit\Support\Uris;
use JothamLec\MarketingToolkit\Tracking\Tracking;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\CP\Color;
use Statamic\Facades\Data;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use Statamic\Structures\Page;

/**
 * Builds what the front-end toolbar shows about one page for one user, as one
 * JSON shape in which each part is null when the user may not see it. It is built
 * on the page's own site (ToolbarController runs it under Sites::as()), with that
 * site's report, redirects, 404s and Search Console numbers, as the control panel
 * shows them with the site selected. Sentences come ready in the user's language,
 * and the script only fills in what the browser knows (consent).
 */
final class PageData
{
    /**
     * These checks cover what the page's HTML holds (links, headings, images, markup). They are fixed in the
     * content, so they lead to the report.
     */
    public const array REPORT_RULES = ['broken_links', 'external_links', 'image_alt', 'json_ld', 'orphan_pages', 'single_h1'];

    private Entry|Term|null $content;

    private string $path;

    private SiteObject $site;

    /** Whether the user may work on the page's site, whose report, redirects, 404s and tracking these are. */
    private bool $viewsSite;

    public function __construct(
        private User $user,
        private string $url,
        private ?int $status,
    ) {
        $this->site = Site::findByUrl($url) ?? Site::current();
        $this->viewsSite = $user->can('view', $this->site);
        $found = Data::findByRequestUrl($url);
        $found = $found instanceof Page ? $found->entry() : $found;
        // Content the user may not view in the control panel (another collection's, another site's, a draft there)
        // is treated as no content here, so its title, status and SEO stay as private as on its edit screen.
        $this->content = ($found instanceof Entry || $found instanceof Term) && $this->viewsSite && $user->can('view', $found) ? $found : null;
        $this->path = Uris::normalizePath($url);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $view = $this->viewsSite && $this->user->can(Permissions::VIEW);
        $report = $view && $this->content ? $this->report() : null;

        return [
            'user' => [
                'color_mode' => $this->user->preferredColorMode(),
                'theme' => $this->theme(),
                'csrf' => csrf_token(),
                'labels' => __('marketing-toolkit::toolbar.ui'),
            ],
            'site' => ['handle' => $this->site->handle(), 'name' => (string) $this->site->name()],
            'page' => $this->page(),
            'seo' => $report,
            'preview' => $view && $this->content ? $this->preview($this->content) : null,
            'redirects' => $this->redirects(),
            'tracking' => $view ? $this->tracking() : null,
            'sites' => $this->sites(),
            'more' => $this->more(),
        ];
    }

    /**
     * Returns the user's control panel theme (Preferences → Theme) as the colours
     * the toolbar draws with, which are the page's background, panels, borders,
     * text, the accent and the focus ring, for light and dark.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    private function theme(): array
    {
        $light = Color::theme();
        $dark = [...$light, ...collect(Color::theme(dark: true))->mapWithKeys(fn ($color, $name) => [Str::after($name, 'dark-') => $color])->all()];
        $pick = fn (array $palette, array $tokens) => array_filter(array_map(fn (string $name) => self::resolve($palette, $name), $tokens));

        return [
            'light' => $pick($light, ['bg' => 'body-bg', 'surface' => 'content-bg', 'border' => 'gray-200', 'text' => 'gray-925', 'muted' => 'gray-600', 'accent' => 'ui-accent-bg', 'link' => 'ui-accent-text', 'focus' => 'focus-outline']),
            'dark' => $pick($dark, ['bg' => 'body-bg', 'surface' => 'gray-850', 'border' => 'gray-700', 'text' => 'gray-100', 'muted' => 'gray-400', 'accent' => 'ui-accent-bg', 'link' => 'ui-accent-text', 'focus' => 'focus-outline']),
        ];
    }

    /**
     * Returns a theme colour with any `var(--theme-color-…)` it refers to filled
     * in, because the toolbar's Shadow DOM doesn't have the control panel's variables.
     *
     * @param  array<string, string>  $palette
     */
    private static function resolve(array $palette, string $name): ?string
    {
        $color = $palette[$name] ?? null;

        for ($depth = 0; $color !== null && $depth < 3 && preg_match('/var\(--theme-color-([a-z0-9-]+)\)/', $color, $match); $depth++) {
            $color = str_replace($match[0], (string) ($palette[$match[1]] ?? ''), $color);
        }

        // The value is printed into a style property, so it must be a colour and never anything that could end it.
        return $color !== null && $color !== '' && ! preg_match('/[;{}<>]/', $color) ? $color : null;
    }

    /**
     * Determines whether Statamic answers this address with a 404. It uses the status
     * the browser saw, or else checks that no content has the address and the 404 log
     * has counted it. A draft's address is missing too, for everyone but the control panel.
     */
    private function missing(): bool
    {
        if ($this->content && $this->isDraft()) {
            return true;
        }

        if ($this->status !== null) {
            return in_array($this->status, [404, 410], true);
        }

        return $this->content === null && $this->viewsSite && MissingPath::query()->ofSite(Sites::scope($this->site->handle()))->where('path', $this->path)->exists();
    }

    private function isDraft(): bool
    {
        return $this->content instanceof Entry && $this->content->status() !== 'published';
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        $content = $this->content;
        $editable = $content && $this->user->can('edit', $content);

        return [
            'type' => $content instanceof Entry ? 'entry' : ($content instanceof Term ? 'term' : null),
            'title' => $content ? (string) Fields::value($content, 'title') : null,
            'status' => $content instanceof Entry ? $content->status() : ($content ? 'published' : null),
            'missing' => $this->missing(),
            'edit_url' => $editable ? $this->cp($content->editUrl()) : null,
            'seo_url' => $editable ? $this->seoUrl($content) : null,
        ];
    }

    /**
     * Returns the edit screen, open on the tab that holds the `seo` field, found by
     * its handle in this content's blueprint (whatever the site renamed the tab to).
     * The screen opens on its first tab when no tab holds the field.
     */
    private function seoUrl(Entry|Term $content): string
    {
        $tab = $content->blueprint()?->tabs()->first(fn ($tab) => $tab->fields()->has('seo'))?->handle();

        return $this->cp($content->editUrl().($tab ? '#'.$tab : ''));
    }

    /**
     * Builds the SEO panel, with this page's row in the site's latest report, its
     * checks worst first, and its Search Console numbers.
     *
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $report = Report::latestDone($this->site->handle());
        $content = $this->content;
        $row = $report && $content ? $report->pages()
            ->where('content_type', $content instanceof Entry ? 'entry' : 'term')
            ->where('content_id', (string) $content->id())
            ->first() : null;

        return [
            'score' => $row?->score,
            'noindex' => $row?->facts()->noindex() ?? false,
            'messages' => $this->reportMessages($report, $row),
            'issues' => $report && $row ? $this->issues($report, $row) : [],
            'report_url' => $report ? $this->cp(cp_route('mt.reports.show', $report)) : $this->cp(cp_route('mt.reports.index')),
            'run_url' => $this->user->can(Permissions::REPORTS) ? $this->cp(cp_route('mt.reports.index')) : null,
            'search' => $this->search(),
        ];
    }

    /**
     * @return list<string>
     */
    private function reportMessages(?Report $report, ?ReportPage $row): array
    {
        if ($report === null) {
            return [__('marketing-toolkit::toolbar.seo.no_report')];
        }

        if ($row === null) {
            return [__('marketing-toolkit::toolbar.seo.not_in_report')];
        }

        $finished = $report->finished_at ?? $report->updated_at;
        $saved = $this->content?->lastModified();
        // When the page was saved on the day of the report, the times say which came first.
        $sameDay = $saved && $saved->isSameDay($finished);
        $messages = [$saved && $saved->gt($finished)
            ? __('marketing-toolkit::toolbar.seo.changed', ['saved' => $this->date($saved, $sameDay), 'date' => $this->date($finished, $sameDay)])
            : __('marketing-toolkit::toolbar.seo.date', ['date' => $this->date($finished)])];

        if ($row->facts()->noindex()) {
            $messages[] = __('marketing-toolkit::toolbar.seo.noindex');
        } elseif ($this->issues($report, $row) === []) {
            $messages[] = __('marketing-toolkit::toolbar.seo.passing');
        }

        return $messages;
    }

    /**
     * Lists the checks this page fails or warns about, failures first and then by
     * the check's weight, each in the reader's language and with where to fix it.
     *
     * @return list<array{label: string, status: string, message: string, url: ?string}>
     */
    private function issues(Report $report, ReportPage $row): array
    {
        $rules = collect($report->summary['rules'] ?? []);
        $fix = $this->content && $this->user->can('edit', $this->content) ? $this->seoUrl($this->content) : null;
        $detail = $this->cp(cp_route('mt.reports.show', $report));

        return collect($row->results ?? [])
            ->reject(fn (array $result) => $result['status'] === Result::PASS)
            ->map(fn (array $result, string $handle) => [
                'label' => (string) __($rules[$handle]['label'] ?? 'marketing-toolkit::reports.rules.'.$handle),
                'status' => $result['status'],
                'message' => Result::translate($result['message'], $result['params'] ?? []),
                'url' => in_array($handle, self::REPORT_RULES, true) || $handle === 'render' ? $detail : $fix,
                'weight' => (float) ($rules[$handle]['weight'] ?? 1),
            ])
            ->sortBy([fn ($a, $b) => ($a['status'] === Result::FAIL ? 0 : 1) <=> ($b['status'] === Result::FAIL ? 0 : 1), fn ($a, $b) => $b['weight'] <=> $a['weight']])
            ->map(fn (array $issue) => array_diff_key($issue, ['weight' => true]))
            ->values()
            ->all();
    }

    /**
     * Returns one sentence of Search Console's numbers for this page, once it is set up for the site.
     */
    private function search(): ?string
    {
        if (! app(Client::class)->configured($this->site->handle())) {
            return null;
        }

        $url = (string) ($this->content?->absoluteUrl() ?? strtok($this->url, '?#'));
        $stat = SearchStat::query()->shownOn($this->site->handle())->whereIn('url', array_unique([$url, rtrim($url, '/'), rtrim($url, '/').'/']))->first();

        if ($stat === null) {
            return __('marketing-toolkit::toolbar.seo.search_none');
        }

        return __('marketing-toolkit::toolbar.seo.search', [
            'days' => (int) config('marketing-toolkit.search_console.days', 28),
            'clicks' => Number::format($stat->clicks, locale: $this->locale()),
            'impressions' => Number::format($stat->impressions, locale: $this->locale()),
            // The position is rounded as the overview rounds it, because ICU would round half to even.
            'position' => Number::format(round($stat->position, 1), maxPrecision: 1, locale: $this->locale()),
        ]);
    }

    /**
     * Builds the Preview panel, with the search result and the share card as the SEO
     * tab's preview draws them, and the indexing facts as sentences.
     *
     * @return array<string, mixed>
     */
    private function preview(Entry|Term $content): array
    {
        $seo = app(SiteSeo::class);
        $meta = app(MetaPayload::class)->forContent($content);
        $languages = count(array_diff_key($seo->contentAlternates($content), ['x-default' => true]));
        $facts = [];

        if (str_contains($meta['robots'], 'noindex')) {
            $facts[] = __('marketing-toolkit::toolbar.preview.noindex', ['reason' => __('marketing-toolkit::toolbar.preview.reasons.'.$this->noindexReason($content))]);
        } elseif ($meta['canonical'] !== null && rtrim($meta['canonical'], '/') !== rtrim($meta['url'], '/')) {
            $facts[] = __('marketing-toolkit::toolbar.preview.canonical', ['url' => $meta['canonical']]);
        }

        if (Features::on('sitemap')) {
            $facts[] = $seo->inSitemap($content) ? __('marketing-toolkit::toolbar.preview.in_sitemap') : __('marketing-toolkit::toolbar.preview.not_in_sitemap');
        }

        if ($languages > 1) {
            $facts[] = __('marketing-toolkit::toolbar.preview.languages', ['count' => $languages]);
        }

        return [...$meta, 'facts' => $facts];
    }

    private function noindexReason(Entry|Term $content): string
    {
        // A translation without a group of its own takes its origin's, as its <head> does.
        $seo = Fields::value($content, 'seo');

        if (is_array($seo) && ($seo['noindex'] ?? false)) {
            return 'page';
        }

        return config('marketing-toolkit.robots.noindex_outside_production') && ! app()->isProduction() ? 'environment' : 'rule';
    }

    /**
     * Builds the Redirects panel, with the rules that send visitors here, any rule
     * from this address that a page here overrides, and on a missing page, its 404s.
     *
     * @return array<string, mixed>|null
     */
    private function redirects(): ?array
    {
        $manage = $this->viewsSite && Features::on('redirects') && $this->user->can(Permissions::REDIRECTS);
        $view = $this->viewsSite && Features::on('not_found') && $this->user->can(Permissions::VIEW);

        if (! $manage && ! $view) {
            return null;
        }

        $missing = $this->missing();
        $handle = $this->site->handle();
        $messages = [];
        $toHere = collect();

        if ($missing) {
            $messages[] = __('marketing-toolkit::toolbar.redirects.missing');

            if ($this->content && $this->isDraft()) {
                $messages[] = __('marketing-toolkit::toolbar.redirects.draft');
            }

            $logged = $view ? MissingPath::query()->ofSite(Sites::scope($handle))->where('path', $this->path)->first() : null;

            if ($logged) {
                $messages[] = trans_choice('marketing-toolkit::toolbar.redirects.logged', $logged->hits, ['count' => Number::format($logged->hits, locale: $this->locale()), 'date' => $this->date($logged->first_seen_at)]);
            }
        }

        if ($manage && ! $missing) {
            $toHere = $this->redirectsHere();
            $hits = (int) $toHere->sum('hits');
            $messages[] = $toHere->isEmpty()
                ? __('marketing-toolkit::toolbar.redirects.none')
                : trans_choice('marketing-toolkit::toolbar.redirects.to_here', $toHere->count(), ['count' => $toHere->count(), 'hits' => Number::format($hits, locale: $this->locale())]);
        }

        $ignored = $manage && $this->content && ! $missing ? $this->redirectFromHere() : null;

        if ($ignored) {
            $messages[] = __('marketing-toolkit::toolbar.redirects.ignored');
        }

        return [
            'messages' => $messages,
            'to_here' => $toHere->take(5)->map(fn (Rule $redirect) => [
                'source' => $redirect->source,
                'hits' => $redirect->hits,
                // A rule typed into a file without an id has no edit form of its own yet, so it links to the list.
                'url' => $this->cp($redirect->id === null ? cp_route('mt.redirects.index') : cp_route('mt.redirects.edit', $redirect->id)),
            ])->values()->all(),
            'ignored' => $ignored ? ['source' => $ignored->source, 'url' => $this->cp($ignored->id === null ? cp_route('mt.redirects.index') : cp_route('mt.redirects.edit', $ignored->id))] : null,
            'create_url' => $manage && $missing
                ? $this->cp(cp_route('mt.redirects.create', array_filter(['source' => $this->path, 'site' => Sites::scope($handle)])))
                : null,
        ];
    }

    /**
     * Finds the active rules on this site whose target is this page, either by its
     * path on the site (as rules store targets) or by its full address.
     *
     * @return Collection<int, Rule>
     */
    private function redirectsHere()
    {
        $url = (string) ($this->content?->absoluteUrl() ?? strtok($this->url, '?#'));
        $relative = Rule::normalizeTarget('/'.ltrim((string) $this->site->relativePath($url), '/'));

        return app(RedirectRepository::class)
            ->pointingTo(array_values(array_unique(array_filter([$relative, $url, rtrim($url, '/'), rtrim($url, '/').'/']))), Sites::multiple() ? $this->site->handle() : null)
            ->filter(fn (Rule $redirect) => $redirect->isAccessible())
            ->values();
    }

    /**
     * Finds an active rule from this address, which never applies while a page is here.
     */
    private function redirectFromHere(): ?Rule
    {
        $repository = app(RedirectRepository::class);
        $rule = $repository->forSource($this->path, Sites::scope($this->site->handle()))
            ?? (Sites::multiple() ? $repository->forSource($this->path) : null);

        return $rule && $rule->active && $rule->isAccessible() ? $rule : null;
    }

    /**
     * Builds the Tracking panel, which says which tags load on this page (or why none
     * do) and describes Consent Mode. The browser adds its own consent state.
     *
     * @return array<string, mixed>
     */
    private function tracking(): array
    {
        $tracking = app(Tracking::class);
        $ids = array_filter($tracking->ids());
        $names = fn (array $trackers) => $this->list(array_map(fn (string $tracker) => (string) __('marketing-toolkit::cp.tracking.names.'.$tracker), $trackers));
        $consent = $tracking->consent();
        $variables = GlobalSet::findByHandle((string) config('marketing-toolkit.settings_global'))?->in($this->site->handle());

        $messages = [match (true) {
            ! Features::on('tracking') => __('marketing-toolkit::toolbar.tracking.off'),
            ! $tracking->onThisSite() => __('marketing-toolkit::toolbar.tracking.off_here'),
            $ids === [] => __('marketing-toolkit::toolbar.tracking.none_set'),
            ! app()->environment((array) config('marketing-toolkit.tracking.environments')) => __('marketing-toolkit::toolbar.tracking.not_production'),
            default => __('marketing-toolkit::toolbar.tracking.printing', ['tools' => $names(array_keys($ids))]),
        }];

        if ($ids !== []) {
            $messages[] = $consent === null
                ? __('marketing-toolkit::toolbar.tracking.consent_off')
                : __('marketing-toolkit::toolbar.tracking.consent_on', ['where' => $this->consentWhere($consent['regions'])]);
            $messages[] = $tracking->conversions() ? __('marketing-toolkit::toolbar.tracking.leads_on') : __('marketing-toolkit::toolbar.tracking.leads_off');
        }

        if ($overlap = $tracking->besideGtm()) {
            $messages[] = __('marketing-toolkit::toolbar.tracking.overlap', ['tools' => $this->list($overlap)]);
        }

        return [
            'messages' => $messages,
            // The browser reads its consent state only where Consent Mode is on.
            'consent' => $consent !== null,
            'settings_url' => $variables && $this->user->can('edit', $variables) ? $this->cp($variables->editUrl()) : null,
        ];
    }

    /**
     * @param  list<string>  $regions
     */
    private function consentWhere(array $regions): string
    {
        if ($regions === []) {
            return __('marketing-toolkit::toolbar.tracking.everywhere');
        }

        $eea = array_diff(Tracking::EEA_REGIONS, $regions) === [];
        $others = $eea ? array_values(array_diff($regions, Tracking::EEA_REGIONS)) : $regions;

        return __('marketing-toolkit::toolbar.tracking.in_regions', ['regions' => $this->list([...($eea ? [__('marketing-toolkit::toolbar.tracking.eea')] : []), ...$others])]);
    }

    /**
     * Builds the Sites panel, which shows this page on each other site the user may see,
     * with its status, its address and its edit screen, or says that it isn't there yet.
     *
     * @return list<array<string, mixed>>|null
     */
    private function sites(): ?array
    {
        $content = $this->content;

        if (! Sites::multiple() || $content === null) {
            return null;
        }

        $versions = $this->versions($content);
        /** @var ?Collection<int, string> $sites */
        $sites = $content instanceof Entry ? $content->collection()->sites() : $content->taxonomy()?->sites();

        return Site::authorized()
            ->filter(fn ($site) => $site->handle() !== $content->locale() && collect($sites)->contains($site->handle()))
            ->map(function ($site) use ($versions) {
                $version = $versions[$site->handle()] ?? null;

                if ($version === null) {
                    return ['site' => (string) $site->name(), 'handle' => $site->handle(), 'missing' => __('marketing-toolkit::toolbar.sites.missing', ['site' => $site->name()])];
                }

                $status = $version instanceof Entry ? $version->status() : 'published';

                return [
                    'site' => (string) $site->name(),
                    'handle' => $site->handle(),
                    'status' => __('marketing-toolkit::toolbar.sites.statuses.'.$status),
                    'url' => $status === 'published' ? $version->absoluteUrl() : null,
                    'edit_url' => $this->user->can('edit', $version) ? $this->cp($version->editUrl(), $site->handle()) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, Entry|Term> Maps each site handle to this content on that site.
     */
    private function versions(Entry|Term $content): array
    {
        if ($content instanceof Entry) {
            /** @var Entry $root */
            $root = $content->root();
            /** @var list<Entry> $descendants */
            $descendants = $root->descendants()->values()->all();

            return collect([$root, ...$descendants])->keyBy(fn (Entry $entry): string => $entry->locale())->all();
        }

        /** @var ?Collection<int, string> $sites */
        $sites = $content->taxonomy()?->sites();

        return collect($sites ?? [])->keyBy(fn (string $site): string => $site)->map(fn (string $site): Term => $content->in($site))->filter()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function more(): array
    {
        return [
            'dashboard_url' => $this->cp(cp_route('dashboard')),
            'cache' => (bool) config('statamic.static_caching.strategy') && $this->user->can('access cache utility'),
            'cache_url' => route('statamic.mt.toolbar.cache', [], false),
        ];
    }

    /**
     * Returns a control panel address that opens on this page's site. On a multi-site
     * install, it goes through `toolbar/go`, which selects the site first.
     */
    private function cp(string $url, ?string $site = null): string
    {
        if (! Sites::multiple()) {
            return $url;
        }

        $to = (string) parse_url($url, PHP_URL_PATH).(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '').(($fragment = parse_url($url, PHP_URL_FRAGMENT)) ? '#'.$fragment : '');

        return cp_route('mt.toolbar.go', ['site' => $site ?? $this->site->handle(), 'to' => $to]);
    }

    private function date(CarbonInterface $date, bool $time = false): string
    {
        return $date->locale($this->locale())->isoFormat($time ? 'LLL' : 'LL');
    }

    private function locale(): string
    {
        return app()->getLocale();
    }

    /**
     * Joins the items as "A, B and C", in the reader's language.
     *
     * @param  list<string>  $items
     */
    private function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' '.__('marketing-toolkit::toolbar.ui.and').' '.$last;
    }
}
