<?php

/*
 * The labels and help of the addon's fields: the SEO fieldset on entries and
 * terms, the report settings (the Settings tab of Marketing → Reports), and the
 * "Brand" and "Marketing settings" global sets that `php please mt:install`
 * creates. Those blueprints store these keys, so each user sees them in their
 * control panel language.
 */
return [

    // resources/fieldsets/seo.yaml
    'seo' => [
        'heading' => [
            'title' => 'Heading',
        ],
        'seo_preview' => [
            'title' => 'SEO preview',
            'display' => 'Search and share preview',
        ],
        'seo' => [
            'display' => 'SEO',
        ],
        'sharing' => [
            'display' => 'Sharing',
        ],
        'advanced' => [
            'display' => 'Advanced',
        ],
        'title' => [
            'display' => 'Title',
        ],
        'description' => [
            'display' => 'Description',
        ],
        'image' => [
            'display' => 'Share image',
        ],
        'og_title' => [
            'display' => 'Card title',
        ],
        'og_subtitle' => [
            'display' => 'Card subtitle',
        ],
        'canonical' => [
            'display' => 'Canonical URL',
        ],
        'noindex' => [
            'display' => 'Hide from search engines',
        ],
        'nofollow' => [
            'display' => 'Do not follow links',
        ],
        'nosnippet' => [
            'display' => 'No snippet',
        ],
        'max_snippet' => [
            'display' => 'Snippet length',
        ],
        'sitemap' => [
            'display' => 'In sitemap',
        ],
        'json_ld' => [
            'display' => 'Extra JSON-LD',
        ],
    ],

    // The lead source fields `mt:install --forms` adds to each form.
    'attribution' => [
        'tab' => 'Lead source',
        'utm_source' => 'Source (utm_source)',
        'utm_medium' => 'Medium (utm_medium)',
        'utm_campaign' => 'Campaign (utm_campaign)',
        'utm_term' => 'Term (utm_term)',
        'utm_content' => 'Content (utm_content)',
        'referrer' => 'Came from',
        'landing_page' => 'First page',
    ],

    // resources/blueprints/settings.yaml
    'settings' => [
        'tabs' => [
            'checks' => 'Checks',
            'running' => 'Running',
        ],
        'sections' => [
            'checks' => [
                'display' => 'What a report checks',
            ],
            'lengths' => [
                'display' => 'Lengths',
            ],
            'pages' => [
                'display' => 'Which pages',
            ],
            'how' => [
                'display' => 'How',
            ],
            'schedule' => [
                'display' => 'Schedule',
            ],
        ],
        'rule_title_length' => ['display' => 'Title length'],
        'rule_title_unique' => ['display' => 'Unique titles'],
        'rule_description_length' => ['display' => 'Description length'],
        'rule_description_unique' => ['display' => 'Unique descriptions'],
        'rule_single_h1' => ['display' => 'One main heading (h1)'],
        'rule_canonical' => ['display' => 'Canonical address'],
        'rule_noindex_in_sitemap' => ['display' => 'Hidden pages left in the sitemap'],
        'rule_image_alt' => ['display' => 'Image descriptions (alt)'],
        'rule_broken_links' => ['display' => 'Broken links to this site'],
        'rule_orphan_pages' => ['display' => 'Pages no other page links to'],
        'rule_external_links' => [
            'display' => 'Broken links to other sites',
        ],
        'rule_og_image' => ['display' => 'Share image'],
        'rule_json_ld' => ['display' => 'Structured data (JSON-LD)'],
        'title_min' => ['display' => 'Shortest title'],
        'title_max' => ['display' => 'Longest title'],
        'description_min' => ['display' => 'Shortest description'],
        'description_max' => ['display' => 'Longest description'],
        'excluded_collections' => ['display' => 'Leave out these collections'],
        'max_pages' => [
            'display' => 'Most pages per report',
        ],
        'chunk_size' => [
            'display' => 'Pages per step',
        ],
        'keep_reports' => ['display' => 'Reports to keep'],
        'schedule' => [
            'display' => 'Run a report',
            'options' => [
                'off' => 'Only by hand',
                'daily' => 'Daily',
                'weekly' => 'Weekly',
            ],
        ],
        'schedule_day' => [
            'display' => 'On',
            'options' => [
                'monday' => 'Monday',
                'tuesday' => 'Tuesday',
                'wednesday' => 'Wednesday',
                'thursday' => 'Thursday',
                'friday' => 'Friday',
                'saturday' => 'Saturday',
                'sunday' => 'Sunday',
            ],
        ],
        'schedule_time' => ['display' => 'At'],
    ],

    // The "Brand" and "Marketing settings" global sets (src/Commands/Install.php)
    'brand' => [
        'tabs' => [
            'brand' => 'Brand',
            'publisher' => 'Publisher',
            'tracking' => 'Tracking',
            'consent' => 'Consent',
            'leads' => 'Leads',
            'shop' => 'Shop',
            'share_cards' => 'Share cards',
            'crawlers' => 'Crawlers',
        ],
        'sections' => [
            'icon' => [
                'display' => 'Icon',
            ],
            'address' => [
                'display' => 'Address',
            ],
            'local_business' => [
                'display' => 'Local business',
            ],
            'returns' => [
                'display' => 'Returns',
            ],
            'shipping' => [
                'display' => 'Shipping',
            ],
        ],

        // Brand
        'favicon' => [
            'display' => 'Icon',
        ],
        'theme_color' => [
            'display' => 'Theme colour',
        ],
        'background_color' => [
            'display' => 'Icon background',
        ],
        'conversions' => [
            'display' => 'Send form submissions as leads',
        ],
        'linkedin_conversion_id' => ['display' => 'LinkedIn conversion ID'],
        'attribution' => [
            'display' => 'Save where each lead came from',
        ],
        'ads_txt' => [
            'display' => 'ads.txt',
        ],
        'site_tracking' => [
            'display' => 'Tracking tags on this site',
            'instructions' => 'Each site has its own tags. A site that hasn’t set its own takes the first site’s, so turn this off for a site that should load none.',
        ],
        'tracking_overlap' => [
            'display' => 'Move these tags into Google Tag Manager',
            'instructions' => 'Google Tag Manager is set, and so is at least one other tool here. If Tag Manager loads that tool as well, every visit is counted twice, so add each tool as a tag in Tag Manager and then clear its ID here.',
        ],
        'gtm_id' => [
            'display' => 'Google Tag Manager',
        ],
        'ga4_id' => ['display' => 'Google Analytics 4 measurement ID'],
        'posthog_key' => ['display' => 'PostHog project API key'],
        'posthog_host' => [
            'display' => 'PostHog host',
        ],
        'posthog_ui_host' => [
            'display' => 'PostHog app address',
            'instructions' => 'Only with a proxy as the host: your PostHog app (https://us.posthog.com or https://eu.posthog.com), for the toolbar and links back to it.',
        ],
        'meta_pixel_id' => ['display' => 'Meta Pixel ID'],
        'linkedin_partner_id' => ['display' => 'LinkedIn Insight Tag partner ID'],
        'consent_mode' => [
            'display' => 'Use Consent Mode',
        ],
        'consent_ad_storage' => ['display' => 'Advertising cookies (ad_storage)'],
        'consent_analytics_storage' => ['display' => 'Analytics cookies (analytics_storage)'],
        'consent_ad_user_data' => ['display' => 'Send data to Google for ads (ad_user_data)'],
        'consent_ad_personalization' => ['display' => 'Personalised ads (ad_personalization)'],
        'consent_value' => [
            'options' => [
                'denied' => 'Denied until they agree',
                'granted' => 'Granted',
            ],
        ],
        'consent_wait_for_update' => [
            'display' => 'Wait for the banner',
        ],
        'consent_regions' => [
            'display' => 'Only in these regions',
            'options' => [
                'eea' => 'EEA, UK, and Switzerland',
            ],
        ],
        'title_site_name' => [
            'display' => 'Add the site name to page titles',
        ],
        'title_separator' => [
            'display' => 'Title separator',
        ],
        'title_brand' => [
            'display' => 'Name in page titles',
            'instructions' => 'A shorter name for the end of page titles. Empty: the site\'s name.',
        ],
        'default_description' => [
            'display' => 'Default description',
        ],
        'default_image' => [
            'display' => 'Default share image',
        ],
        'site_alternate_name' => [
            'display' => 'Other site name',
        ],
        'twitter_handle' => ['display' => 'X handle'],

        // Publisher
        'publisher_type' => [
            'display' => 'Type',
            'options' => [
                'organization' => 'Organisation',
                'corporation' => 'Corporation',
                'educational_organization' => 'Educational organisation',
                'ngo' => 'Non-profit',
                'local_business' => 'Local business',
                'store' => 'Store',
                'professional_service' => 'Professional service',
                'restaurant' => 'Restaurant',
                'person' => 'Person',
            ],
        ],
        'publisher_name' => ['display' => 'Name'],
        'publisher_alternate_name' => [
            'display' => 'Other names',
        ],
        'legal_name' => [
            'display' => 'Legal name',
            'instructions' => 'The registered name, where it differs from the name.',
        ],
        'founding_date' => [
            'display' => 'Founded',
            'instructions' => 'A year (2014), a month (2014-03) or a day (2014-03-01).',
        ],
        'publisher_description' => ['display' => 'Description'],
        'publisher_logo' => ['display' => 'Logo or portrait'],
        'job_title' => ['display' => 'Job title'],
        'telephone' => ['display' => 'Telephone'],
        'email' => ['display' => 'Email'],
        'area_served' => ['display' => 'Area served'],
        'same_as' => [
            'display' => 'Profiles elsewhere',
        ],
        'contact_points' => [
            'display' => 'Contact points',
            'add_row' => 'Add a contact point',
        ],
        'contact_type' => [
            'display' => 'For',
            'placeholder' => 'customer service',
        ],
        'street_address' => ['display' => 'Street address'],
        'address_locality' => ['display' => 'City'],
        'address_region' => ['display' => 'State or region'],
        'postal_code' => ['display' => 'Postcode'],
        'address_country' => ['display' => 'Country code'],
        'price_range' => ['display' => 'Price range'],
        'latitude' => ['display' => 'Latitude'],
        'longitude' => ['display' => 'Longitude'],
        'opening_hours' => [
            'display' => 'Opening hours',
            'add_row' => 'Add hours',
        ],
        'days' => [
            'display' => 'Days',
            'options' => [
                'monday' => 'Mon',
                'tuesday' => 'Tue',
                'wednesday' => 'Wed',
                'thursday' => 'Thu',
                'friday' => 'Fri',
                'saturday' => 'Sat',
                'sunday' => 'Sun',
            ],
        ],
        'opens' => ['display' => 'Opens'],
        'closes' => ['display' => 'Closes'],

        // Shop
        'currency' => [
            'display' => 'Currency',
        ],
        'return_category' => [
            'display' => 'Returns',
            'options' => [
                'finite_window' => 'Within a number of days',
                'unlimited_window' => 'Any time',
                'not_permitted' => 'Not accepted',
            ],
        ],
        'return_days' => ['display' => 'Days to return'],
        'return_country' => ['display' => 'Country code'],
        'return_policy_link' => [
            'display' => 'Return policy page',
        ],
        'shipping_rates' => [
            'display' => 'Shipping rates',
            'add_row' => 'Add a rate',
        ],
        'country' => ['display' => 'Country'],
        'region' => ['display' => 'Region'],
        'min_order' => ['display' => 'Orders from'],
        'max_order' => ['display' => 'Orders up to'],
        'rate' => ['display' => 'Rate'],
        'min_days' => ['display' => 'Days, from'],
        'max_days' => ['display' => 'Days, to'],

        // Share cards
        'og_background' => ['display' => 'Background'],
        'og_text' => ['display' => 'Text'],
        'og_accent' => ['display' => 'Accent'],
        'og_logo' => [
            'display' => 'Logo',
            'instructions' => 'Drawn on every generated card. A PNG with a transparent background works best. Without one, the publisher logo is used, or else the site\'s name. SVG isn\'t accepted, because most servers can\'t draw it onto an image.',
        ],
        'og_picture' => [
            'display' => 'Picture',
            'instructions' => 'A portrait or product photo, drawn as a square on the right of every generated card.',
        ],

        // Crawlers
        'google_verification' => ['display' => 'Google verification'],
        'bing_verification' => ['display' => 'Bing verification'],
        'yandex_verification' => ['display' => 'Yandex verification'],
        'pinterest_verification' => ['display' => 'Pinterest verification'],
        'robots_disallow' => [
            'display' => 'robots.txt Disallow',
        ],
        'allow_ai_training' => [
            'display' => 'Allow AI training',
        ],
        'allow_ai_search' => [
            'display' => 'Allow AI search',
        ],
        'content_signals' => [
            'display' => 'Content signals',
            'instructions' => 'Adds a Content-Signal line to robots.txt that says what the two AI switches allow: search, AI answers (AI search) and AI training. Turn it off if Cloudflare\'s managed robots.txt adds its own line, or the file says it twice.',
        ],
        'robots_extra' => [
            'display' => 'robots.txt extra lines',
        ],
    ],

];
