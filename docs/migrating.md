# Moving an existing site to Marketing Toolkit

[![Heart Marketing Toolkit on the Statamic Marketplace](https://img.shields.io/badge/%E2%99%A5%20Heart%20Marketing%20Toolkit-on%20the%20Statamic%20Marketplace-ff269e?style=for-the-badge)](https://statamic.com/creators/jothamlec)

This checklist is for a Statamic site that already has its SEO, redirects or tracking done some other way, whether by hand in its templates or with another add-on, and that should end up with Marketing Toolkit doing that work instead. Work through it on a branch and a copy of the site, not on the live site, and keep the old setup in place until the last step, so that you can compare the two and nothing is lost on the way.

If you use an AI coding agent, such as Claude Code, the [migration skill](agent-skill/README.md) walks it through the same checklist on your project. It inspects the site, proposes a plan, and asks before it changes or removes anything.

## 1. Before you start

- [ ] Work on a new git branch, with a copy of the database if the site has one.
- [ ] Note the Statamic and PHP versions. Marketing Toolkit needs Statamic 6.34 or later and PHP 8.3 or later (see the [requirements](../README.md#requirements)).
- [ ] List what the site does today for SEO: the add-ons it uses (SEO Pro, Advanced SEO, Aardvark SEO, Redirect, or others), the meta tags in its layouts, its sitemap and robots.txt, its icons, and any JSON-LD.
- [ ] List its tracking: Google Tag Manager, Google Analytics, PostHog, the Meta Pixel, LinkedIn, and the cookie banner, wherever their snippets live (a layout, a partial, or `.env`).
- [ ] Save a baseline of the live site, so that you can compare it afterwards. For ten or so pages of different kinds (home, a page, an article, a listing page, a 404), save the `<head>` of the rendered HTML. Also save `/sitemap.xml` and `/robots.txt`.
- [ ] Export the existing redirects, if any, to a spreadsheet.

## 2. Install the addon

- [ ] Run `composer require jotham-lec/statamic-marketing-toolkit`.
- [ ] Run `php artisan migrate`, which creates the tables for redirects, the 404 log and reports.
- [ ] Run `php please mt:install`, which creates the **Brand** and **Marketing settings** global sets.
- [ ] Delete `public/robots.txt`, `public/favicon.ico` and any other file in `public/` that the addon now serves, such as `sitemap.xml`, `llms.txt`, `ads.txt` or `site.webmanifest`. The web server answers with a file in `public/` before the addon sees the request, and `mt:install` names the ones it finds.

## 3. Move the SEO fields

- [ ] Add the SEO fieldset to each blueprint whose pages need SEO fields, usually as its own tab: `import: marketing-toolkit::seo` (see [getting started](getting-started.md#2-add-the-seo-fields-to-your-blueprints)).
- [ ] Map the old fields to the new ones. The addon reads one `seo` group per entry, with `title`, `description`, `image`, `og_title`, `og_subtitle`, `canonical`, `noindex`, `nofollow`, `nosnippet`, `max_snippet`, `sitemap` and `json_ld`.
- [ ] If the site already has an `seo` group with `title`, `description` and `canonical`, those values are read as they are, and nothing needs to move.
- [ ] If the values live elsewhere (another add-on's fields, or top-level fields such as `meta_title`), copy them into the `seo` group for every entry and term, on every site. Do a dry run first, and count the values before and after. Or leave them where they are: name a title field in the collection's `title_fields` and a description field in its `description_fields` (see [configuration.md](configuration.md#collections)). A title read that way gets the site name added; one in the `seo` group is the whole title, as typed.
- [ ] Where a collection keeps its summary or main image in a field of its own, name it in `config/marketing-toolkit.php` under `collections` (`description_fields`, `image_fields`), so that pages without an SEO description or image still get a good one.
- [ ] Set each collection's `schema`, `og_type` and other options in the same place, if the site had structured data for them.

## 4. Replace the templates' tags

- [ ] In each layout, remove the site's own `<title>`, meta description, canonical, Open Graph, Twitter, hreflang and JSON-LD tags, and the old add-on's tag.
- [ ] Add `<s:mt:head />` (or `{{ mt:head }}` in Antlers) right after `<meta charset>` and the viewport, and `<s:mt:body />` right after `<body>`.
- [ ] For pages that aren't Statamic entries, such as a controller's page or the 404 view, pass what the tag can't know, for example `<s:mt:head title="Contact" />` or `<s:mt:head status="404" />` (see [the tag](developers.md#the-tag)).
- [ ] If the site added structured data of its own, such as events or courses, move it into a `SiteSeo` subclass (see [change a rule](developers.md#change-a-rule-siteseo)) rather than printing it in the template.

## 5. Fill in Brand and Settings

- [ ] Under **Marketing → Brand**, set the separator, the default description and share image, the icon, and who publishes the site.
- [ ] Under **Marketing → Settings → Crawlers**, copy the verification codes, the robots.txt rules and the ads.txt lines from the old setup.
- [ ] On a site with several sites or languages, check each site's values with the site picker.

## 6. Move the redirects

- [ ] Save the old redirects as a CSV file with the columns `source`, `target`, `status` and `active`, and add a `site` column on a multi-site install.
- [ ] Import it under **Marketing → Redirects → Import CSV**, and check the rows it reports as skipped. It works on either driver; with redirects in files (the default), the import writes `content/redirects/`, so commit it.
- [ ] Turn rules that used regular expressions into wildcards (`/blog/*` to `/articles/$1`), or keep them in the web server's configuration.
- [ ] Remove any redirect routes from `routes/web.php` that the addon now handles.

## 7. Move the tracking

- [ ] Remove the old tracking snippets from the layouts.
- [ ] Enter the IDs under **Marketing → Settings → Tracking**, or set them in `.env` as `MT_GTM_ID`, `MT_GA4_ID` and so on.
- [ ] If Google Tag Manager loads Google Analytics or the Meta Pixel already, set only the Tag Manager ID, so that no visit is counted twice.
- [ ] If the site has a cookie banner, switch on Consent Mode under **Marketing → Settings → Consent**, and keep the banner as it is (see [tracking](tracking.md)).
- [ ] Under **Marketing → Settings → Leads**, decide whether form submissions are sent to your tools as leads.

## 8. Remove what the addon replaces

- [ ] Remove the old SEO and redirect add-ons with `composer remove`, together with their config files and published views.
- [ ] Remove the old SEO fields from the blueprints, but only once their values have been copied and checked.
- [ ] Remove the site's own sitemap, robots.txt and icon routes or controllers.
- [ ] Under **Marketing → Settings → Features**, switch off what the site doesn't use.

## 9. Check the result

- [ ] Compare the `<head>` of the same pages with the baseline you saved. The titles, descriptions, canonicals and share images should match, or be better on purpose.
- [ ] Compare `/sitemap.xml` and `/robots.txt` with the baseline.
- [ ] Run a report under **Marketing → Reports**, and fix what it finds.
- [ ] Test a few pages in Google's [Rich Results Test](https://search.google.com/test/rich-results) and a social share preview.
- [ ] Visit a few old addresses and check that they redirect.

## 10. Go live

- [ ] Commit the blueprints, the global sets and `config/marketing-toolkit.php`.
- [ ] Deploy, and run `php artisan migrate` on the server.
- [ ] Check that the live site runs with `APP_ENV=production`, because the addon asks search engines not to index any other environment.
- [ ] If the site keeps its globals or entries in a database, copy the migrated values there as well.
- [ ] Resubmit `/sitemap.xml` in Google Search Console, and connect Search Console under **Marketing → Search Console** if you'd like its numbers in the control panel.
- [ ] Keep an eye on **Marketing → 404s** for the first few weeks, and add a redirect for any old address that people still visit.
