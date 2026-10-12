<?php

use Illuminate\Testing\TestResponse;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Og\Shape;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Token;
use Statamic\Tokens\Handlers\LivePreview as LivePreviewHandler;

beforeEach(function () {
    seoGlobal([]);

    foreach (['pages' => 'page', 'essays' => 'essay'] as $collection => $handle) {
        Blueprint::make($handle)->setNamespace('collections.'.$collection)->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'description', 'field' => ['type' => 'textarea']],
            ['handle' => 'lead', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'textarea']],
            ['handle' => 'faqs', 'field' => ['type' => 'grid', 'fields' => [
                ['handle' => 'question', 'field' => ['type' => 'text']],
                ['handle' => 'answer', 'field' => ['type' => 'textarea']],
            ]]],
            ['import' => 'marketing-toolkit::seo'],
        ]]]]]])->save();
    }

    config([
        'marketing-toolkit.collections.pages' => ['faq_field' => 'faqs'],
        'marketing-toolkit.collections.essays' => ['schema' => 'Article', 'og_type' => 'article', 'faq_field' => 'faqs'],
    ]);

    $disk = AssetContainer::find('assets')->disk();
    $disk->put('saved.png', file_get_contents(__DIR__.'/../fixtures/share.png'));
    $disk->put('typed.png', file_get_contents(__DIR__.'/../fixtures/share.png'));
});

// The asset files outlive a test: leave the container as it was found.
afterEach(function () {
    foreach (['saved.png', 'typed.png'] as $file) {
        AssetContainer::find('assets')->disk()->delete($file);
    }
});

/**
 * The values an editor's publish form holds for a page: every field, as the
 * control panel sends them, with these changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function formValues(EntryContract $entry, array $changes = []): array
{
    return array_replace([
        'title' => $entry->get('title'),
        'slug' => $entry->slug(),
        'description' => $entry->get('description'),
        'lead' => $entry->get('lead'),
        'body' => $entry->get('body'),
        'faqs' => $entry->get('faqs') ?? [],
        'seo' => $entry->get('seo') ?? [],
    ], $changes);
}

/**
 * Starts a Live Preview of $entry as the control panel does, with the form's
 * unsaved values, and returns the preview's address (with its token).
 *
 * @param  array<string, mixed>  $values
 */
function livePreview(EntryContract $entry, array $values): string
{
    return test()->postJson(cp_route('collections.entries.preview.edit', [$entry->collectionHandle(), $entry->id()]), ['preview' => $values])
        ->assertOk()
        ->json('url');
}

function tokenOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['token'];
}

/**
 * @return array<string, array<string, mixed>>
 */
function graphOf(TestResponse $response): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $match);

    return collect(json_decode($match[1], true)['@graph'])->keyBy(fn ($node) => (array) $node['@type'] === ['WebPage'] ? 'WebPage' : implode(',', (array) $node['@type']))->all();
}

function savedPage(): EntryContract
{
    return entryIn('pages', 'about', [
        'description' => 'Saved description.',
        'faqs' => [['question' => 'Saved question?', 'answer' => 'Saved answer.']],
        'seo' => ['og_title' => 'Saved card title'],
    ]);
}

test('a Live Preview prints the form\'s unsaved title, description and share tags in the head', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();

    $url = livePreview($entry, formValues($entry, [
        'title' => 'Typed title',
        'description' => 'Typed description.',
        'faqs' => [['question' => 'Typed question?', 'answer' => 'Typed answer.']],
        'seo' => ['og_title' => 'Typed card title'],
    ]));

    $response = $this->get($url)->assertOk();

    $response->assertSee('<title>Typed title</title>', false)
        ->assertSee('<meta name="description" content="Typed description.">', false)
        ->assertSee('<meta property="og:title" content="Typed title">', false)
        ->assertSee('<meta property="og:description" content="Typed description.">', false)
        ->assertDontSee('Saved');

    $graph = graphOf($response);

    expect($graph['WebPage']['name'])->toBe('Typed title')
        ->and($graph['WebPage']['description'])->toBe('Typed description.')
        ->and($graph['FAQPage']['mainEntity'][0]['name'])->toBe('Typed question?')
        ->and($graph['FAQPage']['mainEntity'][0]['acceptedAnswer']['text'])->toBe('<p>Typed answer.</p>');

    // What was saved stays as it was.
    expect(Entry::find($entry->id())->get('title'))->toBe('About');
});

test('the SEO fields typed in a Live Preview win, as they do once saved', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();

    $url = livePreview($entry, formValues($entry, [
        'seo' => ['title' => 'Typed SEO title', 'description' => 'Typed SEO description.', 'image' => ['assets::typed.png']],
    ]));

    $this->get($url)
        ->assertSee('<title>Typed SEO title</title>', false)
        ->assertSee('<meta name="description" content="Typed SEO description.">', false)
        ->assertSee('<meta property="og:title" content="Typed SEO title">', false)
        ->assertSee('/typed.png', false);
});

test('an SEO image cleared in a Live Preview reads as cleared, as the body reads a cleared field', function () {
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'about', ['seo' => ['image' => 'saved.png']]);

    $url = livePreview($entry, formValues($entry, ['seo' => ['image' => []]]));

    $this->get($url)->assertDontSee('/saved.png', false)->assertSee('https://example.test/og/about.png?v=', false);
});

test('an article\'s node carries the previewed headline, description and image', function () {
    $this->actingAs(cpUser(super: true));
    Collection::findByHandle('essays')->dated(true)->save();
    $entry = entryIn('essays', 'first', ['description' => 'Saved description.'], '2026-01-02');

    $url = livePreview($entry, formValues($entry, [
        'title' => 'Typed headline',
        'description' => 'Typed description.',
        'date' => '2026-01-02',
        'seo' => ['image' => ['assets::typed.png']],
    ]));

    $article = graphOf($this->get($url)->assertOk())['Article'];

    expect($article['headline'])->toBe('Typed headline')
        ->and($article['description'])->toBe('Typed description.')
        ->and($article['image'][0])->toContain('/typed.png');
});

test('the title and description fields a collection names follow a Live Preview', function () {
    $this->actingAs(cpUser(super: true));
    config(['marketing-toolkit.collections.pages' => ['title_fields' => ['lead'], 'description_fields' => ['body']]]);
    $entry = entryIn('pages', 'about', ['lead' => 'Saved lead', 'body' => 'Saved body.']);

    $url = livePreview($entry, formValues($entry, ['lead' => 'Typed lead', 'body' => 'Typed body.']));

    $this->get($url)
        ->assertSee('<title>Typed lead</title>', false)
        ->assertSee('<meta name="description" content="Typed body.">', false);
});

test('the share card in a Live Preview comes from a card address that carries the token', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();

    $url = livePreview($entry, formValues($entry, ['title' => 'Typed title', 'seo' => []]));

    preg_match('#<meta property="og:image" content="([^"]+)"#', $this->get($url)->getContent(), $match);
    $card = html_entity_decode($match[1]);

    expect($card)->toStartWith('https://example.test/og/about.png?v=')
        ->and($card)->toEndWith('&token='.tokenOf($url));
});

test('a previewed draft gets a card address too, as a preview shows drafts', function () {
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'draft');
    $entry->published(false)->save();

    $url = livePreview($entry, formValues($entry, ['title' => 'Typed title']));

    $this->get($url)->assertSee('https://example.test/og/draft.png?v=', false);
});

test('the card address with a preview\'s token draws the previewed card, uncached', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();
    $url = livePreview($entry, formValues($entry, ['title' => 'Typed title', 'seo' => []]));

    $previewed = $this->get('https://example.test/og/about.png?token='.tokenOf($url))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    $saved = $this->get('https://example.test/og/about.png')->assertOk();

    expect($previewed->headers->get('Cache-Control'))->toContain('no-store')
        ->and($previewed->getContent())->not->toBe($saved->getContent());
})->skip(! extension_loaded('imagick'), 'Share cards need PHP\'s imagick extension.');

test('a token for another entry, or a bogus one, gets the saved card', function () {
    $this->actingAs(cpUser(super: true));
    $about = savedPage();
    $other = entryIn('pages', 'other');
    $url = livePreview($other, formValues($other, ['title' => 'Typed title']));

    app()->instance(Generator::class, $generator = new class extends Generator
    {
        /** @var list<string> */
        public array $drawn = [];

        public function available(): bool
        {
            return true;
        }

        public function png(EntryContract $entry, Shape $shape = Shape::Landscape): string
        {
            $this->drawn[] = 'saved: '.$this->card($entry)->title;

            return 'png';
        }

        public function draw(EntryContract $entry, Shape $shape = Shape::Landscape): string
        {
            $this->drawn[] = 'preview: '.$this->card($entry)->title;

            return 'png';
        }
    });

    $this->get('https://example.test/og/about.png?token='.tokenOf($url))->assertOk()->assertHeader('Cache-Control', 'max-age=2592000, public');
    $this->get('https://example.test/og/about.png?token=nonsense')->assertOk();
    $this->get('https://example.test/og/other.png?token='.tokenOf($url))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($generator->drawn)->toBe(['saved: Saved card title', 'saved: Saved card title', 'preview: Typed title']);
});

test('a draft\'s card answers 404 without its preview\'s token', function () {
    $this->actingAs(cpUser(super: true));
    $entry = entryIn('pages', 'draft');
    $entry->published(false)->save();
    $other = entryIn('pages', 'other');
    $url = livePreview($other, formValues($other));

    $this->get('https://example.test/og/draft.png?token='.tokenOf($url))->assertNotFound();
});

test('without a preview\'s token the page prints what was saved', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();
    livePreview($entry, formValues($entry, ['title' => 'Typed title']));

    $this->get('https://example.test/about')
        ->assertSee('<title>About</title>', false)
        ->assertDontSee('Typed');
});

test('only the previewed entry is read from its unsaved values', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();
    $url = livePreview($entry, formValues($entry, ['title' => 'Typed title']));

    // Another entry carrying values set aside, inside the preview's request.
    $other = entryIn('pages', 'other');
    $other->setSupplement('title', 'Stray title');

    expect(metaFor($other, '/other?token='.tokenOf($url))->title)->toBe('Other');

    // And the previewed entry outside its preview.
    $entry->setSupplement('title', 'Stray title');
    expect(metaFor($entry, '/about')->title)->toBe('About');
});

test('a translation without SEO of its own takes its origin\'s in a Live Preview too', function () {
    multilang();
    $this->actingAs(cpUser(super: true));
    $origin = entryIn('pages', 'about', ['seo' => ['title' => 'Origin SEO title']]);
    $french = translationOf($origin, 'fr', 'a-propos');

    // A translation's form holds its origin's values for the fields it doesn't change, as it shows them.
    $url = livePreview($french, formValues($french, ['title' => 'Titre tapé', 'seo' => $origin->get('seo')]));

    $this->get($url)->assertSee('<title>Origin SEO title</title>', false);
    $this->get('https://example.test/fr/a-propos')->assertSee('<title>Origin SEO title</title>', false);
});

test('the preview\'s token is a Live Preview token', function () {
    $this->actingAs(cpUser(super: true));
    $entry = savedPage();

    expect(Token::find(tokenOf(livePreview($entry, formValues($entry))))->handler())->toBe(LivePreviewHandler::class);
});
