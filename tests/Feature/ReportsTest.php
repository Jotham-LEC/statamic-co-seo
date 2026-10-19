<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use JothamLec\MarketingToolkit\Fieldtypes\SeoPreview;
use JothamLec\MarketingToolkit\Reports\Report;
use JothamLec\MarketingToolkit\Reports\ReportPage;
use JothamLec\MarketingToolkit\Reports\ReportSettings;
use JothamLec\MarketingToolkit\Reports\Runner;
use JothamLec\MarketingToolkit\Reports\RunReportStep;
use JothamLec\MarketingToolkit\ServiceProvider;
use JothamLec\MarketingToolkit\Widgets\SeoWidget;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

beforeEach(fn () => seoGlobal([]));

/**
 * @param  array<string, mixed>  $values
 */
function reportSettings(array $values = []): ReportSettings
{
    $settings = new ReportSettings($values);
    app()->instance(ReportSettings::class, $settings);

    return $settings;
}

function fullReport(): Report
{
    $runner = app(Runner::class);

    return $runner->runToEnd($runner->start());
}

function reportPage(Report $report, string $path): ReportPage
{
    return $report->pages()->where('url', 'https://example.test'.$path)->sole();
}

test('a report renders every published page, runs the checks and scores the site', function () {
    entryIn('home', 'home', ['title' => 'Home', 'description' => 'The home page of the Acme site, where it all starts.']);
    entryIn('pages', 'about', ['title' => 'About the Acme company', 'description' => 'Who we are, what we make and why we make it, in brief.']);
    entryIn('pages', 'team', ['title' => 'About the Acme company', 'description' => 'The people behind Acme and what each of them does here.', 'body' => '<a href="/nowhere">x</a><img src="/a.jpg">']);
    entryIn('pages', 'hidden', ['seo' => ['noindex' => true]]);
    entryIn('pages', 'draft')->published(false)->save();

    $report = fullReport();

    expect($report->status)->toBe(Report::DONE)
        ->and($report->pages_total)->toBe(4)
        ->and($report->pages_done)->toBe(4)
        ->and($report->summary)->toMatchArray(['scored' => 3, 'noindex' => 1, 'errors' => 0])
        ->and($report->summary['rules']['title_unique'])->toMatchArray(['fail' => 2, 'warn' => 0])
        ->and($report->summary['rules']['broken_links']['fail'])->toBe(1)
        ->and($report->pages()->pluck('url')->all())->not->toContain('https://example.test/draft');

    $team = reportPage($report, '/team');
    expect($team->results['title_unique'])->toBe(['status' => 'fail', 'message' => 'marketing-toolkit::reports.messages.title_same', 'params' => ['pages' => '/about']])
        ->and($team->results['broken_links']['params']['links'])->toContain('/nowhere')
        ->and($team->results['image_alt']['status'])->toBe('fail')
        ->and($team->results['canonical']['status'])->toBe('pass')
        ->and($team->results['og_image']['status'])->toBe('pass')
        ->and($team->results['json_ld']['status'])->toBe('pass')
        ->and($team->failing)->toContain(',title_unique:fail,')
        ->and($team->score)->toBeLessThan(reportPage($report, '/about')->score);

    // Hidden from search engines: listed and checked against the sitemap, not scored.
    $hidden = reportPage($report, '/hidden');
    expect($hidden->score)->toBeNull()->and(array_keys($hidden->results))->toBe(['noindex_in_sitemap']);

    $scores = $report->pages()->whereNotNull('score')->pluck('score');
    expect($report->score)->toBe((int) round($scores->avg()));
});

test('outside production the environment’s noindex is ignored, so a local report means something', function () {
    $this->app['env'] = 'local';
    entryIn('pages', 'about');

    $facts = reportPage(fullReport(), '/about')->facts();

    expect($facts->noindex())->toBeFalse()->and(config('marketing-toolkit.robots.noindex_outside_production'))->toBeTrue();
});

test('a report runs in steps of the chunk size', function () {
    reportSettings(['chunk_size' => 2]);
    foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
        entryIn('pages', $slug);
    }

    $runner = app(Runner::class);
    $report = $runner->start();

    expect($runner->step($report)->only(['status', 'pages_done']))->toBe(['status' => 'running', 'pages_done' => 2])
        ->and($runner->step($report)->pages_done)->toBe(4)
        ->and($runner->step($report)->only(['status', 'pages_done']))->toBe(['status' => 'done', 'pages_done' => 5]);
});

test('a step of slow pages stops after 45 seconds and leaves the rest of its chunk to the next', function () {
    reportSettings(['chunk_size' => 5]);
    foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
        entryIn('pages', $slug);
    }
    Carbon::setTestNow('2026-10-07 12:00:00');
    // Each page takes 30 seconds to render.
    View::composer('default', fn () => Carbon::setTestNow(now()->addSeconds(30)));

    $runner = app(Runner::class);
    $report = $runner->start();

    expect($runner->step($report)->only(['status', 'pages_done']))->toBe(['status' => 'running', 'pages_done' => 2])
        ->and($runner->step($report)->pages_done)->toBe(4)
        ->and($runner->step($report)->only(['status', 'pages_done']))->toBe(['status' => 'done', 'pages_done' => 5]);
});

test('a queued step ends before the queue would hand it to a second worker', function () {
    $step = new RunReportStep(1);

    // 90 seconds is Laravel's default `retry_after`; the step's last page may start just before its budget ends.
    expect($step->timeout)->toBeLessThan(90)
        ->and($step->timeout)->toBeGreaterThan(Runner::STEP_SECONDS)
        ->and($step->tries)->toBe(1);
});

test('a report that failed while its last step ran stays failed', function () {
    entryIn('pages', 'about');
    $runner = app(Runner::class);
    $report = $runner->start();
    $runner->fail($report);

    $runner->finish($report);

    expect($report->refresh()->status)->toBe(Report::FAILED);
});

test('pages render as a visitor sees them, not as whoever started the report', function () {
    // Signed in through the session, as in a control panel request: a guard reads the user back from it.
    config(['auth.providers.users.driver' => 'statamic']);
    auth()->forgetGuards();
    $user = cpUser(super: true);
    session()->put(auth()->guard()->getName(), $user->id());
    expect(auth()->id())->toBe($user->id());
    entryIn('pages', 'about');
    $seen = null;
    View::composer('default', function () use (&$seen) {
        $seen = auth()->check();
    });

    fullReport();

    expect($seen)->toBeFalse()
        ->and(auth()->user()?->id())->toBe($user->id());
});

test('turned-off checks, left-out collections and the page limit', function () {
    reportSettings(['rule_og_image' => false, 'excluded_collections' => ['essays'], 'max_pages' => 2]);
    entryIn('essays', 'left-out', date: '2026-01-01');
    foreach (['a', 'b', 'c'] as $slug) {
        entryIn('pages', $slug);
    }

    $report = fullReport();

    expect($report->pages_total)->toBe(2)
        ->and($report->summary['rules'])->not->toHaveKey('og_image')
        ->and($report->pages()->first()->results)->not->toHaveKey('og_image')
        ->and($report->pages()->pluck('url')->all())->not->toContain('https://example.test/essays/left-out');
});

test('a page that fails to render scores zero and says why', function () {
    Collection::make('broken')->routes('broken/{slug}')->template('missing-template')->save();
    entryIn('broken', 'page');

    $report = fullReport();
    $page = reportPage($report, '/broken/page');

    expect($page->score)->toBe(0)
        ->and($page->results['render']['status'])->toBe('fail')
        ->and($report->summary['errors'])->toBe(1);

    $this->actingAs(cpUser(super: true));
    expect($this->getJson(cp_route('mt.reports.pages', $report))->json('data.0.issues.0'))
        ->toMatchArray(['label' => 'Page renders', 'status' => 'fail'])
        ->and($this->getJson(cp_route('mt.reports.pages', $report))->json('data.0.issues.0.message'))->not->toStartWith('marketing-toolkit::');
});

test('a page a site route draws from an entry renders as visitors get it', function () {
    // As a site does when a controller shows the entry: the collection has a route but no template.
    Collection::make('cases')->routes('cases/{slug}')->template('no-such-template')->save();
    entryIn('cases', 'acme');
    app('router')->getRoutes()->getByName('statamic.site')->fallback();
    Route::middleware('web')->get('cases/{slug}', fn (string $slug) => "<html><head><title>Case {$slug}</title></head><body><h1>Case {$slug}</h1></body></html>");
    app('router')->getRoutes()->refreshNameLookups();

    $report = fullReport();
    $page = reportPage($report, '/cases/acme');

    expect($page->results)->not->toHaveKey('render')
        ->and($page->facts['title'] ?? null)->toBe('Case acme')
        ->and($report->summary['errors'])->toBe(0);
});

test('a page that throws shows the exception’s class, not its text, which goes to the log', function () {
    View::composer('form', fn () => throw new RuntimeException('SQLSTATE[HY000] secret-db.internal password=hunter2'));
    entryIn('pages', 'contact', ['template' => 'form']);
    Log::spy();

    $report = fullReport();

    $this->actingAs(cpUser(super: true));
    $message = $this->getJson(cp_route('mt.reports.pages', $report))->json('data.0.issues.0.message');

    expect($message)->toBe('The page couldn’t be rendered (RuntimeException). The full error is in the site’s log.')
        ->and(json_encode(reportPage($report, '/contact')->only(['facts', 'results'])))->not->toContain('secret-db');
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains((string) $message, 'secret-db'));
});

test('only the newest reports are kept', function () {
    reportSettings(['keep_reports' => 2]);
    entryIn('pages', 'about');

    $ids = [fullReport()->id, fullReport()->id, fullReport()->id];

    expect(Report::query()->orderBy('id')->pluck('id')->all())->toBe(array_slice($ids, 1))
        ->and(ReportPage::query()->distinct()->pluck('report_id')->sort()->values()->all())->toBe(array_slice($ids, 1));
});

test('starting while a report runs returns that report; one that stopped moving is given up', function () {
    entryIn('pages', 'about');
    $runner = app(Runner::class);

    $running = $runner->start();
    expect($runner->start()->id)->toBe($running->id);

    $running->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    $next = $runner->start();

    expect($next->id)->not->toBe($running->id)
        ->and($running->fresh()->status)->toBe(Report::FAILED);
});

test('php please mt:report runs a whole report and prints the scores', function () {
    entryIn('pages', 'about');

    $this->artisan('statamic:mt:report')
        ->expectsOutputToContain('score')
        ->expectsOutputToContain('Unique title')
        ->assertSuccessful();

    expect(Report::query()->sole()->status)->toBe(Report::DONE);
});

test('without a queue worker the CP advances a report one step per progress request', function () {
    reportSettings(['chunk_size' => 1]);
    entryIn('pages', 'a');
    entryIn('pages', 'b');
    $this->actingAs(cpUser(['view marketing toolkit', 'run marketing toolkit reports']));

    $started = $this->postJson(cp_route('mt.reports.run'))->assertOk()->json();
    expect($started)->toMatchArray(['status' => 'running', 'pages_total' => 2, 'pages_done' => 0]);

    $this->postJson($started['progress_url'])->assertJson(['status' => 'running', 'pages_done' => 1]);
    $this->postJson($started['progress_url'])->assertJson(['status' => 'done', 'pages_done' => 2]);
});

test('with a queue worker the run is queued, and each step queues the next', function () {
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    Queue::fake();
    reportSettings(['chunk_size' => 1]);
    entryIn('pages', 'a');
    entryIn('pages', 'b');
    $this->actingAs(cpUser(['view marketing toolkit', 'run marketing toolkit reports']));

    $report = $this->postJson(cp_route('mt.reports.run'))->json();
    Queue::assertPushed(RunReportStep::class, fn ($job) => $job->reportId === $report['id']);

    // The progress request only reads when a worker does the work.
    $this->postJson($report['progress_url'])->assertJson(['pages_done' => 0]);

    (new RunReportStep($report['id']))->handle(app(Runner::class));
    Queue::assertPushed(RunReportStep::class, 2);
});

test('the reports screens and a report’s pages, filtered by a check', function () {
    entryIn('pages', 'about', ['title' => 'Same']);
    entryIn('pages', 'team', ['title' => 'Same']);
    entryIn('pages', 'unique-page', ['title' => 'A title of its very own here']);
    $report = fullReport();
    $this->actingAs(cpUser(super: true));

    $this->get(cp_route('mt.reports.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('marketing-toolkit::Reports', false)
        ->where('reports.0.id', $report->id)
        ->where('canRun', true));

    $this->get(cp_route('mt.reports.show', $report))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('marketing-toolkit::Report', false)
        ->where('report.score', $report->score)
        ->where('counts.scored', 3)
        ->where('rules', fn ($rules) => collect($rules)->pluck('label')->contains('Unique title')));

    $flagged = $this->getJson(cp_route('mt.reports.pages', [$report, 'rule' => 'title_unique']))->assertOk();
    expect($flagged->json('data.*.path'))->toEqualCanonicalizing(['/about', '/team'])
        ->and($flagged->json('data.0.issues.0'))->toMatchArray(['label' => 'Unique title', 'status' => 'fail'])
        ->and($flagged->json('data.0.issues.0.message'))->toBeIn(['This page has the same title as /about.', 'This page has the same title as /team.'])
        ->and($flagged->json('data.0.edit_url'))->toContain('/cp/collections/pages/entries/');

    $sorted = $this->getJson(cp_route('mt.reports.pages', [$report, 'sort' => 'score', 'order' => 'asc']))->json('data.*.score');
    expect($sorted)->toBe(collect($sorted)->sort()->values()->all());
});

test('viewing reports needs "view marketing toolkit"; running one needs "run marketing toolkit reports"', function () {
    entryIn('pages', 'about');
    $report = fullReport();

    $this->actingAs(cpUser(['view marketing toolkit']));
    $this->get(cp_route('mt.reports.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canRun', false));
    $this->get(cp_route('mt.reports.show', $report))->assertOk();
    $this->postJson(cp_route('mt.reports.run'))->assertForbidden();
});

test('without "run marketing toolkit reports" the progress request reads a running report without moving it on', function () {
    reportSettings(['chunk_size' => 1]);
    entryIn('pages', 'a');
    $report = app(Runner::class)->start();
    $this->actingAs(cpUser(['view marketing toolkit']));

    $this->postJson(cp_route('mt.reports.progress', $report))->assertOk()->assertJson(['status' => 'running', 'pages_done' => 0]);
    expect($report->fresh()->pages_done)->toBe(0);
});

test('the dashboard widget shows the latest finished report', function () {
    entryIn('pages', 'about');
    $report = fullReport();
    $this->actingAs(cpUser(['view marketing toolkit']));

    expect((new SeoWidget)->component()->toArray()['props']['report'])->toMatchArray([
        'score' => $report->score,
        'pages' => 1,
        'url' => cp_route('mt.reports.show', $report),
    ]);
});

test('reports run on the schedule set in the addon settings', function () {
    $events = function (array $settings) {
        reportSettings($settings);
        $schedule = new Schedule;
        (fn () => $this->scheduleJobs($schedule))->call(app()->getProvider(ServiceProvider::class));

        return collect($schedule->events())->filter(fn ($event) => str_contains((string) $event->command, 'mt:report'))->map(fn ($event) => $event->expression)->values()->all();
    };

    expect($events(['schedule' => 'off']))->toBe([])
        ->and($events(['schedule' => 'daily', 'schedule_time' => '04:30']))->toBe(['30 4 * * *'])
        ->and($events(['schedule' => 'weekly', 'schedule_day' => 'wednesday', 'schedule_time' => '03:00']))->toBe(['0 3 * * 3']);
});

test('the schedule is built only for the commands that need it', function (string $command, bool $built) {
    reportSettings(['schedule' => 'daily']);
    app()->instance(Schedule::class, $schedule = new Schedule);
    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', $command];

    try {
        (fn () => $this->bootSchedule())->call(app()->getProvider(ServiceProvider::class));
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(collect($schedule->events())->contains(fn ($event) => str_contains((string) $event->command, 'mt:report')))->toBe($built);
})->with([
    'schedule:run' => ['schedule:run', true],
    'schedule:finish, which finishes a background event' => ['schedule:finish', true],
    'schedule:list' => ['schedule:list', true],
    'a queue worker' => ['queue:work', false],
    'any other command' => ['migrate', false],
]);

test('the preview counters use the report thresholds', function () {
    reportSettings(['title_min' => 20, 'title_max' => 70, 'description_min' => 80, 'description_max' => 150]);

    expect((new SeoPreview)->preload()['limits'])->toBe(['title' => [20, 70], 'description' => [80, 150]]);
});

test('a Blade page that shows validation errors renders in a report run from the console or a queue', function () {
    entryIn('pages', 'contact', ['template' => 'form']);

    $facts = reportPage(fullReport(), '/contact')->facts();

    expect($facts->error)->toBeNull()->and($facts->status)->toBe(200)->and($facts->h1s)->toBe(['Contact']);
});

test('only one report starts at a time', function () {
    Sleep::fake(syncWithCarbon: true);
    $other = Cache::lock('mt:reports:start', 120);
    $other->get();

    // Another process is starting one: this start waits, then gives up rather than starting a second.
    expect(fn () => app(Runner::class)->start())->toThrow(LockTimeoutException::class)
        ->and(Report::query()->count())->toBe(0);

    $other->release();
    app(Runner::class)->start();
    expect(Report::query()->count())->toBe(1);
});

test('only one step of a report runs at a time, and a second click doesn\'t queue a second chain of steps', function () {
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    Queue::fake();
    reportSettings(['chunk_size' => 1]);
    entryIn('pages', 'a');
    entryIn('pages', 'b');
    $this->actingAs(cpUser(['view marketing toolkit', 'run marketing toolkit reports']));

    $report = $this->postJson(cp_route('mt.reports.run'))->json();
    $this->postJson(cp_route('mt.reports.run'))->assertJson(['id' => $report['id']]);
    Queue::assertPushed(RunReportStep::class, 1);

    // Another process is stepping it: this step leaves it be, and the queued one comes back later.
    $other = Cache::lock('mt:reports:step:'.$report['id'], 600);
    $other->get();
    expect(app(Runner::class)->step(Report::query()->find($report['id']))->pages_done)->toBe(0);

    (new RunReportStep($report['id']))->handle(app(Runner::class));
    Queue::assertPushed(RunReportStep::class, 2);
    Queue::assertPushed(RunReportStep::class, fn (RunReportStep $job) => $job->delay === 30);
    expect(Report::query()->find($report['id'])->pages_done)->toBe(0);

    $other->release();
    expect(app(Runner::class)->step(Report::query()->find($report['id']))->pages_done)->toBe(1);
});

test('running a report to the end waits while another process holds the step, rather than asking again at once', function () {
    entryIn('pages', 'about');
    $runner = app(Runner::class);
    $report = $runner->start();
    $other = Cache::lock('mt:reports:step:'.$report->id, 600);
    $other->get();
    Sleep::fake();
    $waits = 0;
    Sleep::whenFakingSleep(function () use (&$waits, $other) {
        if (++$waits === 3) {
            $other->release();
        }
    });

    expect($runner->runToEnd($report)->status)->toBe(Report::DONE);
    Sleep::assertSleptTimes(3);
});

test('a queued step that fails for good marks the report failed, with no error text', function () {
    entryIn('pages', 'about');
    $report = app(Runner::class)->start();

    (new RunReportStep($report->id))->failed(new RuntimeException('SQLSTATE[HY000] secret-db.internal'));

    expect($report->refresh()->only(['status', 'error']))->toBe(['status' => Report::FAILED, 'error' => 'marketing-toolkit::reports.messages.failed'])
        ->and($report->finished_at)->not->toBeNull();

    // A report that finished meanwhile is left as it is.
    $done = fullReport();
    (new RunReportStep($done->id))->failed(null);
    expect($done->refresh()->status)->toBe(Report::DONE);

    $this->actingAs(cpUser(super: true));
    expect($this->postJson(cp_route('mt.reports.progress', $report))->json('error'))
        ->toBe('The report stopped because of an error. The full error is in the site’s log.');
});

test('a report on a queue worker that stood still with no step running is queued again, once', function () {
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    Queue::fake();
    entryIn('pages', 'about');
    $runner = app(Runner::class);
    $report = $runner->start();

    // Still moving: left alone.
    expect($runner->resumeIfStalled($report))->toBeFalse();

    // A step is running (its lock is held): left alone.
    $report->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();
    $step = Cache::lock('mt:reports:step:'.$report->id, 600);
    $step->get();
    expect($runner->resumeIfStalled($report))->toBeFalse();
    $step->release();

    expect($runner->resumeIfStalled($report))->toBeTrue()
        ->and($runner->resumeIfStalled($report))->toBeFalse();
    Queue::assertPushed(RunReportStep::class, 1);

    (new RunReportStep($report->id))->handle($runner);
    expect($report->refresh()->status)->toBe(Report::DONE);
});

function stalledReportOnAWorker(): Report
{
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    Queue::fake();
    entryIn('pages', 'about');
    $report = app(Runner::class)->start();
    $report->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

    return $report;
}

test('watching a stalled report on a queue worker queues its step again', function () {
    $report = stalledReportOnAWorker();

    $this->actingAs(cpUser(['access cp', 'view marketing toolkit', 'run marketing toolkit reports']));
    $this->postJson(cp_route('mt.reports.progress', $report))->assertOk();
    Queue::assertPushed(RunReportStep::class, 1);
});

test('someone who may only view reports doesn\'t queue a stalled step', function () {
    $report = stalledReportOnAWorker();

    $this->actingAs(cpUser(['access cp', 'view marketing toolkit']));
    $this->postJson(cp_route('mt.reports.progress', $report))->assertOk();
    Queue::assertNotPushed(RunReportStep::class);
});

function reportOnAboutAndATerm(): array
{
    Taxonomy::make('topics')->save();
    $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();
    $entry = entryIn('pages', 'about');
    $report = Report::query()->create(['settings' => [], 'status' => Report::DONE, 'summary' => ['rules' => []]]);
    $report->pages()->create(['url' => 'https://example.test/about', 'content_type' => 'entry', 'content_id' => $entry->id(), 'score' => 50, 'checked' => true]);
    $report->pages()->create(['url' => 'https://example.test/topics/gardens', 'content_type' => 'term', 'content_id' => $term->id(), 'score' => 60, 'checked' => true]);

    return [$report, $entry, $term];
}

test('a report page links to the entry or term behind it', function () {
    [$report, $entry, $term] = reportOnAboutAndATerm();
    $this->actingAs(cpUser(super: true));

    expect($this->getJson(cp_route('mt.reports.pages', $report))->json('data.*.edit_url'))
        ->toBe([$entry->editUrl(), $term->in('default')->editUrl()]);
});

test('a report page has no edit link for someone who may not edit it', function () {
    [$report] = reportOnAboutAndATerm();
    $this->actingAs(cpUser(['view marketing toolkit']));

    expect($this->getJson(cp_route('mt.reports.pages', $report))->json('data.*.edit_url'))->toBe([null, null]);
});

test('a report checks links to other sites only when its settings ask', function () {
    Http::fake(['gone.test/*' => Http::response('', 404)]);
    fakeDns(['gone.test' => '93.184.215.14']);
    entryIn('pages', 'about', ['body' => '<a href="https://gone.test/a">Gone</a>']);

    expect(reportPage(fullReport(), '/about')->facts['brokenExternalLinks'])->toBe([]);
    Http::assertNothingSent();

    reportSettings(['rule_external_links' => true]);

    expect(reportPage(fullReport(), '/about')->facts['brokenExternalLinks'])->toBe(['https://gone.test/a']);
});

test('a title longer than its column is cut to fit, so the report still runs on MySQL and Postgres', function () {
    entryIn('pages', 'long', ['title' => str_repeat('Long ', 60)]);

    $page = reportPage(fullReport(), '/long');

    expect(mb_strlen($page->title))->toBe(255)
        ->and($page->facts['title'])->toStartWith('Long Long');
});

/**
 * A finished report with one page, its results and checks stored as given.
 *
 * @param  array<string, array<string, mixed>>  $rules
 * @param  array<string, array<string, mixed>>  $results
 */
function storedReport(array $rules, array $results): Report
{
    $report = Report::query()->create(['settings' => [], 'status' => Report::DONE, 'score' => 0, 'summary' => ['rules' => $rules, 'scored' => 1, 'noindex' => 0, 'errors' => 0]]);
    $report->pages()->create(['url' => 'https://example.test/about', 'content_type' => 'entry', 'content_id' => 'x', 'score' => 0, 'checked' => true,
        'results' => $results, 'failing' => ','.implode(',', array_map(fn ($handle) => $handle.':fail', array_keys($results))).',']);

    return $report;
}

test('a report from before messages were translated shows its text as it is', function () {
    $report = storedReport(
        ['title_length' => ['label' => 'Title length', 'weight' => 2, 'fail' => 1, 'warn' => 0], 'house_style' => ['label' => 'House style', 'weight' => 1, 'fail' => 1, 'warn' => 0]],
        ['title_length' => ['status' => 'fail', 'message' => 'Old English text.'], 'house_style' => ['status' => 'fail', 'message' => 'Says “colour”, not “color”: 50% of the time.']],
    );
    $this->actingAs(cpUser(super: true));

    $this->get(cp_route('mt.reports.show', $report))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('rules.0.label', 'Title length')
        ->where('rules.1.label', 'House style'));

    expect($this->getJson(cp_route('mt.reports.pages', $report))->json('data.0.issues'))->toEqualCanonicalizing([
        ['label' => 'Title length', 'status' => 'fail', 'message' => 'Old English text.'],
        ['label' => 'House style', 'status' => 'fail', 'message' => 'Says “colour”, not “color”: 50% of the time.'],
    ]);
});

test('a report reads in the language of whoever opens it', function () {
    app('translator')->addLines([
        'reports.rules.title_length' => 'Longueur du titre',
        'reports.messages.title_short' => ':count caractère ; visez :min–:max.|:count caractères ; visez :min–:max.',
        'reports.messages.title_same' => 'Même titre que :pages.',
        'reports.messages.and_more' => ':list et :count autre|:list et :count autres',
    ], 'fr', 'marketing-toolkit');
    $report = storedReport(
        ['title_length' => ['label' => 'marketing-toolkit::reports.rules.title_length', 'weight' => 2, 'fail' => 0, 'warn' => 1], 'title_unique' => ['label' => 'marketing-toolkit::reports.rules.title_unique', 'weight' => 2, 'fail' => 1, 'warn' => 0]],
        [
            'title_length' => ['status' => 'warn', 'message' => 'marketing-toolkit::reports.messages.title_short', 'params' => ['count' => 5, 'min' => 30, 'max' => 60]],
            'title_unique' => ['status' => 'fail', 'message' => 'marketing-toolkit::reports.messages.title_same', 'params' => ['pages' => ['message' => 'marketing-toolkit::reports.messages.and_more', 'params' => ['list' => '/a, /b, /c', 'count' => 2]]]],
        ],
    );
    $this->actingAs(cpUser(super: true));
    app()->setLocale('fr');

    $this->get(cp_route('mt.reports.show', $report))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('rules.0.label', 'Unique title')
        ->where('rules.1.label', 'Longueur du titre'));

    // An untranslated line falls back to English.
    expect($this->getJson(cp_route('mt.reports.pages', $report))->json('data.0.issues'))->toBe([
        ['label' => 'Unique title', 'status' => 'fail', 'message' => 'Même titre que /a, /b, /c et 2 autres.'],
        ['label' => 'Longueur du titre', 'status' => 'warn', 'message' => '5 caractères ; visez 30–60.'],
    ]);
});

test('php please mt:report prints a check’s name, not its key', function () {
    entryIn('pages', 'about');

    $this->artisan('statamic:mt:report')
        ->doesntExpectOutputToContain('marketing-toolkit::reports')
        ->assertSuccessful();
});

test('the overview shows the latest score with the checks most pages fail', function () {
    entryIn('pages', 'about', ['title' => 'Same']);
    entryIn('pages', 'team', ['title' => 'Same']);
    $report = fullReport();
    $this->actingAs(cpUser(super: true));

    $this->get(cp_route('mt.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('report.latest.score', $report->score)
        ->where('report.latest.checks', fn ($checks) => collect($checks)->pluck('label')->contains('Unique title') && collect($checks)->every(fn ($check) => $check['fail'] > 0)));
});

test('turned off under Features, reports run only by hand', function () {
    reportSettings(['schedule' => 'daily']);
    config(['marketing-toolkit.reports.enabled' => false]);
    $schedule = new Schedule;
    (fn () => $this->scheduleJobs($schedule))->call(app()->getProvider(ServiceProvider::class));

    expect(collect($schedule->events())->filter(fn ($event) => str_contains((string) $event->command, 'mt:report'))->all())->toBe([]);
});
