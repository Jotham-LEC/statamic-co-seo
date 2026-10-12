<?php

namespace JothamLec\MarketingToolkit\Http\Controllers\CP;

use Inertia\Inertia;
use Inertia\Response;
use JothamLec\MarketingToolkit\Favicons\Favicons;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Reports\Report;
use JothamLec\MarketingToolkit\SearchConsole\Client;
use JothamLec\MarketingToolkit\SearchConsole\SearchStat;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Package;
use JothamLec\MarketingToolkit\Support\Permissions;
use JothamLec\MarketingToolkit\Support\Sites;
use JothamLec\MarketingToolkit\Tracking\Tracking;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Addon;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Shows Marketing → Overview, where the site stands (the latest report, redirects, recent
 * 404s, the brand defaults, the files it serves), each with a way into the
 * screen that changes it. Links the person may not use are left out. On a
 * multi-site install, everything is for the site selected in the control panel.
 */
final class OverviewController
{
    public function __construct(
        private SiteSeo $seo,
        private Client $searchConsole,
        private Tracking $tracking,
        private Generator $cards,
        private Favicons $favicons,
    ) {}

    public function __invoke(): Response
    {
        $user = User::current();

        $site = Sites::multiple() ? Site::selected()->handle() : Site::default()->handle();

        // This uses the selected site's brand values and addresses, not those of the control panel's domain.
        return Sites::as($site, fn () => $this->render($user, $site));
    }

    private function render(UserContract $user, string $site): Response
    {
        $variables = GlobalSet::findByHandle((string) config('marketing-toolkit.global'))?->in($site);
        // The Tracking tab is in Marketing settings, or in Brand on a site that hasn't moved it yet.
        $trackingSet = GlobalSet::findByHandle((string) config('marketing-toolkit.settings_global'))?->in($site) ?? $variables;
        $redirects = fn () => Redirect::query()->where('active', true)->when(Sites::multiple(), fn ($query) => $query->appliesOn($site));

        return Inertia::render('marketing-toolkit::Overview', [
            'siteName' => $this->seo->settings()->siteName(),
            'global' => [
                'exists' => $variables !== null,
                'url' => $variables && $user->can('edit', $variables) ? $variables->editUrl() : null,
                'separator' => $this->seo->settings()->titleSiteName() ? $this->seo->settings()->separator() : null,
                'titleName' => $this->seo->settings()->titleName(),
                'description' => $this->seo->settings()->string('default_description'),
            ],
            'report' => $this->report($user, $site),
            'redirects' => $user->can(Permissions::REDIRECTS) ? [
                'active' => $redirects()->count(),
                'automatic' => $redirects()->where('automatic', true)->count(),
                'url' => cp_route('mt.redirects.index'),
            ] : null,
            'notFound' => [
                'paths' => MissingPath::query()->shownOn($site)->count(),
                'recent' => MissingPath::recent($site),
                'url' => cp_route('mt.404s.index'),
            ],
            'search' => $this->search($site),
            'searchConsole' => ['url' => cp_route('mt.search-console.index')],
            // This is worked out on the site's own address, which can differ from the control panel's.
            'tracking' => $this->tracking($trackingSet && $user->can('edit', $trackingSet) ? $trackingSet->editUrl() : null),
            // This says when share cards are on but can't be drawn (Og\Generator::available()),
            // so that a missing card isn't a mystery.
            'cardsUnavailable' => Features::on('share_cards') && ! $this->cards->available(),
            // The paths start at the domain's root, where the web server and the addon's routes serve them,
            // even for a site under a folder.
            'files' => collect([
                __('marketing-toolkit::cp.overview.files.sitemap') => Features::on('sitemap') ? '/sitemap.xml' : null,
                __('marketing-toolkit::cp.overview.files.robots') => Features::on('robots_txt') ? '/robots.txt' : null,
                __('marketing-toolkit::cp.overview.files.llms') => Features::on('llms_txt') ? '/llms.txt' : null,
                __('marketing-toolkit::cp.overview.files.favicon') => Features::on('favicons') && $this->favicons->version() ? '/site.webmanifest' : null,
                __('marketing-toolkit::cp.overview.files.card') => Features::on('share_cards') && $this->cards->available() ? '/og.png' : null,
            ])->filter()->map(fn ($path, $label) => [
                'label' => $label,
                'url' => $this->seo->absolute($path),
                // When this file exists, the web server answers with it instead of the addon's.
                'public' => file_exists(public_path(ltrim($path, '/'))),
            ])->values(),
        ]);
    }

    /**
     * Lists the trackers that are set, each with where it comes from, and those loaded beside GTM.
     *
     * @return array<string, mixed>
     */
    private function tracking(?string $url): array
    {
        // An ID comes from .env when the config holds it, and an ID that a Tracking subclass returns comes from code.
        $fromEnv = fn (string $tracker, string $id) => strcasecmp((string) $this->tracking->configured(Tracking::FIELDS[$tracker]), $id) === 0;

        return [
            'tools' => collect($this->tracking->ids())->filter()->map(fn (string $id, string $tracker) => [
                'name' => __('marketing-toolkit::cp.tracking.names.'.$tracker),
                'id' => $id,
                'from_env' => $fromEnv($tracker, $id),
            ])->values()->all(),
            // A value that is set but isn't an ID is never printed, so this says where to fix it.
            'invalid' => collect($this->tracking->invalid())->map(fn (string $value, string $tracker) => __('marketing-toolkit::cp.tracking.invalid', [
                'name' => __('marketing-toolkit::cp.tracking.names.'.$tracker),
                'value' => $value,
                'where' => $fromEnv($tracker, $value) ? 'MT_'.strtoupper(Tracking::FIELDS[$tracker]) : __('marketing-toolkit::cp.tracking.where_global'),
            ]))->values()->all(),
            'consent' => $this->tracking->consent() !== null,
            'off_here' => ! $this->tracking->onThisSite(),
            'overlap' => $this->tracking->besideGtm(),
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(UserContract $user, string $site): array
    {
        $latest = Report::latestDone($site);

        return [
            'latest' => $latest === null ? null : [
                'score' => (int) $latest->score,
                'pages' => $latest->scoredPages(),
                'finished_at' => $latest->finished_at?->toIso8601String(),
                'url' => cp_route('mt.reports.show', $latest),
                // These are the checks that most pages fail, shown beside the gauge.
                'checks' => collect($latest->summary['rules'] ?? [])
                    ->filter(fn (array $rule) => ($rule['fail'] ?? 0) > 0)
                    ->sortByDesc(fn (array $rule) => [$rule['fail'] * ($rule['weight'] ?? 1), $rule['fail']])
                    ->take(5)
                    ->map(fn (array $rule) => ['label' => __($rule['label']), 'fail' => (int) $rule['fail']])
                    ->values()
                    ->all(),
            ],
            'url' => cp_route('mt.reports.index'),
            // This link opens the Settings tab of Reports.
            'settings_url' => Package::canEditSettings() ? cp_route('mt.reports.index').'#settings' : null,
        ];
    }

    /**
     * Returns Search Console's numbers once it is set up, which are the totals
     * for the period and the pages with the most clicks.
     *
     * @return array<string, mixed>|null
     */
    private function search(string $site): ?array
    {
        if (! $this->searchConsole->configured($site)) {
            return null;
        }

        $stats = fn () => SearchStat::query()->shownOn($site);
        $first = $stats()->first();

        return [
            'fetched_at' => $first?->fetched_at?->toIso8601String(),
            'from' => $first?->from?->toDateString(),
            'to' => $first?->to?->toDateString(),
            'clicks' => (int) $stats()->sum('clicks'),
            'impressions' => (int) $stats()->sum('impressions'),
            'top' => $stats()->orderByDesc('clicks')->orderByDesc('impressions')->limit(5)->get()
                ->map(fn (SearchStat $row) => [
                    'path' => parse_url($row->url, PHP_URL_PATH) ?: '/',
                    'clicks' => $row->clicks,
                    'impressions' => $row->impressions,
                    'position' => round($row->position, 1),
                ])->all(),
        ];
    }
}
