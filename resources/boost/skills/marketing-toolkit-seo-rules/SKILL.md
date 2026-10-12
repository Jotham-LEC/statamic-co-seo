---
name: marketing-toolkit-seo-rules
description: "Use when a collection's or taxonomy's pages need better SEO from the Marketing Toolkit: their title, description, share image, FAQ, product or article structured data, og:type or share-card design come from the wrong field or none. Covers config/marketing-toolkit.php `collections` and `taxonomies` rules, fields inside a page builder (replicator.set.field), and checking the result."
license: MIT
metadata:
  author: jotham-lec
---

# Marketing Toolkit: SEO rules for collections and taxonomies

The Marketing Toolkit works out every page's title, description, share image and structured data by itself. When a collection keeps its summary, picture or questions in fields of its own, tell the addon which fields, in `config/marketing-toolkit.php`. Don't print tags in the templates instead.

## Steps

1. Find out what the page prints now: the `marketing-toolkit-page-seo` tool with the page's path (or read `<head>` of the rendered page). Note which value is wrong or empty.
2. Read the collection's blueprint (`resources/blueprints/collections/{handle}/*.yaml`) to find the field holding the right value.
3. Add the rule under `collections.{handle}` (or `taxonomies.{handle}` for term pages):

```php
'collections' => [
    'products' => [
        'description_fields' => ['summary'],            // tried before the body's first paragraph
        'image_fields' => ['images'],                   // tried before the generated card
        'product' => ['price_field' => 'price', 'availability_field' => 'in_stock', 'sku_field' => 'sku'],
    ],
    'pages' => [
        'description_fields' => ['blocks.hero.text'],   // the first visible hero set's text
        'image_fields' => ['blocks.hero.image'],
        'faq_field' => 'blocks.faq.items',              // every visible FAQ set's questions
    ],
    'articles' => ['schema' => 'BlogPosting', 'og_type' => 'article', 'author_field' => 'author'],
],
'taxonomies' => [
    'topics' => ['page_schema' => 'CollectionPage', 'description_fields' => ['description']],
],
```

   - A name with dots is `replicator.set.field`, with `*` for any set type. Sets switched off are skipped.
   - Text, textarea, Markdown and Bard fields all work for titles and descriptions.
   - `title_fields` is tried before the entry's title, and the site name is still added.
   - A share-card design of your own is a template class registered in `og.templates`, chosen with `og_template`.
4. Check again with `marketing-toolkit-page-seo`: the description, `image` and `json_ld_types` should now reflect the fields. For FAQs, look for `FAQPage`; for products, `Product`.
5. If the site has a report, run `php please mt:report` and check `marketing-toolkit-report` for remaining failures (titles too long, missing descriptions, no share image).

## Don't

- Don't add `<meta>`, `<title>` or JSON-LD to views; the addon already prints them, and a duplicate confuses search engines.
- Don't add a description or image field to every blueprint when an existing field can be named in the config.
- Editors' own SEO fields (the SEO tab) always win over these rules; that's intended.
