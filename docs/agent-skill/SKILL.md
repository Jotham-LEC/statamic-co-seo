---
name: migrate-to-marketing-toolkit
description: Move an existing Statamic site's SEO, redirects, tracking, sitemap, robots.txt and icons to the Marketing Toolkit addon (jotham-lec/statamic-marketing-toolkit). Use when someone asks to install Marketing Toolkit on a site that already has SEO set up by hand or with another add-on (SEO Pro, Advanced SEO, Aardvark SEO, Redirect), or to replace those with it.
---

# Migrate a Statamic site to Marketing Toolkit

You are moving an existing Statamic site to Marketing Toolkit, without losing any SEO value, redirect or tracking ID on the way. The human checklist this follows is [docs/migrating.md](https://github.com/Jotham-LEC/statamic-marketing-toolkit/blob/main/docs/migrating.md); the addon's documentation is in the same folder, and it is the reference for every name used below.

## Ground rules

- Work on a new git branch. Never work on the live site or its production database.
- Keep the old setup working until the new one has been checked. Copy values before you remove anything, and remove nothing (fields, add-ons, files, routes) without the user's go-ahead.
- Ask before every step that changes content or deletes something, and show what it will change first. A data migration gets a dry run that reports counts, before the real run.
- Don't guess another add-on's field names or storage. Read its blueprints, config and stored data in this project, and say what you found.
- When something doesn't map cleanly, such as a custom JSON-LD block or a regular-expression redirect, stop and ask rather than dropping it.

## 1. Inspect the project

Read, and report back in a short summary:

- `composer.json`: the Statamic and PHP versions (the addon needs Statamic 6.34+ and PHP 8.3+), and any SEO, redirect, sitemap, favicon or tracking packages.
- The layouts and partials in `resources/views`: every `<title>`, meta description, canonical, Open Graph, Twitter, hreflang and JSON-LD tag, every tracking snippet, and the tags of other SEO add-ons.
- The blueprints in `resources/blueprints` and the fieldsets in `resources/fieldsets`: the fields that hold SEO values, and where (a group, top-level fields, another add-on's fieldset).
- How content is stored: flat files under `content/`, or a database (Eloquent driver), for entries, terms and globals.
- `routes/web.php`, `app/` and `config/`: hand-built sitemap, robots.txt, icon or redirect routes and controllers.
- `public/`: static `robots.txt`, `sitemap.xml`, `favicon.ico`, `llms.txt`, `ads.txt` or `site.webmanifest` files, which would be served instead of the addon's.
- Existing redirects: where they live (an add-on, the web server's config, routes) and how many there are.
- Whether the site has several sites or languages (`config/statamic/sites.php` or `resources/sites.yaml`).

## 2. Save a baseline

Before changing anything, start the site locally and save the rendered `<head>` of about ten pages of different kinds (home, a page, an article, a listing with pagination, a term, a 404), together with `/sitemap.xml` and `/robots.txt`. Keep them outside the project, for example in `/tmp/mt-baseline/`, and use them in step 5.

## 3. Propose a plan

Write a plan for the user, and wait for their go-ahead. It should contain:

- A field map: each old field (with its blueprint and handle) and the Marketing Toolkit field it becomes, inside the `seo` group: `title`, `description`, `image`, `og_title`, `og_subtitle`, `canonical`, `noindex`, `nofollow`, `nosnippet`, `max_snippet`, `sitemap` or `json_ld`. An existing `seo` group with `title`, `description` and `canonical` is read as it is. Top-level title and description fields (`meta_title`, `seo_title`) can stay where they are instead, named in the collection's `title_fields` and `description_fields`; a title read that way gets the site name added.
- Per-collection settings for `config/marketing-toolkit.php` under `collections`: `description_fields` and `image_fields` for fields that hold a summary or a main image, and `schema` and `og_type` where the site had structured data.
- Where each site-wide value goes: the separator, default description and image, icon and publisher go to the Brand global set, and the tracking IDs, consent settings, verification codes, robots.txt rules and ads.txt lines go to the Marketing settings global set.
- What happens to each redirect, each tracking snippet, each static file and each old add-on.
- Anything that doesn't map, with your suggestion for it.

## 4. Install and migrate

Follow docs/migrating.md in order, asking at each step that changes content:

1. Install: `composer require jotham-lec/statamic-marketing-toolkit`, `php artisan migrate`, `php please mt:install`.
2. Add `import: marketing-toolkit::seo` to each blueprint that needs SEO fields, as its own tab.
3. Copy the old SEO values into the `seo` group with a one-off script (an Artisan command or a `php artisan tinker` script) that loops over every entry and term on every site. Run it with a dry-run flag first, and report how many values it would copy per field. Leave the old fields in place.
4. Fill Brand and Marketing settings from the old values, through Statamic's API (`GlobalSet::findByHandle('seo')` and `GlobalSet::findByHandle('marketing')`, each site's localization).
5. Write the redirects to a CSV with the columns `source`, `target`, `status` and `active` (and `site` on a multi-site install), and ask the user to import it under Marketing → Redirects → Import CSV, or save them through `app(JothamLec\MarketingToolkit\Redirects\RedirectRepository::class)->save(Rule::make([...]))`, which writes `content/redirects/` (commit it) or the database, as the site's `redirects.driver` says. Turn regular expressions into `*` wildcards with `$1` where you can, and list the ones you can't.
6. In each layout, replace the old tags and snippets with `<s:mt:head />` after `<meta charset>` and the viewport, and `<s:mt:body />` after `<body>` (`{{ mt:head }}` and `{{ mt:body }}` in Antlers). Pass `title`, `description` or `status` on pages that aren't entries.
7. Move custom structured data into a subclass of `JothamLec\MarketingToolkit\SiteSeo`, bound in a service provider (see docs/developers.md).
8. Name the static files in `public/` that the addon now serves, and delete them once the user agrees.

## 5. Check against the baseline

Render the same pages again and compare each `<head>` with the baseline. Report every difference in the title, description, canonical, robots, share image and JSON-LD, and say whether each one is an improvement or a regression. Fix the regressions before you go on. Compare the sitemap and robots.txt in the same way, then run `php please mt:report` and summarise what it finds.

## 6. Clean up

Only after the user has seen the comparison and agreed: remove the old add-ons with `composer remove`, their config and published views, the old SEO fields from the blueprints, and the hand-built routes the addon replaces. Then switch off the features the site doesn't use under Marketing → Settings → Features.

## 7. Hand over

Finish with a short summary for the user, covering:

- what moved, with counts (entries updated, redirects imported, tracking IDs set);
- what was removed, and what was left in place on purpose;
- what they still need to do: commit, deploy, run `php artisan migrate` on the server, make sure the live site runs with `APP_ENV=production`, copy migrated values to a production database if content lives in one, resubmit the sitemap in Google Search Console, and watch Marketing → 404s for the first few weeks.
