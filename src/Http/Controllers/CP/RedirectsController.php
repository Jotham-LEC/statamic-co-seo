<?php

namespace JothamLec\MarketingToolkit\Http\Controllers\CP;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use JothamLec\MarketingToolkit\Cp\Listing;
use JothamLec\MarketingToolkit\Cp\RecordActions;
use JothamLec\MarketingToolkit\Http\Requests\SaveRedirect;
use JothamLec\MarketingToolkit\Preview\Draft;
use JothamLec\MarketingToolkit\Redirects\AutoRedirects;
use JothamLec\MarketingToolkit\Redirects\Campaign;
use JothamLec\MarketingToolkit\Redirects\Csv;
use JothamLec\MarketingToolkit\Redirects\Drivers;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Permissions;
use JothamLec\MarketingToolkit\Support\Sites;
use JothamLec\MarketingToolkit\Support\Uris;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shows Marketing → Redirects. It serves the list, the create and edit forms
 * (Statamic's publish form, on a blueprint defined here), CSV import and export,
 * and the two endpoints behind the "add a redirect?" question when content is saved.
 */
final class RedirectsController
{
    public function index(): Response
    {
        return Inertia::render('marketing-toolkit::Redirects', [
            'listingUrl' => cp_route('mt.redirects.listing'),
            'actionUrl' => cp_route('mt.actions.run'),
            'createUrl' => cp_route('mt.redirects.create'),
            'exportUrl' => cp_route('mt.redirects.export'),
            'importUrl' => cp_route('mt.redirects.import'),
            'notices' => $this->notices(),
        ]);
    }

    /**
     * Says what an editor should know about where the rules are kept: rules the safety net still reads from
     * the database, and files that can't be read or need a look.
     *
     * @return list<array{level: string, message: string}>
     */
    private function notices(): array
    {
        $inDatabase = app(Drivers::class)->stillInDatabase();

        return [
            ...($inDatabase > 0 ? [['level' => 'warning', 'message' => trans_choice('marketing-toolkit::cp.redirects.in_database', $inDatabase, ['count' => $inDatabase])]] : []),
            ...app(RedirectRepository::class)->problems(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(Request $request): array
    {
        $sites = Sites::options();
        // The actions are the same on every row, because they depend only on the kind of row and the user.
        $actions = RecordActions::for(collect([new Rule]), ['type' => 'redirects']);

        $columns = [
            'source' => __('marketing-toolkit::cp.listing.from'), 'target' => __('marketing-toolkit::cp.listing.to'),
            ...(Sites::multiple() ? ['site' => __('marketing-toolkit::cp.listing.site')] : []),
            'status' => __('marketing-toolkit::cp.listing.status'), 'active' => __('marketing-toolkit::cp.listing.active'),
            'hits' => __('marketing-toolkit::cp.listing.hits'), 'last_hit_at' => __('marketing-toolkit::cp.listing.last_used'),
        ];
        [$sort, $order, $perPage] = Listing::sorting($request, $columns);
        // These are the rules the user may manage (see Rule::isAccessible()).
        $accessible = Sites::multiple() && ! Sites::accessesAll() ? Sites::accessible() : null;
        $page = app(RedirectRepository::class)->listing(trim((string) $request->input('search')), $sort, $order, $perPage, $accessible);

        return Listing::page($page, $columns, fn (Collection $redirects) => $redirects->map(fn (Rule $redirect) => [
            'id' => $redirect->id,
            'site' => $redirect->site === null ? __('marketing-toolkit::cp.listing.all_sites') : ($sites[$redirect->site] ?? $redirect->site),
            'source' => $redirect->source,
            'target' => $redirect->target,
            'status' => $redirect->status,
            'active' => $redirect->active,
            'automatic' => $redirect->automatic,
            'hits' => $redirect->hits,
            'last_hit_at' => $redirect->lastHitAt?->toIso8601String(),
            // A rule typed into a file without an id can't be opened until the file is saved again.
            'edit_url' => $redirect->id === null ? null : cp_route('mt.redirects.edit', $redirect->id),
            'actions' => $redirect->id === null ? [] : $actions,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->form(
            Rule::make([
                'source' => (string) $request->query('source', ''),
                // Without a site, the redirect is for every site. For someone who can't work on every site,
                // it is for the selected one if theirs.
                'site' => Sites::scope($request->query('site') === null ? self::defaultSite() : (string) $request->query('site')),
            ]),
            title: __('marketing-toolkit::cp.redirects.create'),
            submitUrl: cp_route('mt.redirects.store'),
            method: 'post',
        );
    }

    public function store(SaveRedirect $request): JsonResponse
    {
        $redirect = app(RedirectRepository::class)->save(Rule::make($request->validated()));

        return response()->json(['redirect' => cp_route('mt.redirects.edit', $redirect->id)]);
    }

    public function edit(string $redirect): Response
    {
        $rule = self::accessible($redirect);

        return $this->form($rule, title: $rule->source, submitUrl: cp_route('mt.redirects.update', $rule->id), method: 'patch');
    }

    public function update(SaveRedirect $request, string $redirect): JsonResponse
    {
        $rule = self::accessible($redirect);

        // A redirect that is edited by hand is no longer one that the content made.
        app(RedirectRepository::class)->save($rule->fill([...$request->validated(), 'automatic' => false]));

        return response()->json(['saved' => true]);
    }

    /**
     * Finds a rule by its id, if the user may manage it.
     */
    private static function accessible(string $id): Rule
    {
        $rule = app(RedirectRepository::class)->find($id);

        abort_unless($rule !== null && $rule->isAccessible(), 404);

        return $rule;
    }

    public function export(Csv $csv): StreamedResponse
    {
        return response()->streamDownload(function () use ($csv) {
            $out = fopen('php://output', 'w');
            $csv->export($out);
            fclose($out);
        }, 'redirects-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function import(Request $request, Csv $csv): array
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        return $csv->import((string) file_get_contents($request->file('file')->getRealPath()));
    }

    /**
     * Tells the form, before an entry or term is saved, whether its address will change.
     *
     * @return array{changes: bool, from?: string, to?: string}
     */
    public function check(Request $request): array
    {
        if (! Features::on('automatic_redirects') || ! User::current()?->can(Permissions::REDIRECTS)) {
            return ['changes' => false];
        }

        $stored = $this->referenced((string) $request->input('reference'));

        // Only content that is published before and after the save gets a redirect (RedirectChangedUris).
        if (! $stored || ($stored instanceof EntryContract && (! $stored->published() || ! $request->boolean('values.published', true)))) {
            return ['changes' => false];
        }

        // A term without a page (no template) gets no redirect either (RedirectChangedUris).
        if ($stored instanceof TermContract && ! Uris::termHasPage($stored)) {
            return ['changes' => false];
        }

        Uris::forget();
        $from = $stored->uri();

        $draft = Draft::from([
            'blueprint' => $stored->blueprint()->fullyQualifiedHandle(),
            'reference' => $request->input('reference'),
            'site' => $stored->locale(),
            'values' => (array) $request->input('values', []),
        ]);

        Uris::forget();
        $to = $draft->uri();

        return $from && $to && Rule::normalize($from) !== Rule::normalize($to)
            ? ['changes' => true, 'from' => $from, 'to' => $to]
            : ['changes' => false];
    }

    /**
     * Stores the editor's answer, which the save that follows reads.
     */
    public function choice(Request $request, AutoRedirects $redirects): JsonResponse
    {
        $request->validate(['reference' => ['required', 'string'], 'create' => ['required', 'boolean']]);

        $content = $this->referenced($request->input('reference'));
        abort_unless($content !== null, 404);

        $redirects->remember((string) $content->id(), $request->boolean('create'));

        return response()->json(['saved' => true]);
    }

    /**
     * Returns the saved entry or term that a publish form's reference points at, if this user may see it.
     */
    private function referenced(string $reference): EntryContract|TermContract|null
    {
        $content = Draft::stored($reference);

        abort_if($content && User::current()?->cant('view', $content), 403);

        return $content;
    }

    /**
     * Returns the site a new rule is for when the form names none. This is every
     * site (null), unless the user may not work on every site.
     */
    private static function defaultSite(): ?string
    {
        if (Sites::accessesAll()) {
            return null;
        }

        $selected = Site::selected()->handle();

        return in_array($selected, Sites::accessible(), true) ? $selected : (Sites::accessible()[0] ?? null);
    }

    private function form(Rule $redirect, string $title, string $submitUrl, string $method): Response
    {
        $fields = SaveRedirect::blueprint()->fields()->addValues([
            ...Campaign::tags((string) $redirect->target),
            'source' => $redirect->source,
            'target' => $redirect->target,
            'status' => (string) $redirect->status,
            'active' => $redirect->active,
            'site' => $redirect->site,
        ])->preProcess();

        return Inertia::render('marketing-toolkit::RedirectForm', [
            'title' => $title,
            'blueprint' => SaveRedirect::blueprint()->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'submitUrl' => $submitUrl,
            'submitMethod' => $method,
            'listingUrl' => cp_route('mt.redirects.index'),
            'stats' => $redirect->id !== null ? ['hits' => $redirect->hits, 'last_hit_at' => $redirect->lastHitAt?->toIso8601String(), 'automatic' => $redirect->automatic] : null,
        ]);
    }
}
