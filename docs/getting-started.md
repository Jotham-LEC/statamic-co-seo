# Getting started

[![Heart Marketing Toolkit on the Statamic Marketplace](https://img.shields.io/badge/%E2%99%A5%20Heart%20Marketing%20Toolkit-on%20the%20Statamic%20Marketplace-ff269e?style=for-the-badge)](https://statamic.com/creators/jothamlec)

From nothing to a site with meta tags, a sitemap, share cards, redirects and reports. Allow about twenty minutes. If the site already has its SEO done by hand or with another add-on, follow [Moving an existing site](migrating.md) instead.

## What you need

- **Statamic 6.34+** (Core is enough) on **PHP 8.3+**, with the PHP extensions in [the README's requirements](../README.md#requirements). Several sites and languages need Statamic Pro, as Statamic itself does; see [Several sites and languages](developers.md#several-sites-and-languages).
- **PHP's `imagick` extension** for the generated share cards. Without it pages use their uploaded or default share image instead of a card, and favicons are drawn with `gd`, from a PNG or JPEG only; everything else works.
- **A database** Laravel can migrate. The 404 log, reports and redirect hit counts live in tables, even on a flat-file site; SQLite is fine. Redirect rules are files in `content/redirects/` by default (or a table; see [configuration.md](configuration.md#where-redirects-are-kept)).
- **A cache store that serializes**: `file`, `redis`, `database` or `memcached`. Not `array`: automatic redirects need to compare an entry with the copy loaded before it was edited, and the `array` store hands back the same object.

Everything is included: Marketing Toolkit is free and open source, with no editions and no licence key.

## 1. Install the package

```bash
composer require jotham-lec/statamic-marketing-toolkit
php artisan migrate                          # mt_redirects, mt_redirect_hits, mt_404s, mt_reports, mt_report_pages, mt_search_stats
php please mt:install                       # the "Brand" and "Marketing settings" global sets
php artisan vendor:publish --tag=marketing-toolkit-config  # optional: config/marketing-toolkit.php, to change the defaults
```

`mt:install` uses the first asset container for the logo, default image and icon; pass `--container=handle` to choose another. It also fills the empty fields with what the site already uses (the home page's description, the control panel kept out of robots.txt), so they show in the control panel ready to change. Running it again overwrites nothing: it adds only what is missing, such as the fields a newer version brings. With several sites it creates each set on every site, the others taking what they leave empty from the default site; for a set that already exists, it offers to enable it on the sites it's missing from.

**A new Statamic site has a `public/robots.txt` and an empty `public/favicon.ico`.** The web server answers with those files before the addon sees the request, so the addon's robots.txt (with its `Sitemap:` line) and icons never show. `mt:install` names them and offers to delete them, and Marketing → Overview marks them too. The same goes for `llms.txt`, `ads.txt` and the other icon files.

The control panel's scripts and styles are published to `public/vendor/statamic-marketing-toolkit` when Composer installs or updates the package. If the Marketing screens look unstyled, publish them yourself: `php artisan vendor:publish --tag=marketing-toolkit --force`.

## 2. Add the SEO fields to your blueprints

`mt:install` does this for each collection that has a route and no blueprint file yet, such as a new Statamic site's Pages: it creates the blueprint with an SEO tab (pass `--no-blueprints` to skip that). It never changes a blueprint you already have; it names those without the SEO fields instead, and running it again won't put back a tab you took out.

For those, add the fieldset where you want it in each blueprint whose pages should have SEO fields (usually every collection with a route), typically on its own tab. In the control panel: **Blueprints**, the collection's blueprint, **Add Tab** "SEO", then **Link Fieldset** and choose **SEO**.

Or in the blueprint's YAML (`resources/blueprints/collections/{collection}/{blueprint}.yaml`), as a tab beside the ones it has:

```yaml
tabs:
  seo:
    display: SEO
    sections:
      -
        fields:
          -
            import: marketing-toolkit::seo
```

This adds the **search and share preview** and one `seo` group: title, description, share image, card title and subtitle, canonical URL, noindex, nofollow, no snippet, snippet length, "in sitemap" and extra JSON-LD. Every field is optional; empty means "use the default". See [What each value falls back to](developers.md#what-each-value-falls-back-to). On a site in several languages, each language has its own values.

Already have your own `seo` group with `title`, `description` and `canonical`? Those keys are read as they are, so swapping your fieldset for `marketing-toolkit::seo` keeps existing data.

## 3. Print the tags in your layout

In the layout, remove your own `<title>`, description, canonical, Open Graph and JSON-LD tags, and any Google Tag Manager, Google Analytics, PostHog, Meta Pixel or LinkedIn snippets. Then add one tag in the `<head>`, right after `<meta charset>` and the viewport, and one right after `<body>`:

```blade
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <s:mt:head />
    …
</head>
<body>
    <s:mt:body />
```

or in Antlers, `{{ mt:head }}` and `{{ mt:body }}`.

`<meta charset>` must come first: browsers look for it in the first 1024 bytes, and the tracking scripts alone can take several thousand.

`mt:head` prints the Consent Mode defaults and tracking tags first, since Google's tags must load before anything else that uses them, then the meta tags. `mt:body` prints the tags' `<noscript>` fallbacks (Google Tag Manager's needs to be in the body). A site that only wants the meta tags can use `<s:mt:meta />` instead of `mt:head`.

Pages that aren't Statamic entries (a controller page, a 404 view) pass what they know to either:

```blade
<s:mt:head title="Contact" description="Write to us." />
<s:mt:head :canonical="false" status="404" />
```

All the parameters are in [developers.md](developers.md#the-tag).

## 4. Fill in Brand and Settings

In the control panel's **Marketing** section, open **Brand** for the title separator, the default description and share image, the icon, who publishes the site (organisation, local business or person), the shop and the share-card colours. Then open **Settings** for the tracking IDs, Consent Mode, leads, verification codes, robots.txt lines and ads.txt. [editors.md](editors.md#brand) explains each field.

## 5. Add the dashboard widget (optional)

In `config/statamic/cp.php`:

```php
'widgets' => [
    ['type' => 'mt', 'width' => 100],
],
```

It shows the latest report's score and the most recent 404s, to people with the `view marketing toolkit` permission.

## 6. Give people access

Super users see everything. For other roles, tick the permissions in the **Marketing** group under **Users → Roles**:

| Permission | Lets them |
|---|---|
| `view marketing toolkit` | open the Marketing overview, the reports, the 404 log, the Search Console screen and the widget |
| `manage marketing toolkit redirects` | create, edit and delete redirects, import and export them, and answer the "add a redirect?" question when saving |
| `run marketing toolkit reports` | start a report |

The search and share preview needs no SEO permission, only access to the entry.

## 7. Schedule reports (optional)

Reports can run daily or weekly (Marketing → Reports → Settings → Running). That needs Laravel's scheduler, as for any scheduled task:

```
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

## Check that it worked

- View a page's source: one `<title>`, a description, `<link rel="canonical">`, `og:` and `twitter:` tags, and a `<script type="application/ld+json">`.
- Open `/sitemap.xml` and `/robots.txt`, and `/og.png` (the home page's share card).
- Edit an entry: the SEO tab shows the Google result and the share cards, and they change as you type.
- With several languages: a translated page's source has a `<link rel="alternate" hreflang="…">` for each language, and the sitemap an `<xhtml:link>` for each.
- Visit a page that doesn't exist, then **Marketing → 404s**: the path is listed. (Visit it in a browser; `curl` counts as a bot and isn't logged.)
- **Marketing → Reports → Run report.** A few hundred pages take under a minute.

If something doesn't, see [troubleshooting.md](troubleshooting.md).

## After every update

```bash
composer update jotham-lec/statamic-marketing-toolkit
php artisan migrate
```

Statamic runs the addon's update scripts on `composer update` (or `php please updates:run`): they add the fields a new version brings to Brand and Marketing settings (a field you removed stays removed), and make any change it needs to your settings. Each runs only for the update that needs it. Commit the files they change (the blueprint in `resources/blueprints/globals`, the global set in `content/globals`). The control panel's scripts are republished at the same time. [CHANGELOG.md](../CHANGELOG.md) says what each version needs beyond that, under **Upgrading**.
