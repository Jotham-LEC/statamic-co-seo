## Marketing Toolkit (jotham-lec/statamic-marketing-toolkit)

This Statamic site uses the Marketing Toolkit for SEO, share cards, structured data, sitemaps, redirects, the 404 log, SEO reports, tracking tags and Consent Mode. Marketing staff manage these in the control panel (Marketing → …); code changes go through the addon's configuration and its `SiteSeo` class, never around it.

### Rules
- The layout prints everything with two tags: `<s:mt:head />` in `<head>` and `<s:mt:body />` after `<body>` (Antlers: `@{{ mt:head }}`, `@{{ mt:body }}`). Do not hand-write `<title>`, meta descriptions, canonical, Open Graph or Twitter tags, JSON-LD, hreflang, favicons, or Google Tag Manager, GA4, Meta Pixel, PostHog or LinkedIn scripts: the addon already prints them.
- Do not add routes or files for `/sitemap.xml`, `/robots.txt`, `/llms.txt`, `/ads.txt`, `/og/…` share cards or favicons: the addon serves them, and a file of the same name in `public/` hides its version.
- Redirects are content, not code: editors add them under Marketing → Redirects (or by CSV import), and a moved entry gets one automatically. Do not add redirect routes for content addresses. Redirects only answer paths the site would 404. They are YAML files in `content/redirects/` (or the `mt_redirects` table with `MT_REDIRECTS_DRIVER=eloquent`); from code, use `RedirectRepository`, not the files or the table.
- A page without an entry (a custom route's view) passes what it knows to the tag: `<s:mt:head title="Contact" description="…" />`; an error page passes `status="404"`.
- Per-collection and per-taxonomy rules live in `config/marketing-toolkit.php` (`collections`, `taxonomies`): `title_fields`, `description_fields`, `image_fields`, `faq_field`, `schema`, `page_schema`, `og_type`, `og_template`, `product`, `author_field`. Field names may point into a page builder's sets as `replicator.set.field`. Text and Bard fields both work.
- To change how a value is worked out, extend `JothamLec\MarketingToolkit\SiteSeo`, override its `@api` methods (`title()`, `description()`, `image()`, `extraNodes()`, `shouldNoindex()`…), and bind the subclass in a service provider: `$this->app->bind(\JothamLec\MarketingToolkit\SiteSeo::class, \App\Seo::class);`. Methods not marked `@api` are internal.
- Tracking IDs belong in Marketing → Settings → Tracking (or `MT_GTM_ID` and the like in `.env`). Tags load only in `tracking.environments` (production) and never in Live Preview.

### Checking your work
- With Laravel Boost, use the `marketing-toolkit-page-seo` tool to see what a page's `<head>` prints after a change, `marketing-toolkit-report` for the latest SEO report's failing checks, and `marketing-toolkit-redirect` to see which redirect answers a path.
- `php please mt:report` runs a fresh SEO report; `php please mt:install` adds the globals and blueprints the addon needs.
- The addon's docs: https://github.com/Jotham-LEC/statamic-marketing-toolkit/tree/main/docs (configuration.md, developers.md, editors.md).
