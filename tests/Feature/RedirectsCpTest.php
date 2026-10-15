<?php

use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use JothamLec\MarketingToolkit\Actions\CreateRedirect;
use JothamLec\MarketingToolkit\Actions\DeleteRecords;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use Statamic\Actions\Action;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

// These run on both drivers: composer test runs the group again on files (MT_TEST_REDIRECTS=file).
pest()->group('redirects');

test('the redirects screen and its listing: search, sort, pagination, columns', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    redirectRule(['source' => '/b-old', 'target' => '/b-new']);
    redirectRule(['source' => '/a-old', 'target' => '/a-new', 'automatic' => true]);
    redirectRule(['source' => '/gone', 'status' => 410]);

    $this->get(cp_route('mt.redirects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing-toolkit::Redirects', false)->where('listingUrl', cp_route('mt.redirects.listing')));

    $listing = $this->getJson(cp_route('mt.redirects.listing', ['sort' => 'source', 'order' => 'asc', 'perPage' => 10]))->assertOk();

    expect($listing->json('data.*.source'))->toBe(['/a-old', '/b-old', '/gone'])
        ->and($listing->json('data.0'))->toMatchArray(['target' => '/a-new', 'status' => 301, 'active' => true, 'automatic' => true, 'hits' => 0])
        ->and($listing->json('meta'))->toMatchArray(['current_page' => 1, 'last_page' => 1, 'total' => 3])
        ->and($listing->json('meta.columns.*.field'))->toBe(['source', 'target', 'status', 'active', 'hits', 'last_hit_at']);

    expect($this->getJson(cp_route('mt.redirects.listing', ['search' => 'b-n']))->json('data.*.source'))->toBe(['/b-old'])
        // In any letter case, on Postgres too.
        ->and($this->getJson(cp_route('mt.redirects.listing', ['search' => 'B-OLD']))->json('data.*.source'))->toBe(['/b-old']);
    expect($this->getJson(cp_route('mt.redirects.listing', ['sort' => 'nope; drop table']))->json('data.*.source'))->toBe(['/a-old', '/b-old', '/gone']);
});

test('creating a redirect through the publish form, and the checks it must pass', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    $this->get(cp_route('mt.redirects.create', ['source' => '/from-a-404']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('marketing-toolkit::RedirectForm', false)
            ->where('values.source', '/from-a-404')
            ->where('values.status', '301')
            ->where('submitMethod', 'post'));

    $response = $this->postJson(cp_route('mt.redirects.store'), ['source' => '/blog/*', 'target' => '/essays/$1', 'status' => '302', 'active' => true])->assertOk();
    $redirect = redirectRules()->sole();

    expect($response->json('redirect'))->toBe(cp_route('mt.redirects.edit', $redirect->id))
        ->and(ruleFields($redirect, ['source', 'target', 'status', 'active', 'automatic']))->toBe(['source' => '/blog/*', 'target' => '/essays/$1', 'status' => 302, 'active' => true, 'automatic' => false]);

    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/blog/*/', 'target' => '/x', 'status' => '301'])->assertJsonValidationErrors(['source' => 'already starts']);
    $this->postJson(cp_route('mt.redirects.store'), ['source' => 'no-slash', 'target' => '/x', 'status' => '301'])->assertJsonValidationErrors('source');
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/q?x=1', 'target' => '/x', 'status' => '301'])->assertJsonValidationErrors('source');
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/a', 'target' => '', 'status' => '301'])->assertJsonValidationErrors('target');
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/a', 'target' => 'ftp://x', 'status' => '301'])->assertJsonValidationErrors('target');
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/a', 'target' => '/x/$2', 'status' => '301'])->assertJsonValidationErrors(['target' => 'no matching *']);
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/a', 'target' => '/x', 'status' => '307'])->assertJsonValidationErrors('status');
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/gone', 'target' => '', 'status' => '410'])->assertOk();
});

test('editing a redirect, which also makes an automatic one manual', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    $redirect = redirectRule(['source' => '/old', 'target' => '/new', 'automatic' => true]);

    $this->get(cp_route('mt.redirects.edit', $redirect->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('values.target', '/new')->where('submitMethod', 'patch')->where('stats.automatic', true));

    $this->patchJson(cp_route('mt.redirects.update', $redirect->id), ['source' => '/old', 'target' => '/newer', 'status' => '301', 'active' => false])->assertOk();

    expect(ruleFields(freshRule($redirect), ['target', 'active', 'automatic']))->toBe(['target' => '/newer', 'active' => false, 'automatic' => false]);
});

test('CSV export, and import that adds, updates and reports bad rows', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    redirectRule(['source' => '/one', 'target' => '/1']);
    redirectRule(['source' => '/gone', 'status' => 410, 'active' => false]);

    $export = $this->get(cp_route('mt.redirects.export'))->assertOk()->streamedContent();
    expect($export)->toBe("source,target,status,active\n/gone,,410,0\n/one,/1,301,1\n");

    $csv = "source,target,status,active\n/one,/uno,301,1\n/two,/2,302,\nbroken,/x,301,1\n/three,https://elsewhere.test/3,,yes\n";
    $result = $this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk()->json();

    expect($result)->toMatchArray(['created' => 2, 'updated' => 1])
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toStartWith('Row 4:')
        ->and(redirectRules()->sortBy('source')->map(fn ($rule) => ruleFields($rule, ['source', 'target', 'status']))->values()->all())->toBe([
            ['source' => '/gone', 'target' => null, 'status' => 410],
            ['source' => '/one', 'target' => '/uno', 'status' => 301],
            ['source' => '/three', 'target' => 'https://elsewhere.test/3', 'status' => 301],
            ['source' => '/two', 'target' => '/2', 'status' => 302],
        ]);
});

test('an import refuses a row that loops back through an earlier row of the same file, or through a wildcard', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    redirectRule(['source' => '/shop/*', 'target' => '/store/$1']);

    $csv = "/a,/b\n/b,/a\n/store/x,/shop/x\n";
    $result = $this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk()->json();

    expect($result['created'])->toBe(1)
        ->and($result['errors'])->toBe([
            'Row 2: The redirect from that address leads back here, so the two would loop.',
            'Row 3: The redirect from that address leads back here, so the two would loop.',
        ]);
});

test('a CSV row and the form refuse a loop with the same message', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    redirectRule(['source' => '/a', 'target' => '/b']);

    $form = $this->postJson(cp_route('mt.redirects.store'), ['source' => '/b', 'target' => '/a', 'status' => '301', 'active' => true])
        ->assertUnprocessable()
        ->json('errors.target.0');
    $csv = $this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', "/b,/a\n")])->json('errors.0');

    expect($form)->toBe(__('marketing-toolkit::validation.redirect.loop'))
        ->and($csv)->toBe('Row 1: '.$form);
});

test('the checks, the import report and the listing speak the editor\'s language', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    redirectRule(['source' => '/a', 'target' => '/b']);
    app('translator')->addLines([
        'cp.redirects.title' => 'XX Redirects',
        'cp.listing.from' => 'XX From',
        'validation.redirect.source_required' => 'XX Which address?',
        'validation.redirect.loop' => 'XX Loop.',
        'validation.csv_row' => 'XX row :row: :message',
    ], 'xx', 'marketing-toolkit');
    app()->setLocale('xx');

    $this->postJson(cp_route('mt.redirects.store'), ['source' => '', 'target' => '/x', 'status' => '301'])
        ->assertJsonValidationErrors(['source' => 'XX Which address?']);

    $csv = "/b,/a\n";
    expect($this->post(cp_route('mt.redirects.import'), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->json('errors'))
        ->toBe(['XX row 1: XX Loop.'])
        ->and($this->getJson(cp_route('mt.redirects.listing'))->json('meta.columns.0.label'))->toBe('XX From');
});

/**
 * Runway's Publish and Unpublish call `runwayResource()` on any Eloquent model
 * they are asked about, and Statamic's `Action::for()` asks every registered
 * action. With Runway installed, both listings answered 500.
 */
test('another addon\'s action that cannot handle our rows does not break the listings', function () {
    $throwsOnForeignModels = new class extends Action
    {
        public static function handle()
        {
            return 'throws_on_foreign_models';
        }

        public function visibleTo($item)
        {
            throw new BadMethodCallException('Call to undefined method runwayResource()');
        }
    };
    app()->instance($throwsOnForeignModels::class, $throwsOnForeignModels);
    app('statamic.actions')->put($throwsOnForeignModels::handle(), $throwsOnForeignModels::class);

    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects']));
    $redirect = redirectRule(['source' => '/old', 'target' => '/new']);
    $row = MissingPath::query()->create(['path' => '/miss', 'hits' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    expect($this->getJson(cp_route('mt.redirects.listing'))->assertOk()->json('data.0.actions.*.handle'))->toBe([DeleteRecords::handle()])
        ->and($this->getJson(cp_route('mt.404s.listing'))->assertOk()->json('data.0.actions.*.handle'))->toBe([DeleteRecords::handle(), CreateRedirect::handle()]);

    $this->postJson(cp_route('mt.actions.bulk'), ['selections' => [$redirect->id], 'context' => ['type' => 'redirects']])->assertOk();
    $this->postJson(cp_route('mt.actions.bulk'), ['selections' => [$row->id], 'context' => ['type' => '404s']])->assertOk();
});

test('the action endpoint runs only the addon\'s own actions', function () {
    $permissive = new class extends Action
    {
        public static function handle()
        {
            return 'permissive_foreign_action';
        }

        public function run($items, $values)
        {
            $items->each->delete();
        }
    };
    app()->instance($permissive::class, $permissive);
    app('statamic.actions')->put($permissive::handle(), $permissive::class);

    $this->actingAs(cpUser(['view marketing toolkit']));
    $row = MissingPath::query()->create(['path' => '/miss', 'hits' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->postJson(cp_route('mt.actions.run'), ['action' => $permissive::handle(), 'selections' => [$row->id], 'context' => ['type' => '404s'], 'values' => []])
        ->assertForbidden();

    expect(MissingPath::query()->count())->toBe(1);
});

test('another addon\'s action registered under one of the addon\'s handles doesn\'t run on its rows', function () {
    $impostor = new class extends Action
    {
        public static function handle()
        {
            return CreateRedirect::handle();
        }

        public function run($items, $values)
        {
            $items->each->delete();
        }
    };
    app()->instance($impostor::class, $impostor);
    // The last registration wins in Statamic's map of handles to classes.
    app('statamic.actions')->put($impostor::handle(), $impostor::class);

    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects']));
    $row = MissingPath::query()->create(['path' => '/miss', 'hits' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->postJson(cp_route('mt.actions.run'), ['action' => CreateRedirect::handle(), 'selections' => [$row->id], 'context' => ['type' => '404s'], 'values' => []])
        ->assertForbidden();

    expect(MissingPath::query()->count())->toBe(1);
});

test('the 404 log listing, newest first, with a "Create redirect" action per row', function () {
    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects']));
    $old = MissingPath::query()->create(['path' => '/old-miss', 'hits' => 9, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
    MissingPath::query()->create(['path' => '/new-miss', 'hits' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->get(cp_route('mt.404s.index'))->assertInertia(fn (AssertableInertia $page) => $page->component('marketing-toolkit::NotFound', false));
    $listing = $this->getJson(cp_route('mt.404s.listing'));
    expect($listing->json('data.*.path'))->toBe(['/new-miss', '/old-miss'])
        ->and($listing->json('data.0.actions.*.handle'))->toContain(CreateRedirect::handle(), DeleteRecords::handle());

    $actions = $this->postJson(cp_route('mt.actions.bulk'), ['selections' => [$old->id], 'context' => ['type' => '404s']])->assertOk()->json('*.handle');
    expect($actions)->toContain(CreateRedirect::handle(), DeleteRecords::handle());

    $this->postJson(cp_route('mt.actions.run'), ['action' => CreateRedirect::handle(), 'selections' => [$old->id], 'context' => ['type' => '404s'], 'values' => []])
        ->assertOk()
        ->assertJson(['redirect' => cp_route('mt.redirects.create', ['source' => '/old-miss'])]);
});

test('deleting redirects and 404 rows through the listing action', function () {
    $this->actingAs(cpUser(['view marketing toolkit', 'manage marketing toolkit redirects']));
    $redirect = redirectRule(['source' => '/old', 'target' => '/new']);
    $row = MissingPath::query()->create(['path' => '/x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->get('/old')->assertRedirect('https://example.test/new');

    $this->postJson(cp_route('mt.actions.run'), ['action' => DeleteRecords::handle(), 'selections' => [$redirect->id], 'context' => ['type' => 'redirects'], 'values' => []])->assertOk();
    $this->postJson(cp_route('mt.actions.run'), ['action' => DeleteRecords::handle(), 'selections' => [$row->id], 'context' => ['type' => '404s'], 'values' => []])->assertOk();

    expect(redirectRules()->count())->toBe(0)->and(MissingPath::query()->count())->toBe(0);
    $this->get('/old')->assertNotFound();
});

test('redirects need "manage marketing toolkit redirects", the 404 log "view marketing toolkit"', function () {
    $redirect = redirectRule(['source' => '/old', 'target' => '/new']);

    $this->actingAs(cpUser(['view marketing toolkit']));

    // A screen sends them back with an error, as Statamic's own screens do.
    $this->get(cp_route('mt.redirects.index'))->assertRedirect(cp_route('index'))->assertSessionHas('error');
    $this->getJson(cp_route('mt.redirects.listing'))->assertForbidden();
    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/a', 'target' => '/b', 'status' => '301'])->assertForbidden();
    $this->getJson(cp_route('mt.redirects.export'))->assertForbidden();
    $this->postJson(cp_route('mt.actions.run'), ['action' => DeleteRecords::handle(), 'selections' => [$redirect->id], 'context' => ['type' => 'redirects'], 'values' => []])->assertForbidden();
    $this->get(cp_route('mt.404s.index'))->assertOk();
});

test('the save dialog\'s check: does saving the form move the entry?', function () {
    Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'about');

    $this->postJson(cp_route('mt.redirects.check'), ['reference' => $entry->reference(), 'values' => ['title' => 'About', 'slug' => 'about-us']])
        ->assertExactJson(['changes' => true, 'from' => '/about', 'to' => '/about-us']);

    $this->postJson(cp_route('mt.redirects.check'), ['reference' => $entry->reference(), 'values' => ['title' => 'New title', 'slug' => 'about']])
        ->assertExactJson(['changes' => false]);

    $this->postJson(cp_route('mt.redirects.check'), ['reference' => null, 'values' => ['slug' => 'new']])
        ->assertExactJson(['changes' => false]);

    // Unpublishing: a draft has no address to protect, so the save adds no redirect and nothing is asked.
    $this->postJson(cp_route('mt.redirects.check'), ['reference' => $entry->reference(), 'values' => ['title' => 'About', 'slug' => 'about-us', 'published' => false]])
        ->assertExactJson(['changes' => false]);

    expect(Entry::find($entry->id())->slug())->toBe('about');
});

test('the dialog only asks people who manage redirects; others get the redirect regardless', function () {
    Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
    $entry = entryIn('pages', 'about');
    $this->actingAs(cpUser(['access cp', 'view pages entries', 'edit pages entries']));

    $this->postJson(cp_route('mt.redirects.check'), ['reference' => $entry->reference(), 'values' => ['slug' => 'about-us']])
        ->assertExactJson(['changes' => false]);
    $this->postJson(cp_route('mt.redirects.choice'), ['reference' => $entry->reference(), 'create' => false])->assertForbidden();
});

test('the dialog\'s answer is kept for the next save of that entry', function () {
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'about');

    $this->postJson(cp_route('mt.redirects.choice'), ['reference' => $entry->reference(), 'create' => false])->assertOk();

    Entry::find($entry->id())->syncOriginal()->slug('about-us')->save();

    expect(redirectRules()->count())->toBe(0);
});

test('the dialog works for a term too: the check, and the answer read by the term\'s save', function () {
    Taxonomy::make('topics')->termTemplate('default')->save();
    Blueprint::make('topic')->setNamespace('taxonomies.topics')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
    $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();
    $reference = $term->in('default')->reference();
    $this->actingAs(cpUser(super: true));

    $this->postJson(cp_route('mt.redirects.check'), ['reference' => $reference, 'values' => ['title' => 'Gardens', 'slug' => 'gardening']])
        ->assertExactJson(['changes' => true, 'from' => '/topics/gardens', 'to' => '/topics/gardening']);

    $this->postJson(cp_route('mt.redirects.choice'), ['reference' => $reference, 'create' => false])->assertOk();
    Term::find('topics::gardens')->term()->syncOriginal()->slug('gardening')->save();

    expect(redirectRules()->count())->toBe(0);
});

test('the dialog does not ask about a term without a page of its own', function () {
    Taxonomy::make('topics')->save();
    Blueprint::make('topic')->setNamespace('taxonomies.topics')->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
    $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();
    $this->actingAs(cpUser(super: true));

    $this->postJson(cp_route('mt.redirects.check'), ['reference' => $term->in('default')->reference(), 'values' => ['title' => 'Gardens', 'slug' => 'gardening']])
        ->assertExactJson(['changes' => false]);
});

test('a campaign link: UTM tags join the target\'s own query, show again when edited, and travel with the visitor', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));

    $this->postJson(cp_route('mt.redirects.store'), [
        'source' => '/go/linkedin', 'target' => '/offer?ref=1#form', 'status' => '302', 'active' => true,
        'utm_source' => 'linkedin', 'utm_medium' => 'paid social', 'utm_campaign' => 'autumn',
    ])->assertOk();

    $redirect = redirectRules()->sole();
    expect($redirect->target)->toBe('/offer?ref=1&utm_source=linkedin&utm_medium=paid%20social&utm_campaign=autumn#form');

    $this->get(cp_route('mt.redirects.edit', $redirect->id))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('values.utm_source', 'linkedin')
        ->where('values.utm_medium', 'paid social')
        ->where('blueprint.tabs.0.sections.1.display', 'Campaign link'));

    // Emptied in the form: taken out of the target.
    $this->patchJson(cp_route('mt.redirects.update', $redirect->id), ['source' => '/go/linkedin', 'target' => $redirect->target, 'status' => '302', 'active' => true, 'utm_source' => 'linkedin', 'utm_medium' => '', 'utm_campaign' => 'autumn'])->assertOk();
    expect(freshRule($redirect)->target)->toBe('/offer?ref=1&utm_source=linkedin&utm_campaign=autumn#form');

    $this->get('https://example.test/go/linkedin')->assertStatus(302)->assertRedirect('https://example.test/offer?ref=1&utm_source=linkedin&utm_campaign=autumn#form');
    expect(freshRule($redirect)->hits)->toBe(1);
});

test('saving a redirect keeps the rest of the target\'s query as typed, and only swaps its UTM tags', function () {
    $this->actingAs(cpUser(['manage marketing toolkit redirects']));
    $target = '/search/$1?q=$1&a.b=1&x=1&x=2&flag&c=d+e#top';

    $this->postJson(cp_route('mt.redirects.store'), ['source' => '/find/*', 'target' => $target, 'status' => '301', 'active' => true])->assertOk();
    $redirect = redirectRules()->sole();
    expect($redirect->target)->toBe($target);

    $this->get('https://example.test/find/shoes')->assertRedirect('https://example.test/search/shoes?q=shoes&a.b=1&x=1&x=2&flag&c=d+e#top');

    // A tag the target already has, sent back unchanged with the form: nothing moves.
    $tagged = '/offer?q=$1&utm_campaign=autumn&a.b=1&x=1&x=2&flag&c=d+e#top';
    $this->patchJson(cp_route('mt.redirects.update', $redirect->id), ['source' => '/find/*', 'target' => $tagged, 'status' => '301', 'active' => true, 'utm_campaign' => 'autumn'])->assertOk();
    expect(freshRule($redirect)->target)->toBe($tagged);

    // A changed one is replaced; everything else stays byte for byte.
    $this->patchJson(cp_route('mt.redirects.update', $redirect->id), ['source' => '/find/*', 'target' => $tagged, 'status' => '301', 'active' => true, 'utm_campaign' => 'winter sale'])->assertOk();
    expect(freshRule($redirect)->target)->toBe('/offer?q=$1&a.b=1&x=1&x=2&flag&c=d+e&utm_campaign=winter%20sale#top');
});

test('the control panel’s script gets the addresses a save asks about its redirect', function () {
    expect(Statamic\Statamic::jsonVariables(request())['marketingToolkit']['urls'])
        ->toBe(['redirectCheck' => cp_route('mt.redirects.check'), 'redirectChoice' => cp_route('mt.redirects.choice')]);
});
