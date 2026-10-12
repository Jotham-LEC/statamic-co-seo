# Configuration

Two places, by who changes what:

- **`config/marketing-toolkit.php`**: rules that belong in code and git (which field is the description, the schema type, redirects and the 404 log). Publish it with `php artisan vendor:publish --tag=marketing-toolkit-config`; anything you leave out keeps its default, at any depth: set `og.templates` alone and `og.enabled` stays. A list you set (`not_found.ignore_paths`, `sitemap.collections`) replaces the default list; copy the defaults in if you want to add to them. A file published by 0.19 or earlier (as config/seo.php, which the update moves) keeps working: its `robots_txt`, `llms_txt` and `ads_txt` switches and its old tracking keys (`gtm`, `ga4`, `meta_pixel`, `linkedin`) are read under their new names.
- **Marketing → Reports → Settings** (Statamic's addon settings for Marketing Toolkit): report settings an editor may want to change.

The site's name is Statamic's own (Settings → Sites, else `APP_NAME`). Brand details (separator, logo, colours) are content, edited under **Marketing → Brand**, and so are the tracking IDs, Consent Mode, leads and crawler settings, under **Marketing → Settings**; see [editors.md](editors.md#brand).

## config/marketing-toolkit.php

### Global sets

| Key | Default | |
|---|---|---|
| `global` | `'seo'` | Handle of the Brand global set: the brand, the publisher, the shop and the share cards. |
| `settings_global` | `'marketing'` | Handle of the Marketing settings global set: tracking, Consent Mode, leads and crawlers. |

Each set has its own values per site. The addon looks for a field in Brand first, then in Marketing settings, so a site whose values haven't been moved to Marketing settings yet reads them as before.

To change how a value is worked out, extend `SiteSeo` and bind your class in a service provider; see [developers.md](developers.md#change-a-rule-siteseo). (`class` in this file, the way before, still works until 1.0.)

### Titles and descriptions

| Key | Default | |
|---|---|---|
| `title.max` | `60` | With **Add the site name to page titles** on, `{title}{separator}{site name}` is used only if it fits; otherwise the title alone. |
| `description.length` | `160` | A description taken from the page is cut to this, on a word, `…` included. |

These two shape what the site prints. The report's title and description checks, and the counters in the entry's preview, have their own limits under **Marketing → Reports → Settings**, for editors to change; the defaults are the same, 60 and 160, so a generated title or description passes its check.

### Collections

`collections` maps a collection handle to its rules. Every key is optional.

```php
'collections' => [
    'essays' => [
        'og_type' => 'article',            // og:type (default 'website')
        'schema' => 'Article',             // adds an Article, NewsArticle or BlogPosting node
        'page_schema' => 'WebPage',        // the WebPage node's type (CollectionPage, ProfilePage: about the entry, as a Person)
        'title_fields' => ['seo_title'],   // tried before the title for <title> and og:title (the site name still added); text or Bard
        'description_fields' => ['intro'], // tried before the body's first paragraph; text or Bard (read as plain text)
        'image_fields' => ['hero'],        // tried before the generated card
        'faq_field' => 'faqs',             // a grid of question / answer rows (answer: Markdown, text or Bard) → FAQPage (valid markup; Google shows no FAQ results since 2026)
        'author_field' => 'authors',       // an entries or users field → the Article's authors (else the publisher)
        'product' => [                     // a Product + Offer from these fields (needs a price above 0 and a currency)
            'price_field' => 'price', 'availability_field' => 'in_stock', // a toggle, or InStock/PreOrder…
            'sku_field' => 'sku', 'gtin_field' => null, 'brand_field' => null, 'brand' => 'Acme',
            'currency' => null,            // else the Brand global's Shop currency
            'condition' => 'NewCondition',
        ],
        'og_template' => 'default',        // a key of og.templates
    ],
],
```

#### Fields in a page builder

`title_fields`, `description_fields`, `image_fields` and `faq_field` can name a field inside a Replicator's sets as `replicator.set.field`: the Replicator's handle, the set's type (`*` for any) and the field in the set. Statamic handles have no dots, so a plain name means what it always did.

A title or description field can be a text field or a Bard field, inline or not: Bard's text is read as plain text (its bold, italics and links dropped, a space between paragraphs, its sets left out).

```php
'pages' => [
    'description_fields' => ['sections.hero.lead'], // the first visible hero set with a lead
    'image_fields' => ['sections.hero.image'],      // the first visible hero set with a photo
    'faq_field' => 'sections.faq.faqs',             // the questions of every visible FAQ set, in the page's order
],
```

A set switched off in the control panel isn't on the page, so it counts for nothing.

### Taxonomies

`taxonomies` gives a taxonomy's term pages the same rules, by taxonomy handle: `og_type`, `page_schema`, `title_fields`, `description_fields`, `image_fields`, `faq_field` and `og_template`. A term has none of its collections' rules. A term page without an uploaded share image gets a generated card, as an entry does: its title and description, labelled with the taxonomy's name, at `/og/{uri}.png` (`/og/topics/gardens.png`).

```php
'taxonomies' => [
    'topics' => [
        'page_schema' => 'CollectionPage', // a listing of the topic's pages
        'description_fields' => ['intro'],
        'image_fields' => ['banner'],
    ],
],
```

Terms are listed in the sitemap (and checked by reports) when their taxonomy is in `sitemap.taxonomies` and they have published entries. Statamic counts only entries of the collections a taxonomy is attached to; when entries name their terms in a `terms` field of a taxonomy that isn't attached, override `termHasEntries()` in your `SiteSeo` subclass.

### Robots

| Key | Default | |
|---|---|---|
| `robots.noindex_outside_production` | `true` | Every page is `noindex` unless `APP_ENV=production`, so staging copies stay out of search results. |
| `robots.noindex_params` | `[]` | A request with any of these query parameters is noindexed (filtered or sorted listings), e.g. `['sort', 'tag']`. |
| `robots.noindex_routes` | `[]` | Route names to noindex, e.g. `['thank-you']`. |
| `robots.default` | `'max-snippet:-1, max-image-preview:large, max-video-preview:-1'` | The robots tag on pages that are indexed. |

### Sitemap and robots.txt

| Key | Default | |
|---|---|---|
| `sitemap.enabled` | `true` | Serves `/sitemap.xml`. |
| `sitemap.collections` | `null` | `null`: every collection with a route. Or a list of handles. |
| `sitemap.exclude_collections` | `[]` | Left out even when `collections` is `null`. |
| `sitemap.taxonomies` | `[]` | Taxonomies whose terms are listed (only terms with published entries). Reports check these terms too. |
| `sitemap.per_page` | `1000` | Above this, `/sitemap.xml` becomes an index of `/sitemap_1.xml`, `/sitemap_2.xml`… |
| `robots_txt.enabled` | `true` | Serves `/robots.txt` from the Crawlers tab of Marketing settings. A real `public/robots.txt` wins: the web server answers with it first. A new Statamic site has one; delete it (`mt:install` offers to). |

The sitemap lists only canonical addresses: it leaves out drafts, redirect entries, noindexed pages, pages whose canonical points to another page (on this site or another), and pages with "In sitemap" off. It's cached and rebuilt when content is saved or deleted, when a collection, taxonomy or page tree is saved, when `marketing-toolkit.sitemap` or `marketing-toolkit.hreflang` changes, when the Stache is cleared (as a deploy does, so changed rules show at once), and when a scheduled entry's date arrives (that needs Laravel's scheduler running, as Statamic's scheduled entries do). On a site whose URL is relative (`url: '/'`), the addresses take their domain from the request, so only requests on a host the install names (an absolute site URL's, else `APP_URL`'s) are cached: set `APP_URL` to the live address, or the sitemap is built afresh for every request.

### Redirects and the 404 log

| Key | Default | |
|---|---|---|
| `redirects.enabled` | `true` | Applies the rules under Marketing → Redirects. |
| `redirects.automatic` | `true` | Adds a 301 when published content moves (slug, date, place in a tree). |
| `redirects.case_sensitive` | `true` | `false` matches a redirect's From in any letter case, accents and other alphabets included: `/ABOUT-US` and `/About-Us` as `/about-us`, `/CAFÉ` as `/café`. What a `*` matched keeps the visitor's case. Two redirects whose From differs only in case are then refused as the same address, and a CSV row updates the redirect with that From in any case. For a site moved off one whose addresses worked in any case (Wix, IIS). |
| `not_found.enabled` | `true` | Logs 404s. |
| `not_found.max_rows` | `1000` | Most paths kept. Laravel's `model:prune` trims the log once a day through the scheduler; without the scheduler, the log is trimmed now and then as paths are logged, and may run over by about a tenth. One-off misses (one hit, no page linking there) go first, then the least recently seen, so a flood of made-up addresses can't push out real broken links. |
| `not_found.ignore_user_agents` | bots, crawlers, curl, wget… | Not logged when the user agent contains one of these (any case). |
| `not_found.ignore_paths` | `*.php`, `/wp-*`, `/.env*`, `/.git*`… | Not logged when the path matches one (`*` matches anything). Scanner probes, mostly. |


### Languages (hreflang)

With several sites (Statamic Pro), a page links to itself in each other language: a `<link rel="alternate" hreflang>` tag per language in the `<head>`, an `og:locale:alternate` for each, and the same links as `<xhtml:link>` in the sitemap. The languages of one page are its entry's origin and the entries localized from it, or a term on each site of its taxonomy that has entries there. A version that is a draft, noindexed, left out of the sitemap or canonical elsewhere is left out; a page that is one of those itself gets no tags. The code is the site's language (`fr`), or its full locale (`en-GB`, `en-US`) where two sites share a language.

| Key | Default | |
|---|---|---|
| `hreflang.enabled` | `true` | Off: no hreflang tags or sitemap links. Turn it off for sites that are separate brands rather than languages, whose terms would otherwise point at each other. |
| `hreflang.x_default` | `null` | The site whose version is `x-default`, what everyone else gets: `null` for the default site, a site handle, or `false` for none. |

A sitemap lists every site on its domain: languages under `/fr/` are in `example.com/sitemap.xml`; a language on its own domain has its own.

### IndexNow

| Key | Default | |
|---|---|---|
| `indexnow.enabled` | `true` | When published content is saved, goes live on schedule, is unpublished or is deleted, its address is sent to IndexNow, and when it moves, its old address too (with its automatic redirect) (Bing, Yandex, Naver, Seznam and others; not Google) once the request has been answered. Production only; a failure is logged. |
| `indexnow.key` | `null` (`MT_INDEXNOW_KEY`) | The key served at `/{key}.txt`. Left empty, it is derived from `APP_KEY`, so it stays the same across deploys. |

### Google Search Console

Clicks, impressions, click-through rate and average position per page, imported daily and shown on Marketing → Overview. Off until there is a key and a property.

**From the control panel**: **Marketing → Search Console** walks whoever may change the addon's settings through it: the Google Cloud and Search Console steps with their links, uploading the key, the property (the site's domain is suggested), a check that turns Google's refusals into what to fix, and the first import. The key is kept in `storage/app/private/marketing-toolkit/search-console-key.json`, encrypted with `APP_KEY` (never in git; on a deployed site, keep `storage` between releases, as Laravel expects; if `APP_KEY` changes, upload the key again), the property as the addon setting `search_console_property`. **From `.env`**, as below; a value there wins and the control panel shows it without changing it.

| Key | Default | |
|---|---|---|
| `search_console.credentials` | `MT_SEARCH_CONSOLE_CREDENTIALS` | A service account's JSON key, or the path to the file. |
| `search_console.property` | `MT_SEARCH_CONSOLE_PROPERTY` | The property as Search Console names it: `sc-domain:example.com` for a domain property, `https://example.com/` for a URL prefix. With several sites, a string is every site's; in `config/marketing-toolkit.php` it can be a map, `['default' => 'sc-domain:example.com', 'shop' => 'sc-domain:shop.example']`. |
| `search_console.days` | `28` | The period imported, ending today (Pacific time, as Search Console counts). |

Setting it up:

1. In [Google Cloud](https://console.cloud.google.com/), create a project (or use one), enable the **Google Search Console API**, and create a **service account** with a **JSON key**.
2. In [Search Console](https://search.google.com/search-console), open the property → Settings → Users and permissions, and add the service account's email (`…@….iam.gserviceaccount.com`) as a **Restricted** user.
3. Put the key on the server (outside the web root) and set `MT_SEARCH_CONSOLE_CREDENTIALS=/path/to/key.json` and `MT_SEARCH_CONSOLE_PROPERTY` in `.env`.
4. Run `php please mt:search-console` once (or Import now on Marketing → Search Console); the schedule then runs it daily at 04:30 (Laravel's scheduler must be running). It is in the schedule (`php artisan schedule:list`) once a key and a property are set.

**Can't create a key, or Google says it is disabled?** New Google Cloud projects often have service account keys blocked by an organization policy, `iam.disableServiceAccountKeyCreation`. Someone who administers the organization can allow keys for the project in [Organization policies](https://console.cloud.google.com/iam-admin/orgpolicies/iam-disableServiceAccountKeyCreation). A key that exists but is disabled can be [enabled again](https://docs.cloud.google.com/iam/docs/keys-disable-enable); the check on the Search Console screen says when Google reports a disabled key or account.

### Share cards and images

| Key | Default | |
|---|---|---|
| `og.enabled` | `true` | Generated cards at `/og.png` (home) and `/og/{uri}.png`, for entries and taxonomy term pages. |
| `og.templates` | `['default' => DefaultTemplate::class]` | Card designs by key; see [developers.md](developers.md#add-a-share-card-template). |
| `og.logo_position` | `'top'` | Where the default card puts the brand: `'top'` or `'bottom'` (left-aligned either way). |
| `og.max_age` | 30 days | `Cache-Control` max-age of the card images. |

Each generated card comes in three shapes, at the card's address with a suffix:

| Shape | Size | Address |
|---|---|---|
| Landscape | 1200×630 | `/og/{uri}.png`, `/og.png`; the page's `og:image` |
| Square | 1200×1200 | `/og/{uri}.1x1.png`, `/og.1x1.png` |
| 4:3 | 1200×900 | `/og/{uri}.4x3.png`, `/og.4x3.png` |

The suffix is part of the path, not a query string, because static caches and CDNs often drop query strings. An Article or Product node without an uploaded image lists all three shapes the page's template draws; a template of your own draws the landscape card alone until it lists more ([developers.md](developers.md#add-a-share-card-template)).

The default card carries the brand at the top left: Brand's share-card **Logo**, else the publisher logo, else the name in page titles in the accent colour. A logo is fitted whole in a box 40% of the card's width and 12% of its height and never enlarged past its own pixels; one that would come out shorter than 32 pixels on the landscape card (a very wide wordmark) is left out for the name. SVG logos are refused (most hosts' Imagick can't read SVG); upload a PNG, ideally with a transparent background. A logo that stands out from the card's background by less than 3:1 is drawn on a rounded pill in the text colour (or its complement, whichever contrasts more).

Uploaded share images are cropped to 1200×630 and served as JPEG; for another size, override `imageWidth()` and `imageHeight()` in your `SiteSeo` subclass.

## Features

The **Features** tab of **Marketing → Settings** has a switch per module, on the default site and for whoever may change the addon's settings: the sitemap, robots.txt, llms.txt, hreflang, IndexNow, generated share cards, redirects, redirects when a page moves, the 404 log, scheduled reports, tracking and Consent Mode, leads, favicons, ads.txt and the front-end toolbar. What's off is copied to the addon settings (`features_off`) when the tab is saved and set off in the config at boot: its addresses answer 404, and its listeners and middleware aren't loaded, so it costs nothing on a request. Nothing it saved is deleted. Its routes stay registered, so cached routes (`php artisan route:cache`, `optimize`) follow a switch without being cached again. A route of the site's own for one of the addresses (its own `sitemap.xml`, say) keeps it: the addon then doesn't register its route there.

Since the switches apply at boot, a process that boots once and serves many requests or jobs (Laravel Octane, a queue worker, Horizon) picks up a change when it restarts: run `php artisan octane:reload` or `php artisan queue:restart` after switching a module on or off. A PHP-FPM site picks it up from the next request.

Each switch sets the matching key below to off, whatever `config/marketing-toolkit.php` says. A module `config/marketing-toolkit.php` already switches off shows off on the screen, locked, with "Off in config/marketing-toolkit.php": a switch can't turn it back on.

## Tracking

Each ID can be set in the **Tracking** tab of Marketing settings (Marketing → Settings), or here, which wins (and shows as "set in .env" on Marketing → Overview). Each key is the field's handle in the Tracking tab, and in `.env` it is `MT_` and the key in capitals. Every `.env` name the addon reads starts with `MT_`; the names up to 0.19, `SEO_…`, are read too until 1.0. See [tracking.md](tracking.md).

| Key | `.env` | |
|---|---|---|
| `tracking.gtm_id` | `MT_GTM_ID` | Google Tag Manager container, `GTM-XXXXXXX`. |
| `tracking.ga4_id` | `MT_GA4_ID` | Google Analytics 4 measurement ID, `G-XXXXXXXXXX`. |
| `tracking.posthog_key` | `MT_POSTHOG_KEY` | PostHog project API key, `phc_…`. |
| `tracking.posthog_host` | `MT_POSTHOG_HOST` | PostHog's API host: `https://eu.i.posthog.com` for an EU project; `https://us.i.posthog.com` unless set. A [reverse proxy](https://posthog.com/docs/advanced/proxy) of your own (`https://t.example.com`) works too. |
| `tracking.posthog_ui_host` | `MT_POSTHOG_UI_HOST` | With a proxy as the host: PostHog's app, `https://us.posthog.com` or `https://eu.posthog.com`, for its toolbar and links back to it. On PostHog's own hosts it is worked out. |
| `tracking.meta_pixel_id` | `MT_META_PIXEL_ID` | Meta Pixel ID (digits). |
| `tracking.linkedin_partner_id` | `MT_LINKEDIN_PARTNER_ID` | LinkedIn Insight Tag partner ID (digits). |
| `tracking.enabled` | | `true`. Off: no tags, Consent Mode or leads. |
| `tracking.environments` | | `['production']`: the environments the tags print in. Never in Live Preview. |
| `leads.enabled` | | `true`. Off: no form submission is sent as a lead or saved with where it came from. See [tracking.md](tracking.md#leads). |

An ID that doesn't look like one (`GTM-` and letters or digits, and so on) is never printed; Marketing → Overview says which one, and where it is set. Consent Mode is set in the global only (the Consent tab of Marketing settings).

## Toolbar

| Key | Default | |
|---|---|---|
| `toolbar.enabled` | `true` | The front-end toolbar for signed-in control panel users. Off (here or under Features): no script on the page, no cookie, and its endpoint answers 404. |

`<s:mt:body />` ends with a script of about 300 bytes, the same for every visitor, so a page stays safe to cache under every static caching strategy. It loads the toolbar (`/vendor/statamic-marketing-toolkit/build/toolbar.js`, about 7 kB gzipped) only when the `mt_toolbar` cookie is there and the page isn't in a frame; visitors download nothing else and make no request. A layout without `mt:body` adds `<s:mt:toolbar />` before `</body>`. The script isn't printed in Live Preview or by `ssg:generate`.

With a Content Security Policy whose `script-src` doesn't allow inline scripts, add the script's hash, `'sha256-ER0DYGxHgRaNqwj+0P+VzXfNCbXGqSELhjDw67SNG8I='`. The addresses it loads are attributes of its tag, so its code, and the hash, is the same on every site and stays the same across updates; the changelog says if it ever changes. A nonce works too (Vite's, `Vite::useCspNonce()`), but not on statically cached pages, which keep the nonce of the response they stored. The toolbar's own script is a file on the site (`'self'`) and talks only to the site (`connect-src 'self'`); its styles are a constructed stylesheet its shadow root adopts, which a policy doesn't govern, so it needs nothing in `style-src`, not even with a nonce-only one. It needs a browser with constructable stylesheets, which every browser Statamic 6 supports has (Safari from 16.4).

The cookie is set when someone who may access the control panel signs in, and on their control panel requests, and removed when they sign out. It holds `1` and nothing else: the toolbar then asks `/!/marketing-toolkit/toolbar` about the page, which checks the session and each permission. It is set on the session's domain (`SESSION_DOMAIN`) for the session's lifetime. If the control panel is on another domain than the site (`admin.example.com` and `www.example.com`), set `SESSION_DOMAIN=.example.com` so one sign-in covers both; on unrelated domains, sign in on each.

Its corner (any of the four) and whether it is hidden are set in the toolbar's own **Toolbar settings** panel (the gear) and kept in each browser; the server keeps nothing of them. **Alt+Shift+M** opens and closes it. A hidden toolbar makes no request until that shortcut brings it back.

## llms.txt and ads.txt

| Key | Default | |
|---|---|---|
| `llms_txt.enabled` | `true` | `/llms.txt` ([llmstxt.org](https://llmstxt.org)): the site's name and default description, then, for each collection the sitemap lists, its 100 most recently changed pages as Markdown links with their descriptions. Like the sitemap, it lists every site on the domain, so sites under a folder (`/fr/`) get their own sections. Cached until content or Brand changes. Override `llmsTxt()` in your `SiteSeo` subclass to write it differently. |
| `ads_txt.enabled` | `true` | `/ads.txt`: the lines in **Marketing → Settings → Crawlers → ads.txt**; a 404 while that's empty. |

A file of the same name in `public/` wins over either.

## Favicons

| Key | Default | |
|---|---|---|
| `favicons.enabled` | `true` | Make the icons from **Icon** in Marketing → Brand, serve them, and print their links in `<s:mt:head />` (or `<s:mt:favicons />`). |

From one image the addon makes `/favicon.ico` (16, 32 and 48 px), `/favicon.svg` (an SVG upload, as it is), `/apple-touch-icon.png` (180 px, on the icon background), `/icon-192.png`, `/icon-512.png` and `/site.webmanifest` (the site's name, short name and colours). They're made once per version of the image and colours, kept in `storage/app/marketing-toolkit/favicons`, made again when Brand is saved, and served without a session or cookie. A file of the same name in `public/` wins, so delete old ones there. Drawing SVG needs PHP's Imagick; with GD alone an SVG gives `/favicon.svg` and the manifest, so upload a PNG on such hosts.

## Report settings

The **Settings** tab of **Marketing → Reports**, for whoever may change the addon's settings. They are Statamic's addon settings for Marketing Toolkit, saved as YAML in `resources/addons/marketing-toolkit.yaml` (or wherever your site stores addon settings). On a site where the production control panel is where content lives, keep that file out of deploys, or store addon settings in the database, so a deploy doesn't overwrite them.

**Checks** tab:

| Setting | Default | |
|---|---|---|
| A switch per check | all on | A check that's off is left out of the reports and the scores. |
| Title: at least / at most | 30 / 60 | Thresholds for the title check and the preview's title counter. |
| Description: at least / at most | 50 / 160 | The same for descriptions. |

**Running** tab:

| Setting | Default | |
|---|---|---|
| Leave out these collections | none | Their entries aren't checked. |
| Most pages per report | 0 (all) | Stops after this many pages. |
| Pages per step | 25 | Pages one step renders. Lower it if a step times out. |
| Reports to keep | 10 | Older reports are deleted when a new one finishes. |
| Run a report | Only by hand | Or daily or weekly, on the day and at the time you choose (app timezone). Needs the scheduler. `marketing-toolkit.reports.enabled` (or the Features switch) off stops the schedule. |

**On a queue worker** (`QUEUE_CONNECTION` other than `sync`), each step of a report is one job. A step takes no new page after 45 seconds, and the job times out after 75. Keep your queue connection's `retry_after` (in `config/queue.php`) above 75 seconds; Laravel's default is 90. With a lower `retry_after`, the queue hands a step that is still running to a second worker, and the report is checked twice at once.

The Search Console property is kept here too (`search_console_property`, and `search_console_properties` for the other sites), but set on **Marketing → Search Console**. `MT_SEARCH_CONSOLE_PROPERTY` wins over them.

## Permissions

| Permission | |
|---|---|
| `view marketing toolkit` | Marketing → Overview, reports, the 404 log, the Search Console screen (changing the connection needs permission to change the addon's settings), the widget. |
| `manage marketing toolkit redirects` | Create, edit and delete redirects, import and export them, delete 404 rows, and the "add a redirect?" question when saving. |
| `run marketing toolkit reports` | Start a report. |

The front-end toolbar shows for anyone with `access cp`, and each of its panels asks the permission above that its screen asks; **Refresh this page's cache** asks Statamic's `access cache utility`. It shows a page's entry or term (its title, status, SEO and report) only to someone who may view it in the control panel (Statamic's `view {collection} entries` or `view {taxonomy} terms`, on a site they may access), and a site's report, redirects, 404s and tracking only on a site they may access.

## Commands

| Command | |
|---|---|
| `php please mt:install [--container=]` | Creates the Brand and Marketing settings global sets and their blueprints, and fills their empty fields with what the site uses (the home page's description, the robots.txt rule). Run again, it adds what is missing, such as the fields a newer version brings (in the tabs the blueprint still has), and never overwrites a value. With several sites, offers to enable an existing set on the sites it's missing from. Names any file in `public/` that would be served instead of the addon's, and offers to delete it. |
| `php please mt:install --tab=shop` | Adds a whole tab the blueprint doesn't have (`shop`, `publisher`, `tracking`…), to whichever of the two sets it belongs to. |
| `php please mt:install --forms` | Adds the lead source fields to every form; see [tracking.md](tracking.md#leads). |
| `php please mt:search-console [--site=]` | Imports the last period's numbers from Google Search Console. With several sites, each site that has a property, or only `--site`. |
| `php please mt:report [--site=]` | Runs a whole report in the terminal and prints the scores. Continues a report that's already running. With several sites, one report per site in turn, or only `--site`; the schedule runs one per site. |

## Laravel Boost

For AI coding agents. With [Laravel Boost](https://github.com/laravel/boost) installed (`composer require laravel/boost --dev`), `php artisan boost:install` lists the addon among the packages with guidelines and skills:

- **Guidelines** (`resources/boost/guidelines/core.blade.php`): how the addon fits a site, and what not to hand-write (meta tags, JSON-LD, sitemaps, redirect routes, tracking scripts).
- **Skills**: `marketing-toolkit-seo-rules` (pointing the addon at a collection's fields) and `marketing-toolkit-extend` (a `SiteSeo` subclass, a share-card template).
- **MCP tools**, added to Boost's own MCP server beside its tools: `marketing-toolkit-page-seo` (a page's title, description, canonical, robots, share image, alternates, JSON-LD types and sitemap status), `marketing-toolkit-report` (the latest SEO report's score and failing checks) and `marketing-toolkit-redirect` (which redirect answers a path). They only read.

| Key | Default | |
|---|---|---|
| `boost.tools` | `true` | Adds the MCP tools to Boost's server (through `boost.mcp.tools.include`). Set to `false` to leave them out; Boost's own `boost.mcp.tools.exclude` works too. |

Nothing here runs without Boost, and nothing is sent anywhere: the tools answer the agent from the site's own data.

## What it sends where

The addon sends nothing to its author: no licence check, no usage numbers, no updates check. The server or the visitor's browser talks to another service only for a feature that is on and set up:

| When | From | To | What is sent |
|---|---|---|---|
| Published content is saved, goes live, moves, is unpublished or is deleted (IndexNow on, production only) | The server | `https://api.indexnow.org/indexnow` | The site's host, the IndexNow key, where the key file is, and the changed addresses. |
| A Search Console check or import (with a key and a property) | The server | `https://oauth2.googleapis.com/token`, then `https://www.googleapis.com/webmasters/v3/sites/…` | A token request signed with the service account key (its email, the read-only Search Console scope, an expiry; the private key itself never leaves the server); then the property, and for an import the date range and which rows. |
| A report runs with **Check external links** on (off unless switched on in the report settings) | The server | The sites the pages link to | A `HEAD` request (a `GET` where `HEAD` is refused) for each linked address, at most 50 a page, each answer kept for a day. Addresses on this machine or a private network are never asked. |
| A visitor opens a page, with a tracking ID set, in the environments in `tracking.environments` (production unless changed), never in Live Preview | The visitor's browser | Google Tag Manager and Google Analytics (`googletagmanager.com`), the Meta Pixel (`connect.facebook.net`, `facebook.com`), LinkedIn (`snap.licdn.com`, `px.ads.linkedin.com`), PostHog (your `tracking.posthog_host`) | Whatever each tool's own script collects, and with leads on, a lead event when a form is sent. How each behaves before consent: [tracking.md](tracking.md#meta-linkedin-and-posthog-without-gtm). |

Reports render pages and check internal links inside the application, without a request over the network.

The front-end toolbar talks only to the site itself. Its `mt_toolbar` cookie, set for signed-in control panel users only, holds `1`, so it carries nothing about the user; the toolbar's open or closed state, and what it draws while a page loads (the user's corner, theme, labels and which items they had, nothing about a page), are kept in the browser's `localStorage` and never sent; they are read only while the cookie is there.
