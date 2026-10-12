---
name: marketing-toolkit-extend
description: "Use when the Marketing Toolkit's SEO output must follow a rule that configuration can't express: a title or description worked out in code, extra JSON-LD (Event, Course, LocalBusiness details), noindexing some pages, URLs in the sitemap that aren't entries, or a custom share-card design. Covers subclassing JothamLec\\MarketingToolkit\\SiteSeo through its @api methods and adding an Og\\Template."
license: MIT
metadata:
  author: jotham-lec
---

# Marketing Toolkit: changing a rule in code

Every value the Marketing Toolkit prints comes from one public method of `JothamLec\MarketingToolkit\SiteSeo`, called with a `Context` (the entry or term, the request, the template's overrides and the response status). Change a rule by overriding that method in a subclass. First check whether `config/marketing-toolkit.php` already covers it (the `marketing-toolkit-seo-rules` skill).

## Steps

1. Create the subclass and override only the `@api` methods you need. Call `parent::` for the default:

```php
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
        ]];
    }

    public function shouldNoindex(Context $context): bool
    {
        return parent::shouldNoindex($context) || $context->request->is('thank-you');
    }
}
```

2. Bind it in a service provider's `register()`: `$this->app->bind(\JothamLec\MarketingToolkit\SiteSeo::class, \App\Seo::class);`
3. Check the page with the `marketing-toolkit-page-seo` tool (`json_ld_types`, `robots`, `title`…).

## Methods worth knowing

- **Values:** `title()`, `ogTitle()`, `description()`, `image()`, `canonical()`, `robots()`, `ogType()`, `shouldNoindex()`.
- **JSON-LD nodes:** `websiteNode()`, `publisherNode()`, `webPageNode()`, `breadcrumbNode()`, `articleNode()`, `productNode()` and `faqNode()` (return `null` to leave a node out), plus `extraNodes()` for your own.
- **Sitemap:** `additionalSitemapUrls()` (as `[['loc' => …, 'lastmod' => …]]`) and `inSitemap(Entry|Term)`.
- **Helpers:** `settings()` (the Brand and Marketing settings globals), `contentConfig()`, `collectionConfig()` and `absolute()`.

Methods without `@api` are internal and may change in any release.

## A share-card design

Extend `JothamLec\MarketingToolkit\Og\Template` and return a simonhamp/the-og `Image` from `image(Card $card)`. Bump `version()` when the design changes. Register it in `og.templates`, and choose it per collection or taxonomy with `og_template`.

Docs: https://github.com/Jotham-LEC/statamic-marketing-toolkit/blob/main/docs/developers.md
