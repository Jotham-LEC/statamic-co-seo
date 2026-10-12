<?php

use Illuminate\Http\Request;
use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\Meta;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Package;
use JothamLec\MarketingToolkit\UpdateScripts\KeepSiteNameInTitles;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

beforeEach(fn () => seoGlobal(['default_description' => 'The default.']));

describe('title', function () {
    test('a page is its own title; home is the site name', function () {
        expect(metaFor(entryIn('pages', 'about'))->title)->toBe('About')
            ->and(metaFor(entryIn('home', 'home'))->title)->toBe('Acme');
    });

    test('with the toggle on, a page is "{title} · {site}"', function () {
        seoGlobal(['title_site_name' => true]);

        expect(metaFor(entryIn('pages', 'about'))->title)->toBe('About · Acme');
    });

    test('a site with a separator saved before the toggle existed keeps the site name, until the toggle is turned off', function () {
        seoGlobal(['title_separator' => '|']);
        expect(metaFor(entryIn('pages', 'about'))->title)->toBe('About | Acme');

        seoGlobal(['title_separator' => '|', 'title_site_name' => false]);
        expect(metaFor(entryIn('pages', 'team'))->title)->toBe('Team');
    });

    test('the site name is dropped when it would push the title past the limit', function () {
        seoGlobal(['title_site_name' => true]);
        config(['marketing-toolkit.title.max' => 20]);

        expect(metaFor(entryIn('pages', 'a-rather-long-page-title'))->title)->toBe('A rather long page title');
    });

    test('a shorter name for titles ends them in place of the site name, which the rest keeps', function () {
        seoGlobal(['title_site_name' => true, 'title_brand' => 'Acme']);
        Site::setSites(['default' => ['name' => 'Acme Industries Worldwide', 'url' => 'https://example.test/', 'locale' => 'en_US']]);
        $meta = metaFor(entryIn('pages', 'about'));

        expect($meta->title)->toBe('About · Acme')
            ->and($meta->siteName)->toBe('Acme Industries Worldwide')
            ->and(metaFor(entryIn('home', 'home'))->title)->toBe('Acme Industries Worldwide');
    });

    test('a field the collection names in title_fields titles the page before its own title, with the site name like it', function () {
        seoGlobal(['title_site_name' => true]);
        config(['marketing-toolkit.collections.pages.title_fields' => ['meta_title', 'seo_title']]);
        $meta = metaFor(entryIn('pages', 'about', ['seo_title' => 'About our team', 'meta_title' => '']));

        expect($meta->title)->toBe('About our team · Acme')
            ->and($meta->ogTitle)->toBe('About our team')
            ->and(metaFor(entryIn('pages', 'team'))->title)->toBe('Team · Acme')
            ->and(metaFor(entryIn('pages', 'both', ['seo_title' => 'From the field', 'seo' => ['title' => 'Typed in SEO']]))->title)->toBe('Typed in SEO');
    });

    test('an SEO title replaces the whole title; an empty one counts as not set', function () {
        expect(metaFor(entryIn('pages', 'about', ['seo' => ['title' => 'Who we are']]))->title)->toBe('Who we are')
            ->and(metaFor(entryIn('pages', 'team', ['seo' => ['title' => '']]))->title)->toBe('Team');
    });

    test('a later page of a listing says which page it is', function () {
        expect(metaFor(entryIn('pages', 'news'), '/news?page=3')->title)->toBe('News · Page 3');
    });
});

describe('description', function () {
    test('falls back from the SEO field to the description to the first paragraph to the site default', function () {
        expect(metaFor(entryIn('pages', 'a', ['seo' => ['description' => 'Override.'], 'description' => 'Own.']))->description)->toBe('Override.')
            ->and(metaFor(entryIn('pages', 'b', ['description' => 'Own.']))->description)->toBe('Own.')
            ->and(metaFor(entryIn('pages', 'c', ['content' => "First para.\n\nSecond."]))->description)->toBe('First para.')
            ->and(metaFor(entryIn('pages', 'd'))->description)->toBe('The default.');
    });

    test('an empty first paragraph is passed over, and a long text is cut on a word', function () {
        config(['marketing-toolkit.description.length' => 30]);

        $entry = entryIn('pages', 'long', ['content' => "&nbsp;\n\nThe real opening paragraph runs on for a while."]);

        expect(metaFor($entry)->description)->toBe('The real opening paragraph…');
    });
});

describe('canonical and robots', function () {
    test('the canonical is the page itself, keeps ?page and drops other query strings', function () {
        $entry = entryIn('pages', 'news');

        expect(metaFor($entry, '/news?utm_source=x')->canonical)->toBe('https://example.test/news')
            ->and(metaFor($entry, '/news?page=2')->canonical)->toBe('https://example.test/news?page=2');
    });

    test('a republished piece names the original, and false prints none', function () {
        expect(metaFor(entryIn('pages', 'reprint', ['seo' => ['canonical' => 'https://times.example/x']]))->canonical)->toBe('https://times.example/x')
            ->and(metaFor(entryIn('pages', 'plain'), '/plain', ['canonical' => false])->canonical)->toBeNull();
    });

    test('robots say index in production and noindex elsewhere, on request, on filters and on errors', function () {
        config(['marketing-toolkit.robots.noindex_params' => ['sort']]);
        $entry = entryIn('pages', 'news');

        expect(metaFor($entry)->robots)->toBe(config('marketing-toolkit.robots.default'))
            ->and(metaFor(entryIn('pages', 'hidden', ['seo' => ['noindex' => true]]))->robots)->toBe('noindex, follow')
            ->and(metaFor($entry, '/news?sort=new')->robots)->toBe('noindex, follow')
            ->and(metaFor(null, '/missing', status: 404)->robots)->toBe('noindex, follow');

        $this->app['env'] = 'staging';

        expect(metaFor($entry)->robots)->toBe('noindex, follow');
    });
});

describe('open graph', function () {
    test('a dated piece in an article collection carries its dates', function () {
        config(['marketing-toolkit.collections.essays' => ['og_type' => 'article']]);

        $meta = metaFor(entryIn('essays', 'first', [], '2026-01-02'));

        expect($meta->ogType)->toBe('article')
            ->and($meta->published)->toStartWith('2026-01-02T')
            ->and($meta->modified)->not->toBeNull();
    });

    test('og:type comes from the collection or the template, not from a value saved on the entry', function () {
        config(['marketing-toolkit.collections.essays' => ['og_type' => 'article']]);

        expect(metaFor(entryIn('essays', 'first', ['seo' => ['og_type' => 'profile']], '2026-01-02'))->ogType)->toBe('article')
            ->and(metaFor(entryIn('pages', 'team', ['seo' => ['og_type' => 'profile']]))->ogType)->toBe('website')
            ->and(metaFor(entryIn('pages', 'me'), '/me', ['og_type' => 'profile'])->ogType)->toBe('profile')
            ->and(Fieldset::find('marketing-toolkit::seo')->fields()->get('seo')->config()['fields'])
            ->each(fn ($field) => $field->handle->not->toBe('og_type'));
    });

    test('verification codes become meta tags', function () {
        GlobalSet::findByHandle('seo')->in('default')->set('google_verification', 'abc123')->save();

        expect(metaFor(entryIn('pages', 'about'))->verification)->toBe(['google-site-verification' => 'abc123']);
    });
});

describe('json-ld', function () {
    test('the graph has the site, the publisher, the page and its breadcrumbs', function () {
        entryIn('pages', 'essays');
        config(['marketing-toolkit.collections.essays' => ['schema' => 'Article']]);

        $graph = collect(metaFor(entryIn('essays', 'first', [], '2026-01-02'))->graph)->keyBy('@type');

        expect($graph->keys()->all())->toBe(['WebSite', 'Organization', 'WebPage', 'BreadcrumbList', 'Article'])
            ->and(collect($graph['BreadcrumbList']['itemListElement'])->pluck('name')->all())->toBe(['Acme', 'Essays', 'First'])
            ->and($graph['Article']['author'])->toBe(['@id' => 'https://example.test/#publisher']);
    });

    test('a draft ancestor is left out of the breadcrumbs: its title and address aren\'t public yet', function () {
        entryIn('pages', 'essays')->published(false)->save();

        $crumbs = nodeOf(metaFor(entryIn('essays', 'first', [], '2026-01-02')), 'BreadcrumbList')['itemListElement'];

        expect(collect($crumbs)->pluck('name')->all())->toBe(['Acme', 'First'])
            ->and(collect($crumbs)->pluck('position')->all())->toBe([1, 2]);
    });

    test('an FAQ grid becomes an FAQPage with the answers as the page shows them', function () {
        config(['marketing-toolkit.collections.pages' => ['faq_field' => 'faqs']]);

        $graph = collect(metaFor(entryIn('pages', 'help', ['faqs' => [['question' => 'Why?', 'answer' => 'Because **so**.']]]))->graph)->keyBy('@type');

        expect($graph['FAQPage']['mainEntity'][0]['acceptedAnswer']['text'])->toBe('<p>Because <strong>so</strong>.</p>');
    });

    test('a Bard answer is rendered as Bard renders it, without its sets; an answer of another shape leaves its question out', function () {
        config(['marketing-toolkit.collections.pages' => ['faq_field' => 'faqs']]);
        $bard = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Because '], ['type' => 'text', 'text' => 'so', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => '.']]],
            ['type' => 'set', 'attrs' => ['id' => 'a1', 'values' => ['type' => 'image']]],
        ];

        $faq = collect(metaFor(entryIn('pages', 'help', ['faqs' => [
            ['question' => 'Why?', 'answer' => $bard],
            ['question' => 'Only a set?', 'answer' => [['type' => 'set', 'attrs' => ['id' => 'a2', 'values' => []]]]],
            ['question' => 'A map?', 'answer' => ['text' => 'No']],
            ['question' => ['not' => 'text'], 'answer' => 'Hidden.'],
        ]]))->graph)->keyBy('@type')['FAQPage'];

        expect(collect($faq['mainEntity'])->pluck('name')->all())->toBe(['Why?'])
            ->and($faq['mainEntity'][0]['acceptedAnswer']['text'])->toBe('<p>Because <strong>so</strong>.</p>');
    });

    test('extra JSON-LD typed in the entry is added; text that does not parse is ignored', function () {
        $types = fn ($entry) => collect(metaFor($entry)->graph)->pluck('@type')->all();

        expect($types(entryIn('pages', 'event', ['seo' => ['json_ld' => '{"@type": "Event", "name": "Launch"}']])))->toContain('Event')
            ->and($types(entryIn('pages', 'broken', ['seo' => ['json_ld' => '{not json']])))->toBe(['WebSite', 'Organization', 'WebPage', 'BreadcrumbList']);
    });

    test('an error page has no graph', function () {
        expect(metaFor(null, '/missing', status: 404)->graph)->toBe([]);
    });
});

describe('fields in a Replicator\'s sets', function () {
    beforeEach(function () {
        config(['marketing-toolkit.og.enabled' => false]);
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'sections', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['display' => 'Main', 'sets' => [
                'hero' => ['display' => 'Hero', 'fields' => [
                    ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]],
                    ['handle' => 'lead', 'field' => ['type' => 'textarea']],
                ]],
                'faq' => ['display' => 'Questions', 'fields' => [
                    ['handle' => 'faqs', 'field' => ['type' => 'grid', 'fields' => [
                        ['handle' => 'question', 'field' => ['type' => 'text']],
                        ['handle' => 'answer', 'field' => ['type' => 'textarea']],
                    ]]],
                ]],
            ]]]]],
        ]]]]]])->save();
        foreach (['hidden.png', 'shown.png', 'seo.png'] as $file) {
            AssetContainer::find('assets')->disk()->put($file, file_get_contents(__DIR__.'/../fixtures/share.png'));
        }
    });

    test('the share image is the first visible set\'s with one; a set switched off is passed over', function () {
        config(['marketing-toolkit.collections.pages.image_fields' => ['sections.hero.image']]);
        $sections = [
            ['type' => 'hero', 'enabled' => false, 'image' => 'hidden.png'],
            ['type' => 'faq', 'faqs' => []],
            ['type' => 'hero', 'lead' => 'No photo here.'],
            ['type' => 'hero', 'image' => 'shown.png'],
        ];

        expect(metaFor(entryIn('pages', 'builder', ['sections' => $sections]))->image['url'])->toContain('/shown.png')
            ->and(metaFor(entryIn('pages', 'own', ['sections' => $sections, 'seo' => ['image' => 'assets::seo.png']]))->image['url'])->toContain('/seo.png');
    });

    test('`*` matches a set of any type', function () {
        config(['marketing-toolkit.collections.pages.image_fields' => ['sections.*.image']]);

        expect(metaFor(entryIn('pages', 'any', ['sections' => [['type' => 'faq'], ['type' => 'hero', 'image' => 'shown.png']]]))->image['url'])->toContain('/shown.png');
    });

    test('the FAQPage has the questions of every visible set, in the page\'s order', function () {
        config(['marketing-toolkit.collections.pages.faq_field' => 'sections.faq.faqs']);
        $entry = entryIn('pages', 'help', ['sections' => [
            ['type' => 'faq', 'faqs' => [['question' => 'First?', 'answer' => 'Yes.']]],
            ['type' => 'faq', 'enabled' => false, 'faqs' => [['question' => 'Hidden?', 'answer' => 'Yes.']]],
            ['type' => 'hero', 'lead' => 'Between.'],
            ['type' => 'faq', 'faqs' => [['question' => 'Last?', 'answer' => 'Also **yes**.']]],
        ]]);

        $faq = collect(metaFor($entry)->graph)->keyBy('@type')['FAQPage'];

        expect(collect($faq['mainEntity'])->pluck('name')->all())->toBe(['First?', 'Last?'])
            ->and($faq['mainEntity'][1]['acceptedAnswer']['text'])->toBe('<p>Also <strong>yes</strong>.</p>');
    });

    test('a description field in a set is read from the first visible set with one', function () {
        config(['marketing-toolkit.collections.pages.description_fields' => ['sections.hero.lead']]);

        expect(metaFor(entryIn('pages', 'described', ['sections' => [
            ['type' => 'hero', 'enabled' => false, 'lead' => 'Hidden lead.'],
            ['type' => 'hero', 'lead' => 'Shown lead.'],
        ]]))->description)->toBe('Shown lead.');
    });

    test('a description field may be Bard, block or inline, read as plain text', function () {
        config(['marketing-toolkit.collections.pages.description_fields' => ['sections.hero.lead', 'intro']]);
        $inline = [['type' => 'text', 'text' => 'Baskets '], ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'made by hand'], ['type' => 'text', 'text' => ' & delivered.']];
        $blocks = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First.']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'image' => 'a.png']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second,'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'on a new line.']]],
        ];

        expect(metaFor(entryIn('pages', 'inline', ['sections' => [['type' => 'hero', 'lead' => $inline]]]))->description)->toBe('Baskets made by hand & delivered.')
            ->and(metaFor(entryIn('pages', 'blocks', ['intro' => $blocks]))->description)->toBe('First. Second, on a new line.')
            // An empty Bard field, or one holding only a set, counts as not set.
            ->and(metaFor(entryIn('pages', 'empty', ['intro' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'image']]]], 'description' => 'Own.']))->description)->toBe('Own.');
    });

    test('a title field may be Bard, read as plain text', function () {
        config(['marketing-toolkit.collections.pages.title_fields' => ['heading']]);

        expect(metaFor(entryIn('pages', 'titled', ['heading' => [['type' => 'text', 'text' => 'Slow '], ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'gifting']]]))->ogTitle)->toBe('Slow gifting');
    });

    test('no visible set, or none of that type, gives nothing', function () {
        config(['marketing-toolkit.collections.pages' => ['image_fields' => ['sections.hero.image'], 'faq_field' => 'sections.faq.faqs']]);
        $meta = metaFor(entryIn('pages', 'empty', ['sections' => [['type' => 'hero', 'enabled' => false, 'image' => 'hidden.png']]]));

        expect($meta->image)->toBeNull()
            ->and(collect($meta->graph)->pluck('@type')->all())->not->toContain('FAQPage');
    });
});

test('the tag prints the tags, escaped, with JSON-LD that cannot close its script', function () {
    entryIn('pages', 'quotes', ['title' => 'Say "hi" </script><b>&']);
    $html = renderAt('/quotes', '<s:mt:meta :entry="$entry" />', ['entry' => Entry::findByUri('/quotes')]);

    expect($html)->toContain('<title>Say &quot;hi&quot; &lt;/script&gt;&lt;b&gt;&amp;</title>')
        ->toContain('<link rel="canonical" href="https://example.test/quotes">')
        ->toContain('<meta property="og:image" content="https://example.test/og/quotes.png?v=')
        ->not->toContain('</script><b>');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);

    expect(json_decode($match[1], true)['@graph'][2]['name'])->toBe('Say "hi" </script><b>&');
});

test('a page without an entry passes what it knows', function () {
    expect(renderAt('/contact-form', '<s:mt:meta title="Contact" description="Write to us." />'))
        ->toContain('<title>Contact</title>')
        ->toContain('<meta name="description" content="Write to us.">')
        ->toContain('<link rel="canonical" href="https://example.test/contact-form">');
});

class CountingSeo extends SiteSeo
{
    /** @var array<string, int> */
    public static array $calls = [];

    protected function contentDescription(Context $context): ?string
    {
        self::$calls['body'] = (self::$calls['body'] ?? 0) + 1;

        return parent::contentDescription($context);
    }

    public function generatedImageUrl(Statamic\Contracts\Entries\Entry $entry): ?string
    {
        self::$calls['card'] = (self::$calls['card'] ?? 0) + 1;

        return parent::generatedImageUrl($entry);
    }
}

test('the tag works out the description and the share image once per page', function () {
    config(['marketing-toolkit.class' => CountingSeo::class, 'marketing-toolkit.collections.essays.schema' => 'Article']);
    $entry = entryIn('essays', 'long-read', ['content' => "The first paragraph.\n\nThe second."], '2026-01-02');
    CountingSeo::$calls = [];

    $html = renderAt('/essays/long-read', '<s:mt:meta :entry="$entry" />', ['entry' => $entry]);

    expect($html)->toContain('The first paragraph.')->and(CountingSeo::$calls)->toBe(['body' => 1, 'card' => 1]);
});

test('one rules object asked about page after page gives each its own values', function () {
    $seo = app(SiteSeo::class);
    $entries = [entryIn('pages', 'one', ['description' => 'First.']), entryIn('pages', 'two', ['description' => 'Second.'])];

    $descriptions = array_map(fn ($entry) => $seo->description(Context::make($entry)), $entries);

    expect($descriptions)->toBe(['First.', 'Second.']);
});

describe('taxonomies', function () {
    beforeEach(fn () => Taxonomy::make('topics')->save());

    test('a term page follows its taxonomy\'s rules', function () {
        config(['marketing-toolkit.taxonomies.topics' => ['page_schema' => 'CollectionPage', 'og_type' => 'article', 'description_fields' => ['intro']]]);
        $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens', 'intro' => 'Everything that grows.']))->save();

        $meta = app(SiteSeo::class)->meta(Context::make($term->in('default'), Request::create('https://example.test/topics/gardens')));

        expect($meta->description)->toBe('Everything that grows.')
            ->and($meta->ogType)->toBe('article')
            ->and(collect($meta->graph)->pluck('@type'))->toContain('CollectionPage');
    });

    test('a term without rules of its own, or a collection\'s, keeps the defaults', function () {
        config(['marketing-toolkit.collections.topics' => ['page_schema' => 'CollectionPage', 'description_fields' => ['intro']]]);
        $term = tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens', 'intro' => 'Everything that grows.']))->save();

        $meta = app(SiteSeo::class)->meta(Context::make($term->in('default'), Request::create('https://example.test/topics/gardens')));

        expect($meta->description)->toBe('The default.')
            ->and($meta->ogType)->toBe('website')
            ->and(collect($meta->graph)->pluck('@type'))->toContain('WebPage')->not->toContain('CollectionPage');
    });
});

/**
 * Before the toggle, a saved separator meant the site name; in the control
 * panel the new toggle showed off there, and the next save dropped it.
 */
test('the update script turns the toggle on where a separator is saved, and leaves a choice alone', function () {
    $script = new KeepSiteNameInTitles(Package::NAME);
    seoGlobal(['title_separator' => '|']);
    $script->update();
    expect(GlobalSet::findByHandle('seo')->in('default')->get('title_site_name'))->toBeTrue();

    seoGlobal(['title_separator' => '|', 'title_site_name' => false]);
    $script->update();
    expect(GlobalSet::findByHandle('seo')->in('default')->get('title_site_name'))->toBeFalse();

    seoGlobal(['default_description' => 'The default.']);
    $script->update();
    expect(GlobalSet::findByHandle('seo')->in('default')->data()->has('title_site_name'))->toBeFalse();
});

test('a brand image is found from its path and the field\'s container, and nothing when empty', function () {
    AssetContainer::find('assets')->disk()->put('brand.png', file_get_contents(__DIR__.'/../fixtures/share.png'));
    seoGlobal(['default_image' => ['brand.png'], 'publisher_logo' => 'assets::brand.png']);
    $settings = app(SiteSeo::class)->settings();

    expect($settings->asset('default_image')?->id())->toBe('assets::brand.png')
        ->and($settings->asset('publisher_logo')?->id())->toBe('assets::brand.png')
        ->and($settings->asset('favicon'))->toBeNull();
});

test('a brand image in a field imported from a fieldset is found from its path too', function () {
    AssetContainer::find('assets')->disk()->put('brand.png', file_get_contents(__DIR__.'/../fixtures/share.png'));
    Fieldset::make('brand_images')->setContents(['fields' => [
        ['handle' => 'default_image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]],
    ]])->save();
    seoGlobal(['default_image' => ['brand.png']]);
    Blueprint::make('seo')->setNamespace('globals')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
        ['import' => 'brand_images'],
    ]]]]]])->save();

    expect(app(SiteSeo::class)->settings()->asset('default_image')?->id())->toBe('assets::brand.png');

    Fieldset::find('brand_images')->delete();
});

test('text that isn\'t UTF-8 can\'t fail the page: the JSON-LD prints a replacement character', function () {
    $meta = new Meta(
        title: 'Café', description: null, canonical: null, robots: 'index, follow', ogTitle: 'Café', ogType: 'website',
        url: 'https://example.test/', siteName: 'Acme', locale: 'en_US', image: null, published: null, modified: null,
        twitterSite: null, verification: [], graph: [['@type' => 'WebPage', 'name' => "Caf\xE9"]],
    );

    expect($meta->jsonLd())->toContain('"name":"Caf'."\u{FFFD}".'"');
});
