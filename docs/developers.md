# For developers

How the addon works out each value, and where to change it for one site.

## How a value is worked out

Every value the `<head>` prints comes from one public method of `JothamLec\MarketingToolkit\SiteSeo`, called with a `Context`:

```php
final readonly class Context
{
    public ?Entry $entry;     // the entry being shown, if any
    public ?Term $term;       // or the term
    public Request $request;
    public array $overrides;  // what the template passed: title, description, canonical, image, og_type, noindex
    public int $status;       // 200, or what the template passed (404…)

    public function content(): Entry|Term|null;
    public function seo(): array;      // the content's `seo` group, without empty fields
    public function override(string $key): mixed;
    public function isHome(): bool;
    public function page(): int;       // ?page=N, at least 1
}
```

`SiteSeo::meta($context)` collects everything into a readonly `Meta` object that `resources/views/meta.blade.php` prints. The same rules feed the sitemap, the share cards, the control panel preview and the reports, so a change in one place shows everywhere.

## What each value falls back to

| Value | Order |
|---|---|
| `<title>` | SEO title as typed → the title (with **Add the site name to page titles** on: `{title}{separator}{site}` if it fits `marketing-toolkit.title.max`) → site name on home. `· Page N` past page 1, in the page's language |
| description | SEO description → `description` field → `description_fields` (text or Bard) → first paragraph of `content` → global default. Cut to 160 on a word (`marketing-toolkit.description.length`) |
| share image | template `image` → SEO share image → `image_fields` (a field in a Replicator's sets too) → **generated card** → global default image. Uploads are cropped to 1200×630 JPEG through Glide |
| canonical | template `canonical` (`false` for none) → SEO canonical (a piece first published elsewhere) → the page, with `?page=N` |
| robots | noindex when: SEO noindex, not production, a `noindex_params` query, a `noindex_routes` route, a 4xx status, or your `shouldNoindex()` |
| hreflang | the page's other languages that are published and listed, with `x-default`: `alternates()`, `localizations()`, `hreflangCodes()`, `xDefaultSite()` |
| og:locale, `inLanguage` | the content's own site's locale and language, whichever domain the request came in on |

Empty fields count as unset.

## Change a rule: SiteSeo

Extend the class, override the methods you need, and bind your class in its place in a service provider, as for any Laravel class:

```php
// app/Seo.php
namespace App;

use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\SiteSeo;

class Seo extends SiteSeo
{
    public function extraNodes(Context $context): array
    {
        if ($context->entry?->collectionHandle() !== 'events') {
            return [];
        }

        return [[
            '@type' => 'Event',
            'name' => $context->entry->get('title'),
            'startDate' => $context->entry->date()->toAtomString(),
            'location' => ['@type' => 'Place', 'name' => $context->entry->get('venue')],
        ]];
    }
}
```

```php
// app/Providers/AppServiceProvider.php, in register()
$this->app->bind(\JothamLec\MarketingToolkit\SiteSeo::class, \App\Seo::class);
```

(`'class' => App\Seo::class` in `config/marketing-toolkit.php`, the way before, still works until 1.0.)

The methods below are the API, marked `@api` in the source: their names and signatures change only in a major version. Other methods, public or protected, are internal and may change in any release; if you need one, open an issue.

The methods you're most likely to override:

| Method | Returns |
|---|---|
| `title(Context)` | The `<title>`. |
| `ogTitle(Context)` | og:title and twitter:title. |
| `description(Context)` | The description, already cut to length. |
| `image(Context)` | `['url', 'width', 'height', 'alt']` or `null`: override, uploaded image, `image_fields`, generated card, default image. |
| `canonical(Context)` | The canonical URL, or `null` for none. |
| `robots(Context)` | The robots meta content. |
| `snippetRules(Context)` | The robots content after any `nofollow`: `marketing-toolkit.robots.default` with the page's **No snippet** or **Snippet length** applied. |
| `shouldNoindex(Context)` | Extra reasons to noindex (an empty listing, a thank-you page). Return `parent::shouldNoindex($context) || …`. |
| `hiddenOutsideProduction()` | Whether this copy of the site is kept out of search engines (every page noindexed, robots.txt disallowing all): `marketing-toolkit.robots.noindex_outside_production` unless `APP_ENV=production`. |
| `ogType(Context)` | og:type. |
| `graph(Context)` | Every JSON-LD node. Usually you override one of the node methods instead. |
| `publisherTypes()`, `profileEntity(Context)`, `authors(Context)`, `articleImages(Context)` | The publisher's schema.org types; who a ProfilePage is about; an Article's authors; its images in three shapes. |
| `websiteNode()`, `publisherNode()`, `webPageNode(Context)`, `breadcrumbNode(Context)`, `articleNode(Context)`, `productNode(Context)`, `faqNode(Context)` | One node each; return `null` to leave it out. Products come from a collection's `product` config. |
| `extraNodes(Context)` | Your own nodes (Event, Course…), worked out in code. Empty by default. Editors' hand-written JSON-LD, from a page's **Extra JSON-LD** field, comes from `customNodes(Context)`. |
| `additionalSitemapUrls()` | URLs that aren't entries or terms, as `[['loc' => …, 'lastmod' => …]]`. |
| `inSitemap(Entry\|Term)` | Whether a content item is listed. |
| `isProtected(Entry\|Term)` | Whether Statamic protects a content item (its `protect` value, else `statamic.protect.default`). Protected content stays out of the sitemap, llms.txt and IndexNow, and has no generated share card. |
| `termHasEntries(Term)` | Whether a term has published entries, for the sitemap and the reports. Override for a taxonomy that isn't attached to the collection whose entries use it. |
| `robotsTxt()` | robots.txt. |
| `llmsPerCollection()` | How many pages llms.txt lists per collection, the most recently changed first. 100 by default. |

Helpers available in a subclass: `settings()` (the Brand and Marketing settings globals, with `string()`, `list()`, `asset()`, `siteName()`), `contentConfig($context, $key, $default)` (the page's collection rules, or a term's taxonomy rules), `collectionConfig($context, $key, $default)` (an entry's collection only), and `absolute($url)`.

## The tag

`<s:mt:head />` (Antlers: `{{ mt:head }}`) prints the Consent Mode defaults, the tracking tags and the meta tags; `<s:mt:meta />` prints the meta tags alone; `<s:mt:body />` prints the tracking tags' `<noscript>` fallbacks. They read the entry or term from the view's `page`. Parameters of `mt:head` and `mt:meta`:

| Parameter | |
|---|---|
| `:entry="$entry"` | The content to describe, when the view's `page` isn't it. |
| `title`, `description` | For a page without content, or to override. A `title` gets the site name added like a content title. |
| `image` | A share image URL. |
| `:canonical="false"` | No canonical tag (error pages). Or a URL. |
| `og_type` | og:type. |
| `:noindex="true"` | Noindex this page. |
| `status` | The response status; 4xx pages get noindex and no JSON-LD. |

## Tracking from code

`JothamLec\MarketingToolkit\Tracking\Tracking` works out the tags: `ids()`, `consent()`, `posthogHost()`, `posthogUiHost()`, `besideGtm()`, `head()` and `body()`. To change one rule, e.g. `ids()` to read the IDs from somewhere else, extend it and bind your class in a service provider of your own:

```php
// app/Providers/AppServiceProvider.php, in register()
$this->app->bind(\JothamLec\MarketingToolkit\Tracking\Tracking::class, \App\Tracking::class);
```

See [tracking.md](tracking.md) for how the tags and Consent Mode behave.

## Add a share-card template

A template turns a `Card` (title, description, label, site name, picture, colours, the brand's `logo` file or `brandText`, and the `shape` to draw) into a [simonhamp/the-og](https://github.com/simonhamp/the-og) image:

```php
namespace App\Og;

use JothamLec\MarketingToolkit\Og\Card;
use JothamLec\MarketingToolkit\Og\Template;
use SimonHamp\TheOg\Image;
use SimonHamp\TheOg\Layout\Layouts\Standard;
use SimonHamp\TheOg\Theme;

class EssayTemplate extends Template
{
    public function image(Card $card): Image
    {
        return (new Image)
            ->layout(new Standard)
            ->theme(Theme::Dark->load()->accentColor($card->accent))
            ->title($card->title)
            ->description($card->description ?? '')
            ->url($card->siteName);
    }

    public function version(): string
    {
        return '2'; // bump when the design changes, so cached cards are redrawn
    }
}
```

Register it and choose where it's used:

```php
'og' => ['templates' => ['default' => DefaultTemplate::class, 'essay' => App\Og\EssayTemplate::class]],
'collections' => ['essays' => ['og_template' => 'essay']],
'taxonomies' => ['topics' => ['og_template' => 'essay']], // a term page's card
```

A template draws the landscape card (1200×630) unless it lists more shapes; the others answer 404 for it. To draw them, return them from `shapes()` and size your layout from `$card->shape` (`width()`, `height()`). the-og's layouts fix their size in the class, so a shape other than 1200×630 needs an `AbstractLayout` subclass of your own that sets `$width` and `$height`, as `Og\Layouts\Branded` does:

```php
public function shapes(): array
{
    return [Shape::Landscape, Shape::Square, Shape::Classic];
}
```

To place the brand as the default card does, `LogoBox::fit($width, $height, $card->shape)` gives the logo's position and size in the card (or null when it would be too small, so you draw `$card->brandText` instead), and `LogoBox::pill($colour, $card->background, $card->text)` says whether it needs a backing. `$card->logo` is a local file that already fits the shape.

Cards are cached per entry, last-modified time, template, version, shape, logo and text, and served from `/og.png` and `/og/{path}.png` (the page's path from the domain's root, so `/og/fr/a-propos.png` for `/fr/a-propos`, and `.1x1.png` or `.4x3.png` for the other shapes) without cookies, so a CDN can cache them.

## Redirects, 404s and reports from code

- **Redirects** are the Eloquent model `JothamLec\MarketingToolkit\Redirects\Redirect` (`site`, `source`, `target`, `status`, `active`, `automatic`, `hits`, `last_hit_at`). `site` is a site handle, or null for every site (always null on a single site). Saving or deleting one through the model clears the cached rules; after bulk queries, call `JothamLec\MarketingToolkit\Redirects\Matcher::flush()`.
- **Automatic redirects** go through `JothamLec\MarketingToolkit\Redirects\AutoRedirects::create($from, $to, $site)`, which also collapses chains among that site's rules. Use it when you move content in code.
- **The 404 log** is `JothamLec\MarketingToolkit\NotFound\MissingPath` (with `site`, as redirects).
- **Reports**: `app(JothamLec\MarketingToolkit\Reports\Runner::class)->runToEnd($runner->start(site: 'handle'))` runs one in-process; without `site`, of the current site. Reports, like the 404 log, have a `site` column that is null on a single site. Each check is a class in `src/Reports/Rules` extending `Rule` (`handle()`, `label()`, `weight()`, `check($url, PageFacts, SiteFacts): Result`).
- **Another site as the current one**: `JothamLec\MarketingToolkit\Support\Sites::as($handle, fn () => …)` runs code with that site current (the Brand and Marketing settings globals, `absolute()` and the sitemap read it) and puts back what was there.

## Several sites and languages

With Statamic Pro and more than one site, whether separate brands on their own domains or languages under `/fr/` or on their own domains, each site gets its own:

- **Brand and Marketing settings**: `mt:install` puts both global sets on every site, each other site taking what it leaves empty from the default site's. A set that already exists isn't changed: enable it on each site under **Globals** (or in the set's `sites`), else that site uses the addon's defaults.
- **Sitemap and robots.txt** per domain: a sitemap lists every site on its domain, each URL with its other languages. **Share cards** and the **IndexNow key** on the site's own domain; IndexNow gets one request per domain.
- **hreflang**: a page's localizations link to each other; see [configuration.md](configuration.md#languages-hreflang).
- **Redirects** for one site or for every site (a site's own wins from the same address), and **automatic 301s** on the site of the content that moved.
- **404 log** and **reports**, one report per site (`mt:report` reports on each in turn, or `--site=`). The Marketing section's screens and the dashboard widget show the site selected in the control panel.
- **Search Console property**: one key, a property per site (set up from Marketing → Search Console with the site selected, or a map in config).

The SEO fields are `localizable`, so each language keeps its own values.

## Translating the control panel

Every word the addon shows in the control panel, and the "Page N" it adds to titles, is in `lang/en/*.php` under the `marketing-toolkit::` namespace (`cp.php`, `fields.php`, `reports.php`, `validation.php`, `frontend.php`). To translate, publish them and copy the folder:

```bash
php artisan vendor:publish --tag=marketing-toolkit-translations   # lang/vendor/marketing-toolkit/en
cp -r lang/vendor/marketing-toolkit/en lang/vendor/marketing-toolkit/fr
```

The control panel uses the user's language preference; "Page N" uses each site's language. A report shows its checks in the reader's language: results are stored as keys and translated when shown.

## Names

Two names, by one rule: `marketing-toolkit`, the addon's slug, wherever Statamic names a thing after the slug; `mt` wherever you type a short handle.

| Name | Where |
|---|---|
| `jotham-lec/statamic-marketing-toolkit` | The Composer package |
| `JothamLec\MarketingToolkit\…` | PHP classes |
| `marketing-toolkit` | `config/marketing-toolkit.php` (`--tag=marketing-toolkit-config`); the `marketing-toolkit::` views, translations (`--tag=marketing-toolkit-translations`) and fieldset (`marketing-toolkit::seo`); the addon's settings (`resources/addons/marketing-toolkit.yaml`); the control panel's addresses (`/cp/marketing-toolkit`), scripts (`--tag=marketing-toolkit`, `public/vendor/statamic-marketing-toolkit`) and permissions (`view marketing toolkit`, `manage marketing toolkit redirects`, `run marketing toolkit reports`); its files in `storage/app/marketing-toolkit` and `storage/app/private/marketing-toolkit` |
| `mt` | The tags (`<s:mt:head />`, `{{ mt:head }}`), the commands (`mt:install`, `mt:report`, `mt:search-console`), the tables (`mt_*`), route names (`mt.*`), `.env` (`MT_*`), the widget (`'type' => 'mt'`), the fieldtype (`mt_preview`), the toolbar's tag (`<s:mt:toolbar />`), endpoint (`/!/marketing-toolkit/toolbar`, route `statamic.mt.toolbar`) and in the browser `window.mtConversion()`, `window.mtConsent()`, the `mt_source`, `mt_conversion` and `mt_toolbar` cookies, and the `mt-toolbar` and `mt-toolbar-shell` keys in `localStorage` |

`seo` is left only where it is content about SEO: the SEO fields' `seo` group in each entry, the `seo` fieldset, and the **Brand** global set's handle (`seo`, `marketing-toolkit.global`), which was called SEO & brand up to 0.20. The Marketing settings set, new after 0.20, has the handle `marketing` (`marketing-toolkit.settings_global`). Up to 0.19 everything above was `seo`; [upgrading.md](upgrading.md) says how a site moves.

## How the pieces fit

The code map, and the path a request takes for a page's title, a redirect and a report, are in [CONTRIBUTING.md](../CONTRIBUTING.md#where-things-live), with how to run the tests, build and release.
