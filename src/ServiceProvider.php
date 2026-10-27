<?php

namespace JothamLec\MarketingToolkit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use JothamLec\MarketingToolkit\Boost\Boost;
use JothamLec\MarketingToolkit\Conversions\Attribution;
use JothamLec\MarketingToolkit\Cp\Navigation;
use JothamLec\MarketingToolkit\Events\RedirectDeleted;
use JothamLec\MarketingToolkit\Events\RedirectSaved;
use JothamLec\MarketingToolkit\Http\Middleware\AdvertiseLlmsTxt;
use JothamLec\MarketingToolkit\Http\Middleware\HandleMissing;
use JothamLec\MarketingToolkit\Http\Middleware\MarkToolbarUser;
use JothamLec\MarketingToolkit\Http\Middleware\NegotiateMarkdown;
use JothamLec\MarketingToolkit\IndexNow\IndexNow;
use JothamLec\MarketingToolkit\Legacy\OldConfig;
use JothamLec\MarketingToolkit\Listeners\CountConversion;
use JothamLec\MarketingToolkit\Listeners\FlushSitemap;
use JothamLec\MarketingToolkit\Listeners\RedirectChangedUris;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use JothamLec\MarketingToolkit\Redirects\Drivers;
use JothamLec\MarketingToolkit\Redirects\File\RedirectsStore;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Reports\ReportSettings;
use JothamLec\MarketingToolkit\SearchConsole\Client as SearchConsoleClient;
use JothamLec\MarketingToolkit\SearchConsole\Connection;
use JothamLec\MarketingToolkit\Support\Config;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Permissions;
use JothamLec\MarketingToolkit\Support\Sites;
use JothamLec\MarketingToolkit\Toolbar\Toolbar;
use JothamLec\MarketingToolkit\Tracking\Tracking;
use Statamic\Events\CollectionSaved;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryScheduleReached;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\StacheCleared;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Facades\Git;
use Statamic\Facades\Permission;
use Statamic\Facades\Stache;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    /*
     * Views, translations, fieldsets and blueprints all use `marketing-toolkit::`, the addon's slug.
     * Statamic names the last three after the slug already, but it names views after the package
     * unless it is told otherwise.
     */
    protected $viewNamespace = 'marketing-toolkit';

    /** The config is merged in register() instead (see mergeConfigFrom), before anything that reads it boots. */
    protected $config = false;

    protected $vite = [
        'input' => ['resources/js/addon.js', 'resources/css/addon.css'],
        'publicDirectory' => 'resources/dist',
    ];

    protected $middlewareGroups = [
        'statamic.web' => [HandleMissing::class],
        // This runs after Statamic's own middleware, so the user is already known.
        'statamic.cp.authenticated' => [MarkToolbarUser::class],
    ];

    /*
     * FlushSitemap's handle() takes no event, so Statamic can't find its events from it
     * as it does for the other listeners in Listeners/.
     */
    protected $listen = [
        EntrySaved::class => [FlushSitemap::class],
        EntryDeleted::class => [FlushSitemap::class],
        // Statamic's scheduler fires this when a scheduled entry goes live or an expiring one goes away.
        EntryScheduleReached::class => [FlushSitemap::class],
        TermSaved::class => [FlushSitemap::class],
        TermDeleted::class => [FlushSitemap::class],
        CollectionTreeSaved::class => [FlushSitemap::class],
        // A new route moves every entry or term in the collection or taxonomy.
        CollectionSaved::class => [FlushSitemap::class],
        TaxonomySaved::class => [FlushSitemap::class],
        // A deploy clears the Stache, and the rules may have changed along with the code.
        StacheCleared::class => [FlushSitemap::class],
        // llms.txt starts with Brand's description, and saving any global set is rare enough to flush on.
        GlobalVariablesSaved::class => [FlushSitemap::class],
    ];

    protected $subscribe = [RedirectChangedUris::class];

    public function register(): void
    {
        parent::register();

        // The config is merged here rather than in Statamic's bootConfig(), which runs after
        // the Features switches read it.
        $this->mergeConfigFrom(__DIR__.'/../config/marketing-toolkit.php', 'marketing-toolkit');

        // A site binds its own subclass in its service provider, which registers after this one.
        // The `marketing-toolkit.class` config key still works until 1.0.
        $this->app->bind(SiteSeo::class, fn ($app) => $app->build(config('marketing-toolkit.class') ?? SiteSeo::class));

        // It is a single instance, so what it learns while content saves is still there once the save is done.
        $this->app->singleton(RedirectChangedUris::class);

        // There is one instance per request, because it gathers the changed addresses until the response is out.
        $this->app->singleton(IndexNow::class);

        // Redirect rules are stored by the driver `redirects.driver` names, chosen each time the repository
        // is resolved, so a change to the config takes effect at once. Drivers remembers for the request
        // whether rules are still in the database (its safety net).
        $this->app->scoped(Drivers::class);
        $this->app->bind(RedirectRepository::class, fn ($app) => $app->make(Drivers::class)->repository());
    }

    /**
     * Merges the site's config/marketing-toolkit.php over the addon's at every depth (Support\Config),
     * rather than at Laravel's single level, so a site states only what it changes, even inside `og`
     * or `robots`. Keys renamed since the site published its copy are read under their new names.
     */
    protected function mergeConfigFrom($path, $key)
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $config->set($key, Config::merge(require $path, OldConfig::upgrade((array) $config->get($key, []))));
    }

    /**
     * @return void
     */
    public function boot()
    {
        // This runs ahead of the parent's own callback, which registers the routes, listeners and
        // middleware, because each listener and middleware asks for itself whether its module is on.
        Statamic::booted(fn () => Features::apply());

        parent::boot();
    }

    public function bootAddon(): void
    {
        // Global, not in a route group: Statamic's static cache keeps the headers a response has once
        // the route's middleware are done, so a page converted to Markdown there would be cached as
        // HTML under Markdown's Content-Type. Out here the cache has already kept the HTML page.
        $kernel = $this->app->make(Kernel::class);
        $kernel->pushMiddleware(NegotiateMarkdown::class);
        $kernel->pushMiddleware(AdvertiseLlmsTxt::class);

        $this->publishes([__DIR__.'/../config/marketing-toolkit.php' => config_path('marketing-toolkit.php')], 'marketing-toolkit-config');

        // The page's own script writes and reads these cookies, so they are left as plain text.
        EncryptCookies::except([Attribution::COOKIE, CountConversion::COOKIE, Toolbar::COOKIE]);

        // This uses a key and property set up in the control panel when .env has none.
        Connection::apply();

        $this->app->terminating(fn () => $this->app->make(IndexNow::class)->flush());

        // A queue worker doesn't terminate between jobs, so this sends what each job changed once it is done.
        // A sync job is skipped, because it is part of the request or command that ran it, which terminates as usual.
        Queue::after(function (JobProcessed $event) {
            if ($event->connectionName !== 'sync') {
                $this->app->make(IndexNow::class)->flush();
            }
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The file driver's rules are a Stache store, so `stache:clear` and `stache:warm` read them afresh.
        Stache::registerStore(new RedirectsStore);
        self::listenForGit();

        Permission::extend(fn () => Permission::group('marketing-toolkit', __('marketing-toolkit::cp.permissions.group'), function () {
            Permission::register(Permissions::VIEW)->label(__('marketing-toolkit::cp.permissions.view'));
            Permission::register(Permissions::REDIRECTS)->label(__('marketing-toolkit::cp.permissions.redirects'));
            Permission::register(Permissions::REPORTS)->label(__('marketing-toolkit::cp.permissions.reports'));
        }));

        Navigation::register();

        // With Laravel Boost installed, the addon's read-only tools join Boost's MCP server.
        Boost::register();

        // These values are worked out when the control panel renders, which has the CP routes,
        // and a front-end request never asks for them.
        Statamic::provideToScript(['marketingToolkit' => fn () => [
            // When automatic redirects are off, a save has nothing to ask the redirect check.
            'automaticRedirects' => Features::on('automatic_redirects'),
            'urls' => ['redirectCheck' => cp_route('mt.redirects.check'), 'redirectChoice' => cp_route('mt.redirects.choice')],
            // These are the Brand and Marketing settings globals, and the Tracking tab is in one of them.
            'globals' => Settings::handles(),
            // These are the Tracking tab's fields and those set in .env, which the tab's warning counts as well.
            'trackingFields' => Tracking::FIELDS,
            'trackingFromConfig' => array_filter(app(Tracking::class)->fromConfig()),
        ]]);
    }

    /**
     * With Statamic's Git automation on, saving or deleting a redirect commits the rule files, as saving
     * an entry commits its file.
     */
    public static function listenForGit(): void
    {
        if (config('statamic.git.enabled')) {
            Git::listen(RedirectSaved::class);
            Git::listen(RedirectDeleted::class);
        }
    }

    /**
     * Statamic builds an addon's schedule on every console boot, which means each artisan
     * command, queue job process and test. Reading the report settings costs 15 to 25 ms
     * there, because Statamic parses each default value as Antlers, and it is spent only to
     * learn that reports are off. Only the commands that run the schedule, list it, or finish
     * a background event of it need it.
     *
     * @param  Schedule  $schedule
     * @return void
     */
    protected function schedule($schedule)
    {
        if (! $this->app->runningConsoleCommand(['schedule:run', 'schedule:work', 'schedule:test', 'schedule:list', 'schedule:finish'])) {
            return;
        }

        // This applies a key set up in the control panel, which bootAddon() would only apply after this.
        Connection::apply();

        $this->scheduleJobs($schedule);
    }

    private function scheduleJobs(Schedule $schedule): void
    {
        $settings = app(ReportSettings::class);
        // When reports are off under Features (or in config/marketing-toolkit.php), they run only by hand.
        $schedules = Features::on('reports') ? $settings->get('schedule') : 'off';
        $time = substr((string) $settings->get('schedule_time'), 0, 5) ?: '03:00';
        $day = array_search($settings->get('schedule_day'), ReportSettings::DAYS, true);

        // A multi-site install gets one run per site, each with its own overlap lock.
        foreach (Sites::multiple() ? Sites::handles() : [null] as $site) {
            $command = $site === null ? 'statamic:mt:report' : 'statamic:mt:report --site='.$site;

            $event = match ($schedules) {
                'daily' => $schedule->command($command)->dailyAt($time),
                'weekly' => $schedule->command($command)->weeklyOn($day === false ? 1 : $day, $time),
                default => null,
            };

            $event?->withoutOverlapping()->runInBackground();
        }

        if (app(SearchConsoleClient::class)->configuredForAnySite()) {
            $schedule->command('statamic:mt:search-console')->dailyAt('04:30')->withoutOverlapping();
        }

        // This keeps the 404 log to `not_found.max_rows` rows (see MissingPath::prunable()).
        if (Features::on('not_found')) {
            $schedule->command('model:prune', ['--model' => [MissingPath::class]])->daily();
        }
    }
}
