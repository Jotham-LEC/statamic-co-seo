<?php

use JothamLec\MarketingToolkit\Og\DefaultTemplate;

/*
 * Until 1.0, every environment variable below is also read under the name it
 * had up to 0.19, with SEO_ in place of MT_ (SEO_GTM_ID for MT_GTM_ID, and so
 * on). The `env('SEO_…')` defaults do this. Rename them in .env before 1.0,
 * which drops them.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Brand and defaults
    |--------------------------------------------------------------------------
    |
    | These are the two global sets editors fill in, each with its own values
    | per site (create them with `php please mt:install`). `global` is Brand,
    | which holds the title separator, the default description and image, the
    | publisher for JSON-LD, the shop and the share-card colours.
    | `settings_global` is Marketing settings, which holds tracking tags,
    | Consent Mode, leads, verification codes and robots.txt. The site's name
    | is Statamic's own (from Settings → Sites, or else APP_NAME).
    |
    */

    'global' => 'seo',

    'settings_global' => 'marketing',

    'title' => [
        // When "Add the site name to page titles" is on (in Brand), the title
        // becomes "{title}{separator}{site name}" only when the result fits.
        // The report's title check uses its own limit, but the defaults match.
        'max' => 60,
    ],

    'description' => [
        // A description taken from the page is cut to this length, at a word
        // boundary. The report's checks and the preview's counters use their own
        // limits (in Marketing → Reports → Settings), but the defaults match.
        'length' => 160,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | Each collection is configured by its handle, and all keys are optional:
    |
    |   'og_type' => 'article',            // og:type; default 'website'
    |   'schema' => 'Article',             // adds an Article / NewsArticle / BlogPosting node
    |   'page_schema' => 'WebPage',        // the WebPage node's type (CollectionPage, ProfilePage: about the entry, as a Person)
    |   'title_fields' => ['seo_title'],   // tried before the title for <title> and og:title (the site name still added)
    |   'description_fields' => ['intro'], // tried before the body's first paragraph
    |   'image_fields' => ['hero'],        // tried before the generated card
    |   'faq_field' => 'faqs',             // a grid of question / answer (Markdown, text or Bard) → FAQPage (valid markup; Google shows no FAQ results since 2026)
    |   'author_field' => 'authors',       // an entries or users field → the Article's authors (else the publisher)
    |   'product' => [                     // a Product + Offer from these fields (needs a price above 0 and a currency)
    |       'price_field' => 'price', 'availability_field' => 'in_stock', // a toggle, or InStock/PreOrder…
    |       'sku_field' => 'sku', 'gtin_field' => null, 'brand_field' => null, 'brand' => 'Acme',
    |       'currency' => null,            // else the Brand global's Shop currency
    |       'condition' => 'NewCondition',
    |   ],
    |   'og_template' => 'default',        // a key of og.templates
    |
    | title_fields, description_fields, image_fields and faq_field can name a field in a
    | Replicator's sets as 'replicator.set.field' (such as 'sections.hero.image', with
    | `*` for any set). Sets that are switched off are passed over.
    |
    */

    'collections' => [],

    /*
    |--------------------------------------------------------------------------
    | Taxonomies
    |--------------------------------------------------------------------------
    |
    | A taxonomy's term pages take the same rules, keyed by taxonomy handle:
    | og_type, page_schema (CollectionPage suits a listing), title_fields,
    | description_fields, image_fields, faq_field and og_template. A term page
    | gets a generated share card, as an entry does.
    |
    */

    'taxonomies' => [],

    /*
    |--------------------------------------------------------------------------
    | Robots
    |--------------------------------------------------------------------------
    */

    'robots' => [
        // This keeps staging and local copies out of search results.
        'noindex_outside_production' => true,

        // A request carrying any of these query parameters is noindexed
        // (filtered or sorted listings), e.g. ['sort', 'search', 'tag'].
        'noindex_params' => [],

        // These are route names to noindex, e.g. ['thank-you'].
        'noindex_routes' => [],

        'default' => 'max-snippet:-1, max-image-preview:large, max-video-preview:-1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap and robots.txt
    |--------------------------------------------------------------------------
    */

    'sitemap' => [
        'enabled' => true,

        // When this is null, the sitemap lists every collection that has a route.
        'collections' => null,

        'exclude_collections' => [],

        // These are the taxonomies whose terms are listed (only terms with published entries).
        'taxonomies' => [],

        // When there are more URLs than this, /sitemap.xml becomes an index of /sitemap_{n}.xml.
        'per_page' => 1000,

        // A Google News sitemap at /news-sitemap.xml, listed in robots.txt: the entries of these
        // collections published in the last two days. It answers 404 while none is named.
        'news' => [
            'collections' => [],

            // The publication's name as Google News knows it; the site's name when null.
            'publication' => null,
        ],
    ],

    'robots_txt' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Boost
    |--------------------------------------------------------------------------
    |
    | For AI coding agents. With Laravel Boost installed, `php artisan
    | boost:install` offers the addon's guidelines and skills, and its
    | read-only tools join Boost's MCP server: marketing-toolkit-page-seo (what
    | a page's <head> prints), marketing-toolkit-report (the latest SEO report)
    | and marketing-toolkit-redirect (which redirect answers a path). Set
    | `tools` to false to leave them out.
    |
    */

    'boost' => [
        'tools' => true,
    ],

    // /llms.txt lists the site's pages as Markdown for AI assistants (llmstxt.org).
    'llms_txt' => [
        'enabled' => true,
    ],

    // A page asked for with `Accept: text/markdown` is answered with its <main> as Markdown, for AI agents.
    'markdown' => [
        'enabled' => true,
    ],

    // /ads.txt serves the lines in Marketing settings → Crawlers, when there are any.
    'ads_txt' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Languages (hreflang)
    |--------------------------------------------------------------------------
    |
    | With several sites, a page links to itself in each other language its
    | entry (or term) is published in, using <link rel="alternate" hreflang>
    | tags and the same links in the sitemap. The code is the site's language
    | (`fr`), or its full locale (`en-GB`) where two sites share a language.
    | `x_default` names the site whose version everyone else gets. Use null
    | for the default site, a site handle, or false for none.
    |
    */

    'hreflang' => [
        'enabled' => true,
        'x_default' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | Redirect rules are managed under Marketing → Redirects, and they apply
    | only to addresses the site would answer with a 404. `driver` says where
    | they are kept: `file` (YAML in `directory`, one file per site, committed
    | with the site) or `eloquent` (the mt_redirects table). Hits are counted
    | in the database either way. See docs/configuration.md. `automatic` adds a
    | 301 when an entry's or a term's address changes (its slug, its date, or
    | its place in a tree). Setting `case_sensitive` to false matches a rule's
    | From in any letter case (`/ABOUT-US` as `/about-us`), which helps a site
    | moved off one whose addresses worked in any case (Wix, IIS).
    |
    */

    'redirects' => [
        'enabled' => true,

        'driver' => env('MT_REDIRECTS_DRIVER', 'file'),

        'directory' => base_path('content/redirects'),

        'automatic' => true,

        'case_sensitive' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | 404 log
    |--------------------------------------------------------------------------
    |
    | The log keeps one row per missing path, under Marketing → 404s. Requests
    | from these user agents (matched case-insensitively, anywhere in the
    | string) and to these paths (`*` matches anything) are not logged.
    |
    */

    'not_found' => [
        'enabled' => true,

        'max_rows' => 1000,

        'ignore_user_agents' => [
            'bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
            'go-http-client', 'headlesschrome', 'lighthouse', 'facebookexternalhit',
        ],

        'ignore_paths' => [
            '*.php', '*.asp', '*.aspx', '*.cgi', '/wp-*', '/wordpress*', '/.env*',
            '/.git*', '/.well-known/*', '/cgi-bin/*', '/vendor/*', '/xmlrpc*',
            '*.map',
            // Browsers and crawlers ask for these on their own. If they were logged,
            // at max_rows they would push the broken links out.
            '/favicon.ico', '/robots.txt', '/sitemap.xml', '/apple-touch-icon*', '/build/*',
            '*.js', '*.css', '*.png', '*.jpg', '*.jpeg', '*.gif', '*.svg', '*.webp', '*.ico',
            '*.woff', '*.woff2', '*.ttf', '*.eot',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IndexNow
    |--------------------------------------------------------------------------
    |
    | IndexNow tells Bing, Yandex and the other IndexNow engines (not Google)
    | which addresses changed when published content is saved or deleted, in
    | production only. The key is served at /{key}.txt, and when it is left
    | null, it is derived from APP_KEY.
    |
    */

    'indexnow' => [
        'enabled' => true,
        'key' => env('MT_INDEXNOW_KEY', env('SEO_INDEXNOW_KEY')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Search Console
    |--------------------------------------------------------------------------
    |
    | Marketing → Overview shows clicks, impressions, CTR and position per page,
    | imported daily (`php please mt:search-console`). `credentials` is a service
    | account's JSON key, or the path to it. `property` is the property as Search
    | Console names it, such as `sc-domain:example.com` or `https://example.com/`.
    |
    */

    'search_console' => [
        'credentials' => env('MT_SEARCH_CONSOLE_CREDENTIALS', env('SEO_SEARCH_CONSOLE_CREDENTIALS')),
        'property' => env('MT_SEARCH_CONSOLE_PROPERTY', env('SEO_SEARCH_CONSOLE_PROPERTY')),
        'days' => 28,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | <s:mt:head /> and <s:mt:body /> print Google Tag Manager, Google Analytics
    | 4, PostHog, the Meta Pixel and the LinkedIn Insight Tag. Set them in the
    | Tracking tab of Marketing settings, or here (.env), which wins. They print
    | only in these environments, and never in Live Preview.
    |
    */

    'tracking' => [
        'enabled' => true,
        'environments' => ['production'],
        // Each key is the field's handle in the Tracking tab, and MT_ + the key in .env.
        // SEO_ + the key, the name used up to 0.19, is also read until 1.0.
        'gtm_id' => env('MT_GTM_ID', env('SEO_GTM_ID')),
        'ga4_id' => env('MT_GA4_ID', env('SEO_GA4_ID')),
        'posthog_key' => env('MT_POSTHOG_KEY', env('SEO_POSTHOG_KEY')),
        'posthog_host' => env('MT_POSTHOG_HOST', env('SEO_POSTHOG_HOST')),
        // When posthog_host is a proxy, set this to PostHog's app (https://eu.posthog.com) for its toolbar.
        'posthog_ui_host' => env('MT_POSTHOG_UI_HOST'),
        'meta_pixel_id' => env('MT_META_PIXEL_ID', env('SEO_META_PIXEL_ID')),
        'linkedin_partner_id' => env('MT_LINKEDIN_PARTNER_ID', env('SEO_LINKEDIN_PARTNER_ID')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leads
    |--------------------------------------------------------------------------
    |
    | Form submissions are sent to your tools as leads, and where each lead
    | came from is saved with its submission, as set in the Leads tab of
    | Marketing settings. When this is off here (or under Features), neither
    | happens.
    |
    */

    'leads' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Favicons
    |--------------------------------------------------------------------------
    |
    | The addon makes /favicon.ico, /favicon.svg, /apple-touch-icon.png,
    | /icon-192.png, /icon-512.png and /site.webmanifest from the icon in
    | Brand, and prints their <link> tags in <s:mt:head />. A file of the same
    | name in public/ wins.
    |
    */

    'favicons' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end toolbar
    |--------------------------------------------------------------------------
    |
    | The toolbar is a small bar on the site for signed-in control panel users.
    | It shows the page's score and failing checks, its search and share
    | preview, redirects and 404s, tracking and consent, and its other sites.
    | <s:mt:body /> prints the same tiny script for every visitor, which loads
    | the toolbar only when the `mt_toolbar` cookie says a control panel user
    | is signed in, so pages stay safe to cache. Each user can hide or move it
    | under Preferences.
    |
    */

    'toolbar' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | Which checks a report runs, their thresholds and its schedule are set
    | under Marketing → Reports → Settings. When this is off here (or under
    | Features), no report runs on the schedule, but one can still run by hand.
    |
    */

    'reports' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated Open Graph images
    |--------------------------------------------------------------------------
    |
    | A page without an uploaded share image gets a card drawn at
    | /og/{uri}.png (or /og.png for the home page) by simonhamp/the-og. Editors
    | change the card's text per entry in the SEO fieldset, or replace it
    | outright with an uploaded image. A collection picks a template with
    | `og_template`. Each card comes in three shapes: 1200×630 (the og:image),
    | 1200×1200 at /og/{uri}.1x1.png and 1200×900 at /og/{uri}.4x3.png.
    |
    | The default card carries the brand: Brand's share-card Logo, else the
    | publisher logo, else the name in page titles. `logo_position` puts it at
    | the 'top' or the 'bottom' of the card.
    |
    */

    'og' => [
        'enabled' => true,

        'templates' => [
            'default' => DefaultTemplate::class,
        ],

        'logo_position' => 'top',

        'max_age' => 60 * 60 * 24 * 30,

        // The cache store that keeps the drawn cards, or null for the default store. A card is an
        // image of about 100 KB in each of three shapes, so a site whose default store is its
        // database may keep them in a file or Redis store instead.
        'cache_store' => null,
    ],

];
