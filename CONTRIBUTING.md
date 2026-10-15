# Working on Marketing Toolkit

```bash
composer install && npm install
npm run build      # Vue → resources/dist/build; commit the build, sites don't run npm
composer test      # Pest, in parallel (about a minute), then the redirect suites again on files; the share-card tests skip without PHP's imagick extension
composer lint      # Pint; `vendor/bin/pint` fixes what it finds
composer analyse   # Larastan, level 6, with the 1 GB it needs; phpstan.neon says why each ignored error is ignored
```

One file or one test, without the parallel runner (so `dump()` and `dd()` show):

```bash
vendor/bin/pest tests/Feature/SitemapTest.php
vendor/bin/pest --filter="adds URLs that are not entries"
```

## Where things live

Everything is under `src/`, in the `JothamLec\MarketingToolkit` namespace.

| Folder or file | What it holds |
|---|---|
| `ServiceProvider.php` | Registers the config, routes, listeners, middleware, permissions, nav and schedule. |
| `SiteSeo.php`, `Concerns/` | The rules for each value a page prints (title, description, robots, JSON-LD, alternates). Sites extend `SiteSeo`; its `@api` methods are listed in `PublicApiTest`. |
| `Context.php`, `Meta.php`, `Settings.php` | One page being rendered; everything its `<head>` prints; the Brand and Marketing settings globals. |
| `Tags/Mt.php` | The `mt` tag: `<s:mt:head />`, `<s:mt:body />` and the rest. |
| `Sitemap/`, `TextFiles/` | The sitemap's URLs, and robots.txt, llms.txt and ads.txt. |
| `Http/` | Controllers for the public files and the control panel, the middleware, and form requests. |
| `Redirects/`, `NotFound/`, `Rules/` | Redirect rules (`Rule`), where they are kept (`RedirectRepository`, with a driver in `File/` and one in `Eloquent/`, chosen by `Drivers`) and how a path is matched, the 404 log, and the validation rules for a redirect. |
| `Reports/` | Site reports: the runner, the page renderer, the HTML reader, and one class per check in `Reports/Rules/`. |
| `Toolbar/` | The front-end toolbar's cookie, guard script and page data. |
| `Tracking/`, `Conversions/` | Tracking tags and Consent Mode; leads and where they came from. |
| `SearchConsole/`, `IndexNow/`, `Og/`, `Favicons/` | Google Search Console, IndexNow, share cards and favicons. |
| `Cp/`, `Actions/`, `Fieldtypes/`, `Widgets/`, `Preview/` | The control panel's nav and listings, row actions, the SEO preview and heading fieldtypes, the dashboard widget, and the unsaved-entry preview. |
| `Commands/`, `Listeners/`, `UpdateScripts/` | `php please mt:*` commands, event listeners, and the update scripts Statamic runs on `composer update`. |
| `Models/Concerns/` | What the addon's Eloquent models share. |
| `Support/` | Small helpers: sites, text, URIs, assets, field values in Live Preview, permissions, the Features switches, the package name, config merging. |
| `Legacy/` | Code that keeps sites set up for older versions working; all of it goes in 1.0 (docs/upgrading.md, "Removed in 1.0"). |

The front end is in `resources/js`: Vue pages and components for the control panel (`addon.js`), and the toolbar in plain JavaScript (`toolbar/`). Views are in `resources/views`, blueprints and fieldsets in `resources/blueprints`, `resources/fieldsets` and `resources/install`, and translations in `lang/en`.

### How a request gets its answer

- **A page's `<title>`.** The layout's `<s:mt:head />` calls `Tags\Mt::head()`, which builds a `Context` for the page and asks `SiteSeo::meta()`. That calls `title()`, which tries the SEO fields, the collection's `title_fields` and the entry's title, then adds the site's name. `resources/views/meta.blade.php` prints the result.
- **A redirect.** The site answers a missing page with a 404. `Http\Middleware\HandleMissing`, in Statamic's `statamic.web` group, sees the 404 and asks `Redirects\Matcher::match()` for a rule. The matcher reads the site's rules from the cache, looks the path up exactly, then tries the wildcards. A match becomes a 301, 302 or 410; no match is counted in the 404 log by `NotFound\Recorder`, after the response has gone out.
- **A report run.** **Run report** posts to `ReportsController::run()`, which calls `Reports\Runner::start()` to list the pages. Each step (`Runner::step()`) renders a chunk of pages with `Renderer` and reads them with `HtmlInspector`. On a queue worker each step is a `RunReportStep` job; without one, the report's screen asks for the next step while it is open. The last step runs each check in `Reports/Rules/` on every page and scores the site.

## Tests

Every test is in `tests/Feature`, one file per area; `tests/Pest.php` sets up a site (one `default` site at `https://example.test/`, the `home`, `pages` and `essays` collections, an `assets` container) and holds the helpers (`entryIn()`, `seoGlobal()`, `metaFor()`, `renderAt()`, `cpUser()`, `multisite()`…).

- Tests run as production (`APP_ENV` in `phpunit.xml`), so the meta tags and robots.txt are what a live site prints. CSRF is switched off in `tests/Pest.php`.
- The cache is an `array` store that serializes, as automatic redirects need. Pages render through `tests/fixtures/views`.
- Statamic matches the site by its absolute URL, so request front-end pages as `https://example.test/…`.
- Blueprints, forms and global sets are files that outlive a test: delete what a test creates, or give it a handle no other test uses.
- Each parallel process gets its own copy of Testbench's skeleton in `/tmp/marketing-toolkit-tests`, so a test may write to `public/` or `resources/`.
- The blueprints are YAML, so they repeat lists the code owns (the report checks and their defaults, the Features switches, the tracking ID patterns). `BlueprintsTest` fails when the two disagree: change both.

The redirect suites (the `redirects` group) run on the database driver; `MT_TEST_REDIRECTS=file vendor/bin/pest --group=redirects` runs them on files, as `composer test` does after the full suite. Tests that save a rule go through the helpers in `tests/Pest.php` (`redirectRule()`, `freshRule()`, `storedRule()`…), not the `Redirect` model, so they run on both.

The suite runs on SQLite. To run it on Postgres, point it at an empty database: `MT_TEST_DB=pgsql DB_PORT=5432 DB_DATABASE=mt_test vendor/bin/pest` (also `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`; not in parallel, as the processes would share the database). GitHub Actions runs all of this on every push: PHP 8.3 on the oldest versions composer.json allows, PHP 8.4 on the newest, Postgres, and without Imagick, plus `composer validate`, Pint, PHPStan and the build.

## Database and data changes

- Migrations that have shipped stay as they are: never rename, move or edit one, since sites have already run it.
- A new migration is dated `2026_10_13` or later. Some shipped migrations carry dates ahead of their release, and Laravel runs migrations in the order of their file names, so a new one must sort after all of them. Check `ls database/migrations | tail -1` before naming one.
- A migration changes tables only. A change to sites' content or settings (globals, blueprints, addon settings) is an update script: a subclass of the addon's `UpdateScripts\UpdateScript` (which extends Statamic's) in `src/UpdateScripts/`, which Statamic finds there and runs on `composer update` (or `php please updates:run`), on the developer's machine, so the change is committed with the update. Statamic asks every script's `shouldUpdate()` on every update of the addon, so:
  - `shouldUpdate()` is true only for a site that still needs the change: from the version it is updating from (`self::before($oldVersion, 'x.y.z')`; not Statamic's `isUpdatingTo()`, which reads the lock files instead of the versions it is given), or from something only the old version left behind, which `update()` removes. Never `return true`: a change the developer undoes would be made again on each update.
  - `update()` changes only what the addon itself wrote (its tags, its namespaces, its fields), never what merely looks like it: the Brand global is `seo` too, and another package may publish a `config/seo.php`.
  - Its tests show that a site's own look-alike is left alone, and that a second run, or the next update, changes nothing.
  - A field added to `resources/install` is listed in `AddNewBrandFields::FIELDS` under the version that brings it; a test fails until it is.
- The one exception is `carry_over_co_seo_settings`, a migration because Statamic doesn't run update scripts for a package that changed its name.

## Changelog

Each version in [CHANGELOG.md](CHANGELOG.md) has a heading `## X.Y.Z – YYYY-MM-DD`, an optional paragraph that sums it up, and only these sections:

- `### Added`: new features.
- `### Changed`: changes to how existing features behave.
- `### Fixed`: bug fixes.
- `### Upgrading`: what a site must do, or what it will notice, after updating.

Every bullet starts with one bold sentence that stands on its own, followed by the detail. The Marketplace's release notes show only that bold sentence, with a **New** badge for Added and Changed and a **Fix** badge for Fixed (see `scripts/release-notes.php`). `ReleaseNotesTest` fails when a version from 0.24.0 on uses another heading.

## Release

1. Note the change in [CHANGELOG.md](CHANGELOG.md), with an **Upgrading** list for anything a site must do. Check the Marketplace notes with `php scripts/release-notes.php X.Y.Z`.
2. `npm run build` and commit `resources/dist`.
3. `git tag -a vX.Y.Z -m vX.Y.Z && git push --follow-tags`. Sites update with `composer update jotham-lec/statamic-marketing-toolkit`; the Marketplace picks the tag up from Packagist.
4. Check that the tag has a GitHub Release: the `Release` workflow creates it from the CHANGELOG. If the workflow failed, fix the CHANGELOG and create the release by hand (`php scripts/release-notes.php X.Y.Z > notes.md && gh release create vX.Y.Z --title X.Y.Z --notes-file notes.md`) before the Marketplace sees the tag: it keeps the first notes it reads.
5. Within a day, check the version's Release Notes tab on the Marketplace.
