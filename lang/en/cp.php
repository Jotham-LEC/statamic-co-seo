<?php

/*
 * The control panel's words. Copy this folder to lang/{locale} (or publish
 * it with `php artisan vendor:publish --tag=marketing-toolkit-translations`)
 * to translate.
 */
return [

    'seo' => 'Marketing',

    'nav' => [
        'overview' => 'Overview',
        'reports' => 'Reports',
        'redirects' => 'Redirects',
        'not_found' => '404s',
        'search_console' => 'Search Console',
        'brand' => 'Brand',
        'settings' => 'Settings',
    ],

    'permissions' => [
        'group' => 'Marketing',
        'view' => 'View the marketing overview, reports, and 404s',
        'redirects' => 'Manage redirects',
        'reports' => 'Run SEO reports',
    ],

    'overview' => [
        'report' => [
            'title' => 'Report',
            'pages' => ':count page,|:count pages,',
            'none' => 'There is no report yet. A report opens every page of the site and gives each one a score out of 100.',
            'open_latest' => 'Open the latest report',
            'all' => 'All reports',
            'settings' => 'Report settings',
        ],
        'not_found' => [
            'title' => '404s',
            'none' => 'No missing pages have been logged yet.',
            'count' => ':count missing address has been logged. The most recent are listed below.|:count missing addresses have been logged. The most recent are listed below.',
            'all' => 'All 404s',
        ],
        'redirects' => [
            'title' => 'Redirects',
            'active' => 'There is :count active redirect.|There are :count active redirects.',
            'automatic' => 'Of these, :count was added when a page moved.|Of these, :count were added when a page moved.',
            'only_404' => 'A redirect is only used where the site would otherwise show a 404.',
            'manage' => 'Manage redirects',
        ],
        'brand' => [
            'title' => 'Brand',
            'missing' => 'The “Brand” global set is missing. Run :command to create it.',
            'site_name' => 'Site name',
            'titles' => 'Titles',
            'page_title' => 'Page title',
            'default_description' => 'Default description',
            'no_description' => 'None, so each page uses its own text',
            'edit' => 'Edit the brand',
        ],
        'search' => [
            'title' => 'Google Search',
            'not_imported' => 'Search Console is connected, but nothing has been imported yet. The import runs every day, or you can run it now from the Search Console screen.',
            'summary' => 'The site had :clicks clicks from :impressions appearances in Google between :from and :to.',
            'updated' => 'Updated',
            'page' => 'Page',
            'clicks' => 'Clicks',
            'impressions' => 'Appearances',
            'position' => 'Position',
            'connect' => 'Connect Search Console',
            'connect_body' => 'Connect Search Console to see how often each page appears in Google and how often people click on it.',
            'manage' => 'Search Console',
        ],
        'files' => [
            'title' => 'What the site serves',
            'public' => 'A file with this name in the public folder is served instead of the addon’s. Delete that file to use the addon’s version.',
            'sitemap' => 'Sitemap',
            'robots' => 'robots.txt',
            'llms' => 'llms.txt',
            'favicon' => 'Web app manifest',
            'card' => 'Home page share card',
            'no_cards' => 'Share cards are on, but this server can’t draw them: they need the Imagick PHP extension. Until it’s installed, pages use their own image or the default one from Brand.',
        ],
    ],

    'search_console' => [
        'title' => 'Search Console',
        'status' => 'Connection',
        // A key and a property are saved; only "Check the connection" asks Google whether they work.
        'connected' => 'Set up',
        'not_connected' => 'Not set up',
        'not_connected_body' => 'Anyone who can change this addon’s settings can connect Google Search Console here, so that you can see how often each page is found and clicked.',
        'key' => 'Key',
        'property' => 'Property',
        'no_property' => 'None',
        'properties' => 'Property of each site',
        'last_import' => 'Last import',
        'never' => 'Never',
        'pages' => 'Pages with numbers',
        'import' => 'Import now',
        'disconnect' => 'Disconnect',
        'disconnect_confirm' => 'Do you want to remove the key? The numbers that were already imported stay until the next import.',
        'from_env' => '(from .env)',
        'overview' => 'Back to the overview',
        'setup' => [
            'heading' => 'Connect Google Search Console',
            'set_up' => 'Set up',
            'intro' => 'Search Console shows how often each page appears in Google and how often people click on it. Setting it up takes about ten minutes, and you only do it once.',
            'step_key' => 'Create a key in Google Cloud.',
            'step_key_body' => 'In a Google Cloud project (a new one is fine), :enable. Then, under :accounts, create a service account, which needs no roles. Open it, go to Keys, choose Add key, and pick JSON, and a key file will download.',
            'enable_api' => 'enable the Google Search Console API',
            'service_accounts' => 'Service accounts',
            'key_blocked' => 'If you can’t add a key, or Google says the key is disabled, the project probably blocks service account keys, which is common in new Google Cloud projects. An administrator of your organisation can allow them in :policy (iam.disableServiceAccountKeyCreation), and a key that exists but is disabled can be :enable.',
            'key_policy' => 'Organisation policies',
            'key_enable' => 'enabled again',
            'key_added' => 'Key added',
            'remove' => 'Remove',
            'env_key_invalid' => 'MT_SEARCH_CONSOLE_CREDENTIALS in .env is not a service account key, or the file it points to can’t be read.',
            'upload' => 'Upload the key file',
            'paste' => 'Or paste its contents',
            'save_key' => 'Save the key',
            'stored' => 'The key is stored on the server in storage/app/private and never in git, and only its email address is shown here.',
            'step_users' => 'Let the key read your property.',
            'step_users_body' => 'In :users, add :email as a Restricted user.',
            'users_link' => 'Search Console → Settings → Users and permissions',
            'the_email' => 'the key’s email address',
            'copy_email' => 'Copy the email address',
            'copied' => 'Copied',
            'copy_failed' => 'The browser didn’t allow copying, so select the email address and copy it yourself.',
            'step_property' => 'Name the property.',
            'step_property_body' => 'Type it the way Search Console names it, which is :domain for a domain or :prefix for an address prefix.',
            'save' => 'Save',
            'step_check' => 'Check that it works.',
            'step_check_body' => 'Then use Import now, above, to bring in the numbers.',
            'check' => 'Check the connection',
            'schedule' => 'After that, the numbers are updated every day at 04:30, as long as Laravel’s scheduler runs (:command every minute). If it doesn’t, come back here and use Import now.',
            'failed' => 'That did not work.',
        ],
        'messages' => [
            'add_first' => 'Add the key and the property first.',
            'connected' => 'Search Console is connected, and the key can read :property.',
            'imported' => 'Imported :count page.|Imported :count pages.',
            'import_failed' => 'The import failed.',
            'import_failed_log' => 'The import failed, and the site’s log (storage/logs) says why.',
            'key_in_env' => 'The key is set in .env (MT_SEARCH_CONSOLE_CREDENTIALS).',
            'property_in_env' => 'The property is set in .env (MT_SEARCH_CONSOLE_PROPERTY).',
            'not_a_key' => 'That is not a service account key. Download one as JSON from Google Cloud → IAM & Admin → Service accounts → Keys.',
            'property_format' => 'Type the property the way Search Console names it, either sc-domain:example.com or https://example.com/ with the slash at the end.',
            'key_disabled' => 'Google says that this key, or its service account, is disabled. Enable it in Google Cloud, and then check again at :url',
            'api_disabled' => 'The Google Search Console API is not enabled in the key’s Google Cloud project. Enable it, wait a minute, and check again.',
            'key_refused' => 'Google refused the key, which may mean it was deleted in Google Cloud. Create a new key and upload it.',
            'not_a_user' => ':email is not a user of :property. Add it in Search Console → Settings → Users and permissions.',
            'the_service_account' => 'the service account',
            'no_property' => 'Search Console has no property called :property. Check how it is named there, which is sc-domain:example.com for a domain or https://example.com/ for an address prefix.',
            'google_said' => 'Search Console replied with this message: :message',
            'error' => 'error :status',
            'unexpected' => 'The check failed before Google answered, and the site’s log (storage/logs) says why. If APP_KEY has changed since the key was uploaded, upload the key again.',
        ],
    ],

    'redirects' => [
        'title' => 'Redirects',
        'import' => 'Import CSV',
        'export' => 'Export CSV',
        'create' => 'Create redirect',
        'intro' => 'A redirect is only used when an address would otherwise show a 404, so a page that exists always comes first. Redirects marked “Automatic” were added when content moved.',
        'automatic' => 'Automatic',
        'yes' => 'Yes',
        'no' => 'No',
        'imported' => ':created added and :updated updated.',
        'skipped' => ':summary Some rows were skipped (:count in all). :errors',
        'import_failed' => 'The file could not be imported.',
    ],

    // The columns of the redirects and 404 listings.
    'listing' => [
        'from' => 'From',
        'to' => 'To',
        'site' => 'Site',
        'all_sites' => 'All sites',
        'status' => 'Status',
        'active' => 'Active',
        'hits' => 'Hits',
        'last_used' => 'Last used',
        'last_seen' => 'Last seen',
        'path' => 'Path',
        'first_seen' => 'First seen',
        'last_linked_from' => 'Last linked from',
    ],

    'redirect_form' => [
        'campaign' => 'Campaign link',
        'source' => 'From',
        'target' => 'To',
        'status' => 'Type',
        'status_301' => '301 Moved permanently',
        'status_302' => '302 Moved temporarily',
        'status_410' => '410 Gone',
        'active' => 'Active',
        'site' => 'Site',
        'all_sites' => 'All sites',
        'used' => 'Used :count time.|Used :count times.',
        'used_last' => 'Used :count time, most recently :when.|Used :count times, most recently :when.',
        'automatic' => 'This redirect was added automatically when the content moved. If you save it here, it becomes a manual redirect.',
        'back' => '← Redirects',
    ],

    'not_found' => [
        'title' => '404s',
        'intro' => 'These are the addresses visitors asked for that don’t exist, with one row per address, and the :max most recent are kept. Bots and scanners are left out.',
        'create' => 'Use the menu on a row to create a redirect for that address.',
        'off' => 'The 404 log is switched off (:setting).',
    ],

    // Marketing → Features.
    'features' => [
        'title' => 'Features',
        'groups' => [
            'search' => 'Search engines',
            'redirects' => 'Redirects and broken links',
            'marketing' => 'Marketing',
        ],
        'modules' => [
            'sitemap' => ['display' => 'Sitemap'],
            'robots_txt' => ['display' => 'robots.txt'],
            'llms_txt' => ['display' => 'llms.txt'],
            'hreflang' => ['display' => 'Languages (hreflang)'],
            'indexnow' => ['display' => 'IndexNow'],
            'share_cards' => ['display' => 'Generated share cards'],
            'redirects' => ['display' => 'Redirects'],
            'automatic_redirects' => ['display' => 'Redirects when a page moves'],
            'not_found' => ['display' => '404 log'],
            'reports' => ['display' => 'Scheduled reports'],
            'tracking' => ['display' => 'Tracking tags and Consent Mode'],
            'leads' => ['display' => 'Leads and their source'],
            'favicons' => ['display' => 'Favicons'],
            'ads_txt' => ['display' => 'ads.txt'],
            'toolbar' => ['display' => 'Front-end toolbar'],
        ],
    ],

    // The tracking tags (src/Tracking), on the overview and in the Tracking tab's warning.
    'tracking' => [
        'title' => 'Tracking',
        'names' => [
            'gtm' => 'Google Tag Manager',
            'ga4' => 'Google Analytics 4',
            'posthog' => 'PostHog',
            'meta' => 'Meta Pixel',
            'linkedin' => 'LinkedIn Insight Tag',
        ],
        'none' => 'There are no tracking tags yet. You can add Google Tag Manager, Google Analytics, PostHog, the Meta Pixel, or LinkedIn in Settings → Tracking.',
        'live_only' => 'They load on the live site only.',
        'off_here' => 'They don’t load on this site, because its Tracking tab turns them off.',
        'from_env' => 'set in .env',
        'consent' => 'Consent Mode is on.',
        'overlap_title' => 'Move these tags into Google Tag Manager',
        'overlap' => 'Google Tag Manager is set, and so is :tools. If Tag Manager loads it as well, every visit is counted twice. Add it as a tag in Tag Manager, and then clear its ID where it is set, which is either Settings → Tracking or .env.|Google Tag Manager is set, and so are :tools. If Tag Manager loads them as well, every visit is counted twice. Add them as tags in Tag Manager, and then clear their IDs where they are set, which is either Settings → Tracking or .env.',
        'invalid' => 'The :name value “:value” in :where isn’t a valid ID, so the tag isn’t added to the site.',
        'where_global' => 'Settings → Tracking',
        'overlap_toast' => 'Your changes are saved. Google Tag Manager is set, and so is another tracking tool, so move that tool into Tag Manager or every visit may be counted twice.',
        'edit' => 'Edit tracking',
    ],

    // The score's gauge.
    'gauge' => [
        'label' => 'SEO score',
    ],

    // A running report's progress bar, and a report that failed.
    'report_progress' => [
        'failed' => 'The report’s progress could not be loaded. It may have been deleted, or you may need to sign in again.',
        'retry' => 'Try again',
        'watching' => 'This report only moves on while someone who may run reports has it open, because the site has no queue worker.',
        'report_failed' => 'The report did not finish.',
    ],

    // The dashboard widget.
    'widget' => [
        'latest_report' => 'Latest report',
        'pages' => ':count page|:count pages',
        'no_report' => 'There is no report yet.',
        'recent_404s' => 'Recent 404s',
        'none' => 'No missing pages have been recorded.',
    ],

    // The search and share previews on an entry's SEO tab.
    'preview' => [
        'failed' => 'The preview could not be worked out. Save the entry to see it.',
        'title' => 'Title',
        'description' => 'Description',
        'noindex' => 'This page is hidden from search engines (:robots), so this result will not appear.',
        'drawing' => 'Drawing the card…',
        'no_image' => 'No share image',
        'card_failed' => 'The card could not be drawn.',
        'from' => 'From :host',
        'count' => ':label length is :count character, and the aim is :min to :max.|:label length is :count characters, and the aim is :min to :max.',
        'under' => 'under :min',
        'over' => 'over :max',
    ],

    // Asked when saving would change a page's address.
    'confirm' => [
        'title' => 'This page’s address is changing',
        'add' => 'Add redirect',
        'dont_add' => 'Don’t add',
        'moves' => 'Saving will move the page to a new address.',
        'question' => 'Do you want to add a 301 redirect from the old address, so that links to it keep working?',
        'not_yet' => 'Don’t save yet',
        'not_saved' => 'The page was not saved.',
        'choice_failed' => 'Your answer didn’t reach the server, so saving adds a redirect from the old address. You can delete it under Redirects.',
    ],

    'actions' => [
        'delete_confirm' => 'Do you want to delete this item?|Do you want to delete these :count items?',
    ],

];
