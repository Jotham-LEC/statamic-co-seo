<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use JothamLec\MarketingToolkit\Fieldtypes\SeoPreview;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Field;

beforeEach(function () {
    seoGlobal([]);

    foreach (['collections.pages' => 'page', 'taxonomies.topics' => 'topic'] as $namespace => $handle) {
        Blueprint::make($handle)->setNamespace($namespace)->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'description', 'field' => ['type' => 'textarea']],
            ['import' => 'marketing-toolkit::seo'],
        ]]]]]])->save();
    }

    Taxonomy::make('topics')->save();
});

/**
 * @param  array<string, mixed>  $values
 */
function previewOf(string $blueprint, array $values, ?string $reference = null, string $route = 'mt.preview.meta'): TestResponse
{
    return test()->postJson(cp_route($route), [
        'blueprint' => $blueprint,
        'reference' => $reference,
        'site' => 'default',
        'values' => $values,
    ]);
}

test('the fieldtype is in the SEO fieldset, hands the form its routes and limits, and stores nothing', function () {
    $fieldtype = new SeoPreview;
    $fields = Blueprint::find('collections.pages.page')->fields();

    expect($fields->has('seo_preview'))->toBeTrue()
        ->and($fieldtype->preload())->toMatchArray([
            'urls' => ['meta' => cp_route('mt.preview.meta'), 'card' => cp_route('mt.preview.card')],
            'limits' => ['title' => [30, 60], 'description' => [50, 160]],
            'og' => true,
        ])
        ->and($fields->addValues(['title' => 'About', 'seo_preview' => 'anything'])->process()->values()['seo_preview'])->toBeNull();
});

test('an entry being edited is previewed from the form, not from what was saved', function () {
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'about', ['description' => 'Saved description.']);

    previewOf('collections.pages.page', ['title' => 'About us', 'description' => 'Who we are, typed just now.', 'slug' => 'about'], $entry->reference())
        ->assertOk()
        ->assertJson([
            'title' => 'About us',
            'og_title' => 'About us',
            'description' => 'Who we are, typed just now.',
            'url' => 'https://example.test/about',
            'site_name' => 'Acme',
            'image' => ['generated' => true],
        ]);

    expect($entry->fresh()->get('title'))->toBe('About');
});

test('the preview follows the same rules as the page: a typed SEO title as is, a long title without the site name', function () {
    $this->actingAs(cpUser(super: true));

    previewOf('collections.pages.page', ['title' => 'About', 'seo' => ['title' => 'Exactly this <title>']])
        ->assertJson(['title' => 'Exactly this <title>', 'og_title' => 'Exactly this <title>']);

    $long = 'A title long enough that adding the site name would push it past sixty';

    previewOf('collections.pages.page', ['title' => $long])->assertJson(['title' => $long]);
});

test('a new entry is previewed at the address it will have', function () {
    $this->actingAs(cpUser(super: true));

    previewOf('collections.pages.page', ['title' => 'Brand new', 'slug' => 'brand-new'])
        ->assertOk()
        ->assertJson(['title' => 'Brand new', 'url' => 'https://example.test/brand-new', 'image' => ['generated' => true]]);
});

test('a noindexed entry says so, and an uploaded image replaces the card', function () {
    $this->actingAs(cpUser(super: true));
    AssetContainer::find('assets')->disk()->put('share.png', file_get_contents(__DIR__.'/../fixtures/share.png'));

    $response = previewOf('collections.pages.page', ['title' => 'Hidden', 'slug' => 'hidden', 'seo' => ['noindex' => true, 'image' => ['assets::share.png']]]);

    expect($response->json('robots'))->toStartWith('noindex')
        ->and($response->json('image.generated'))->toBeFalse()
        ->and($response->json('image.url'))->toStartWith('https://example.test/img/');
});

test('a term is previewed too', function () {
    $this->actingAs(cpUser(super: true));
    $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();

    $saved = previewOf('taxonomies.topics.topic', ['title' => 'Gardens and parks', 'slug' => 'gardens'], $term->in('default')->reference())
        ->assertOk()
        ->assertJson(['title' => 'Gardens and parks', 'url' => 'https://example.test/topics/gardens']);
    // A saved term has its generated card, as an entry does. (Without Imagick, tests/Pest.php
    // counts cards as drawable, so this holds there too.)
    expect($saved->json('image.url'))->toStartWith('https://example.test/og/topics/gardens.png?v=');

    // A term being created has no card yet.
    previewOf('taxonomies.topics.topic', ['title' => 'Ponds', 'slug' => 'ponds'])
        ->assertOk()
        ->assertJson(['title' => 'Ponds', 'url' => 'https://example.test/topics/ponds', 'image' => null]);
});

test('the card is drawn from the form as a PNG and never cached', function () {
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'about');

    $response = previewOf('collections.pages.page', ['title' => 'About', 'slug' => 'about', 'seo' => ['og_title' => 'Typed card title']], $entry->reference(), 'mt.preview.card')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $cached = (fn () => $this->storage)->call(Cache::store()->getStore());

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(getimagesizefromstring($response->getContent())[0])->toBe(1200)
        ->and(collect($cached)->keys()->filter(fn ($key) => str_contains($key, 'mt:og')))->toBeEmpty();
})->skip(! extension_loaded('imagick'), 'Share cards need PHP\'s imagick extension.');

test('the card route draws a term\'s card too', function () {
    $this->actingAs(cpUser(super: true));

    previewOf('taxonomies.topics.topic', ['title' => 'Ponds', 'slug' => 'ponds'], route: 'mt.preview.card')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
})->skip(! extension_loaded('imagick'), 'Share cards need PHP\'s imagick extension.');

test('the card route has nothing to draw with cards off', function () {
    $this->actingAs(cpUser(super: true));

    config(['marketing-toolkit.og.enabled' => false]);

    previewOf('collections.pages.page', ['title' => 'About'], route: 'mt.preview.card')->assertNotFound();
});

test('the card route answers at most 30 times a minute per user', function () {
    $this->actingAs(cpUser(super: true));
    config(['marketing-toolkit.og.enabled' => false]);

    foreach (range(1, 30) as $request) {
        previewOf('collections.pages.page', ['title' => 'About'], route: 'mt.preview.card')->assertNotFound();
    }

    previewOf('collections.pages.page', ['title' => 'About'], route: 'mt.preview.card')->assertTooManyRequests();
});

test('only people who may see the content get its preview', function () {
    $entry = entryIn('pages', 'about');

    previewOf('collections.pages.page', ['title' => 'About'], $entry->reference())->assertUnauthorized();

    $this->actingAs(cpUser([]));
    previewOf('collections.pages.page', ['title' => 'About'], $entry->reference())->assertForbidden();
    previewOf('collections.pages.page', ['title' => 'New'])->assertForbidden();
});

test('an editor of the collection gets the preview without any SEO permission', function () {
    $this->actingAs(cpUser(['view pages entries', 'create pages entries']));

    previewOf('collections.pages.page', ['title' => 'About'], entryIn('pages', 'about')->reference())->assertOk();
    previewOf('collections.pages.page', ['title' => 'New'])->assertOk();
});

test('a new entry or term is only previewed on a site its editor may work on', function () {
    multisite();
    Taxonomy::findByHandle('topics')->sites(['default', 'cothinking'])->save();
    $this->actingAs(cpUser(['create pages entries', 'create topics terms', 'access default site']));

    foreach (['collections.pages.page', 'taxonomies.topics.topic'] as $blueprint) {
        $preview = fn (string $site) => $this->postJson(cp_route('mt.preview.meta'), ['blueprint' => $blueprint, 'site' => $site, 'values' => ['title' => 'New']]);

        $preview('default')->assertOk();
        $preview('cothinking')->assertForbidden();
    }
});

test('a saved term is only previewed on a site its editor may work on', function () {
    multisite();
    Taxonomy::findByHandle('topics')->sites(['default', 'cothinking'])->save();
    $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();
    $this->actingAs(cpUser(['view topics terms', 'access default site']));

    $preview = fn (string $site) => $this->postJson(cp_route('mt.preview.meta'), ['blueprint' => 'taxonomies.topics.topic', 'reference' => $term->in($site)->reference(), 'site' => $site, 'values' => ['title' => 'Gardens']]);

    $preview('default')->assertOk();
    $preview('cothinking')->assertForbidden();
});

test('a blueprint that is not an entry or term has no preview', function () {
    $this->actingAs(cpUser(super: true));

    previewOf('globals.seo', [])->assertStatus(422);
    previewOf('collections.pages.nope', [])->assertStatus(422);
});

test('the SEO tab\'s headings arrive translated, since the control panel\'s script doesn\'t load field labels', function () {
    $field = (new Field('sharing', ['type' => 'mt_heading', 'display' => 'marketing-toolkit::fields.seo.sharing.display']));

    expect($field->fieldtype()->preload())->toBe(['display' => 'Sharing']);
});
