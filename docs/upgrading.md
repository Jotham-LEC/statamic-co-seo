# Upgrading

## Removed in 1.0

Version 1.0 drops what keeps sites set up for older versions working. Before you update to 1.0, update to the last 0.x release and check each of these:

- **`SEO_*` environment variables.** Rename each to its `MT_*` name (`SEO_GTM_ID` to `MT_GTM_ID`). Until 1.0 both are read.
- **A `config/marketing-toolkit.php` published up to 0.19.** Its old keys (`tracking.gtm`, `tracking.ga4`, `tracking.meta_pixel`, `tracking.linkedin`, and `robots_txt`, `llms_txt` or `ads_txt` set to `true` or `false`) are read in today's names until 1.0 (`src/Legacy/OldConfig.php`). Rename them as the addon's own config file names them now.
- **The `marketing-toolkit.class` config key.** Bind your `SiteSeo` subclass in a service provider instead (see [developers.md](developers.md)).
- **Co-SEO's addon settings** (`resources/addons/seo.yaml`, or its `addon_settings` row) are copied to Marketing Toolkit's by a migration until 1.0 (`src/Legacy/CoSeoSettings.php`). Run `php artisan migrate` on 0.x first.
- **The static helpers on `Redirects\Redirect`** (`normalize()`, `normalizeTarget()`, `ignoresCase()`, `key()`, `pointsBack()`, `validator()`, `rules()`, `messages()`, and the `STATUSES` and `MAX_SOURCE` constants) and **`Matcher::flushAfter()`**. Use the same names on `Redirects\Rule`, and `RedirectRepository::batch()` (see [developers.md](developers.md#redirects-404s-and-reports-from-code)). The `Redirect` model itself stays, as the Eloquent driver's.
- **Update scripts for sites coming from before 0.21.** `RenameFromSeo`, `MoveToMarketingSettings`, `DropFieldDescriptions` and `KeepSiteNameInTitles` move a site's files and settings from older versions. Run `composer update` to the last 0.x release (or `php please updates:run 0.17.0 --package=jotham-lec/statamic-marketing-toolkit` from Co-SEO) and commit what they change.

## From 0.28: redirects in files

Redirect rules can now live in YAML files in `content/redirects/`, committed with the site, and that is the default for new sites (see [configuration.md](configuration.md#where-redirects-are-kept)). **Updating changes nothing by itself**: while `content/redirects/` doesn't exist and the `mt_redirects` table has rows, the addon keeps reading and writing the database, and the Redirects screen says so. Run `php artisan migrate` as usual: it adds the `mt_redirect_hits` table, where hits are counted for rules in files.

### To move a site's redirects into files

1. Require `^0.29.0` and run `php artisan migrate`.
2. Run `php please mt:export-redirects` against the database that holds the live rules: on the production server, or locally on a copy of the production database. It writes `content/redirects/all.yaml` (and a `{site}.yaml` per site on a multisite), each rule keeping its database id, and copies the hit counts into `mt_redirect_hits`.
3. Commit `content/redirects/` and deploy. The folder now exists, so the files are used from then on, everywhere.
4. If you exported from a copy, run `php please mt:export-redirects --hits-only` once on production after the deploy. It writes no file; it carries production's counts into `mt_redirect_hits` for the rules in the files.

`MT_REDIRECTS_DRIVER=file` is the default, so `.env` needs nothing. The rows in `mt_redirects` stay where they are, unused; the table stays too, as your own migrations may write to it (such writes go to the database whatever the driver).

Once the rules are files, a redirect saved in the control panel of a deployed site is written into that release, and lost on the next deploy unless you use Statamic's Git automation, as for entries. A site whose editors add redirects on the live site, without Git automation, is better off staying on the database.

### To stay on the database

Set `MT_REDIRECTS_DRIVER=eloquent` in `.env` (or `'driver' => 'eloquent'` under `redirects` in `config/marketing-toolkit.php`). Nothing else changes.

### To move from files back to the database

Run `php please mt:import-redirects`, then set `MT_REDIRECTS_DRIVER=eloquent`. It refuses while `mt_redirects` has rows, unless given `--force`.

### For developers

Code that queried the `Redirect` model to read rules should ask `app(JothamLec\MarketingToolkit\Redirects\RedirectRepository::class)` instead, which answers for either driver with `Rule` objects. Rule ids are strings now (a number as a string in the database, a UUID in files), and so is the `id` that `Matcher::match()` returns. The model's static helpers are deprecated in favour of the same ones on `Rule`.

## From 0.22: Brand fields, and one row per address

- **Run `php artisan migrate`.** It adds a unique index so the 404 log keeps one row per path, and redirects one rule per address, also on a single site (rows without a site). 404 paths already logged twice are merged first, their hits added up. Redirects are never deleted: if two redirects for every site share a source, the migration stops before changing anything and names them. Check first under **Marketing → Redirects** (search for the address) and delete all but one of each; the newest active one is the one that applies today. On MySQL and MariaDB the index is on a new generated column, `site_key`.
- **New fields**: `composer update` (or `php please updates:run`) adds **Name in page titles** and **Legal name** to Brand and **PostHog app address** to Marketing settings, as it adds every new field. Nothing else in your blueprints changes. Commit the blueprint files it lists.
- **Several other names or areas, or a year alone for Founded** (optional): new installs get "Other names" and "Area served" as List fields and "Founded" as a Text field. Your blueprint keeps the field types it has, and the addon reads both. To switch, open **Fields → Blueprints → Brand** and change "Other names" and "Area served" to **List**, and "Founded" to **Text** (add the validation `regex:/^\d{4}(-\d{2}(-\d{2})?)?$/` if you like). A saved text becomes the list's first item, and a saved date stays as it was.

## From 0.21.4: redirects on multi-site installs

Nothing to run. Two changes to check on a multi-site install:

- **Sites under a folder of their domain** (French under `/fr/`): redirects are paths within the site, so `/a-propos` means `example.com/fr/a-propos`, as the automatic redirects have always stored them. Redirects you typed with the folder (`/fr/old`) still apply, and a target that starts with the folder no longer gets it twice. A redirect for every site now also applies inside each folder site (`/old` → `/new` sends `example.com/fr/old` to `example.com/fr/new`), so check those. New 404s on such a site are logged without the folder, so a path already logged as `/fr/x` may appear again as `/x`; the old row ages out of the log.
- **Redirects for every site and site permissions**: people who can't work on every site no longer see redirects for every site, and can't create them. Give such a redirect a site, or leave it to a super user or a role with access to every site.

## From 0.20: Brand and Marketing settings

The "SEO & brand" global set is now two sets. **Brand** keeps its handle (`seo`, `marketing-toolkit.global`) and the Brand, Publisher, Shop and Share cards tabs. The new **Marketing settings** set (`marketing`, `marketing-toolkit.settings_global`) takes the Tracking, Consent, Leads and Crawlers tabs. In the control panel both sit in the new **Marketing** section of the sidebar, which replaces Tools → SEO: Brand as **Brand**, and Marketing settings as **Settings**. The report settings have moved from Tools → Addons to the **Settings** tab of **Marketing → Reports**.

### What happens by itself

On `composer update` (on your machine), the `MoveToMarketingSettings` update script:

- creates the Marketing settings blueprint and global set, on the same sites as Brand;
- moves each site's own values of the tracking, Consent Mode, leads and crawler fields from Brand to Marketing settings (a site that took a value from its origin site keeps taking it from there);
- removes those fields from Brand's blueprint, along with any section or tab they leave empty, while fields the site added itself stay where they are;
- renames "SEO & brand" to "Brand", unless you had already given the set another title.

Until the script has run, the addon reads those values from Brand as before, so nothing stops working in between. Once a value is saved in Marketing settings, that one is used, even if an older copy is still in Brand.

The script runs when you update from 0.20 or earlier. It is safe to run again (`php please updates:run 0.20.0 --package=jotham-lec/statamic-marketing-toolkit`): it moves only values still in Brand, and never replaces a value that is already in Marketing settings. Once you're on 0.21, a field you put back in Brand yourself stays there.

### What to commit and check

- **Commit the files it changes**: the blueprints in `resources/blueprints/globals` (`seo.yaml` and the new `marketing.yaml`) and the global sets and their values under `content/globals`, then deploy. If editors change these settings on the production site rather than in git, bring production's `content/globals` into your copy before running `composer update`, so that the script moves the current values.
- **Globals stored in the database**: if your site keeps global sets in the database (Statamic's Eloquent driver), the update moves the values in your own database only. After deploying, run `php please updates:run 0.20.0 --package=jotham-lec/statamic-marketing-toolkit` on each server whose database you didn't update, and the script moves that database's values in the same way.
- **Roles**: Marketing settings is a new global set, so a role that may edit Brand can't edit it until you allow it. Under **Users → Roles**, tick it for the people who look after tracking and consent.
- **Your own templates and code**: a template or class that reads a tracking, consent, leads or crawler field from the `seo` global (`{{ seo:gtm_id }}`, say) should read it from `marketing` instead once the values have moved.
- **Field descriptions**: the fields of Brand and Marketing settings no longer show descriptions. The `DropFieldDescriptions` update script takes the addon's old descriptions out of your copies of those blueprints, and keeps any you wrote yourself. Commit the blueprints it changes.
  - **Coming from 0.19 or earlier, through 0.21.0 or 0.21.1**: those versions missed the descriptions still under their `seo::` names, so Brand's fields may show raw keys such as `marketing-toolkit::fields.brand.title_separator.instructions` underneath. Updating from 0.21.0 or 0.21.1 to any later version with `composer update` takes them out. If a site still shows them after that, run `php please updates:run 0.21.1 --package=jotham-lec/statamic-marketing-toolkit`. Commit the blueprints it changes.
- **Bookmarks**: the report settings are now on the Settings tab of Marketing → Reports, and Features is a tab of Marketing → Settings, on the default site.

## From 0.19: the `seo` names

Up to 0.19 most of the addon's names were `seo`. They are now `marketing-toolkit` (the addon's slug, wherever Statamic names a thing after it) and `mt` (wherever you type a short handle); see [the names](developers.md#names). Most of the move happens by itself.

### What happens by itself

On `composer update` (on your machine), Statamic runs the addon's update scripts. They rename, in the site's own files:

- `import: seo::seo` in blueprints and fieldsets → `marketing-toolkit::seo`, and the `seo::` labels in the SEO & brand blueprint (now Brand) and the forms' lead source fields → `marketing-toolkit::`.
- The addon's four tags in `resources/views`, in Antlers and Blade: `seo:head`, `seo:body`, `seo:meta` and `seo:favicons` → `mt:head` and so on (`<s:seo:head />`, `{{ seo:head }}`, `Statamic::tag('seo:head')`). Nothing else that starts with `seo:` is touched: the Brand global is still `seo`, so `{{ seo:site_name }}` and `{{ seo:logo }}` stay as they are. A site with a `seo` tag of its own keeps its templates as they are, and the command says so.
- The widget in `config/statamic/cp.php`: `'type' => 'seo'` → `'type' => 'mt'`.
- `config/seo.php` → `config/marketing-toolkit.php`, as it was: its old keys keep working. Only the addon's own (it names `JothamLec\…` classes): another package's `config/seo.php` stays.
- `lang/vendor/seo` → `lang/vendor/marketing-toolkit`, when it holds the addon's translations.
- The permissions of each role: `view seo`, `manage seo redirects`, `run seo reports` → `view marketing toolkit`, `manage marketing toolkit redirects`, `run marketing toolkit reports`.

The command lists what it changed. **Commit it**, then deploy.

This runs once: when you update from 0.19 or earlier, or later on a site that still has one of the old names it renames (as when Co-SEO was swapped for this package without `updates:run`). Each of those is gone once it has run, so later updates leave your files alone, including anything you changed back by hand.

On `php artisan migrate` (on the server, as every deploy runs it), the tables `seo_*` become `mt_*`, the reports kept are rewritten to the new translation keys, and a Search Console key uploaded in the control panel moves to `storage/app/private/marketing-toolkit`.

### What to check by hand

- **Anything that names the old ones in code**: a template that builds the tag's name (`Statamic::tag('seo:'.$tag)`), `route('seo.…')` (now `mt.…`), `__('seo::…')` (now `marketing-toolkit::…`), a query on `seo_redirects` (now `mt_redirects`), a scheduler or deploy script that runs `php please seo:…` (now `mt:…`).
- **`.env`**: the names are now `MT_GTM_ID`, `MT_SEARCH_CONSOLE_CREDENTIALS` and so on. The `SEO_…` names are still read until 1.0, so nothing stops working; rename them on each server when convenient.
- **Roles kept in the database** (Statamic's Eloquent driver for users): the update script renames the permissions in the database it runs against. For production's, run it there too: `php please updates:run 0.19.0 --package=jotham-lec/statamic-marketing-toolkit`.
- **Bookmarks**: the control panel's screens moved from `/cp/seo/…` to `/cp/marketing-toolkit/…`.
- **Listing columns** chosen on Redirects, 404s and a report reset once (they're kept per user under a new name).

## From Co-SEO

Co-SEO is now **Marketing Toolkit**: a site changes its Composer package, and the update scripts and migrations above do the rest.

### 1. Swap the package

```bash
composer remove jotham-lec/statamic-co-seo --no-update
composer require jotham-lec/statamic-marketing-toolkit
php please updates:run 0.17.0 --package=jotham-lec/statamic-marketing-toolkit
php artisan migrate
```

The new package replaces the old one, so Composer won't install both. `jotham-lec/statamic-co-seo` has been removed from Packagist. Statamic runs a package's update scripts only when it updates that package, not when it's installed under a new name, hence `updates:run`, from 0.17.0, the last Co-SEO version. If you skipped it, the renaming happens on your next `composer update` instead, as long as the site still has an old name (an `import: seo::seo` in a blueprint, say); run `php please mt:install` for any Brand fields that are missing. Then follow [From 0.19](#from-019-the-seo-names) for what to check.

`php artisan migrate` also copies the addon settings (the Search Console property) to the new name, from `resources/addons/seo.yaml` to `resources/addons/marketing-toolkit.yaml`, or, with Statamic's Eloquent driver, from the `addon_settings` row of `jotham-lec/statamic-co-seo`. Commit the new file if you keep it in git.

### 2. What else changes

- The control panel's scripts are published to `public/vendor/statamic-marketing-toolkit`, with the publish tag `marketing-toolkit`: `php artisan vendor:publish --tag=marketing-toolkit --force`.
- PHP classes moved from `JothamLec\Seo\…` to `JothamLec\MarketingToolkit\…`. Only a site that overrides `SiteSeo` or extends a share-card template needs to change its `use` lines.
- There are no editions any more: delete the addon's line (`jotham-lec/statamic-co-seo`) from `config/statamic/editions.php`. Everything that was Pro is in every install.

### 3. Use the new modules

- **Tracking and Consent Mode**: run `php please mt:install --tab=tracking`, swap `<s:mt:meta />` for `<s:mt:head />` (right after `<meta charset>` and the viewport), add `<s:mt:body />` right after `<body>`, then move the site's tracking IDs into the **Tracking** tab of **Marketing → Settings** (or `.env`) and delete its own snippets, or each visit counts twice. See [tracking.md](tracking.md).
- **Favicons**: upload the icon in **Marketing → Brand**, under **Icon** on the Brand tab (after `mt:install`), then delete the old `favicon.ico`, `apple-touch-icon.png` and `site.webmanifest` from `public/` and their `<link>` tags from the layout; files in `public/` win over the generated ones.
- **Leads**: run `php please mt:install --forms` to add the lead source fields to every form. A site that sends its own lead events (from Livewire forms, say) should call `window.mtConversion('form-handle')` instead, or leave **Send form submissions as leads** off.
