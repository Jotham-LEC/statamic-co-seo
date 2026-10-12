<?php

namespace JothamLec\MarketingToolkit\Tracking;

use Illuminate\Support\Facades\Vite;
use JothamLec\MarketingToolkit\Settings;
use JothamLec\MarketingToolkit\Support\Features;
use Statamic\Facades\Site;

/**
 * Provides the tracking tags of the current site and its Consent Mode defaults. The IDs come from
 * the "Tracking" tab of the Marketing settings global, each site's own, and config/marketing-toolkit.php
 * (and so .env) wins over it, for every site or, keyed by site handle, for one. <s:mt:head /> and <s:mt:body /> print them, in production only and never
 * in Live Preview.
 *
 * To change it, override a method in a subclass bound in its place in the container, for
 * example ids() to read the IDs from somewhere else.
 */
class Tracking
{
    /** Maps each tracker to what its ID looks like. Anything else is ignored and never printed. */
    public const array PATTERNS = [
        'gtm' => '/^GTM-[A-Z0-9]{4,12}$/',
        'ga4' => '/^G-[A-Z0-9]{4,16}$/',
        'posthog' => '/^phc_[A-Za-z0-9]{16,64}$/',
        'meta' => '/^\d{6,20}$/',
        'linkedin' => '/^\d{3,12}$/',
    ];

    /**
     * Maps each tracker to its key, which is the field's handle in the global's Tracking tab and the
     * key under `marketing-toolkit.tracking`. In .env, it is MT_ followed by the key in capitals.
     */
    public const array FIELDS = [
        'gtm' => 'gtm_id',
        'ga4' => 'ga4_id',
        'posthog' => 'posthog_key',
        'meta' => 'meta_pixel_id',
        'linkedin' => 'linkedin_partner_id',
    ];

    /** These are the four Consent Mode v2 signals. */
    public const array SIGNALS = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];

    /** This region choice stands for the EEA, the UK and Switzerland. */
    public const string EEA = 'EEA';

    /** These are the EU, EEA, UK and Swiss country codes, where consent comes before tracking. */
    public const array EEA_REGIONS = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH',
    ];

    public function __construct(protected Settings $settings) {}

    /**
     * Determines whether the tags print on this request. They print in the environments that
     * `marketing-toolkit.tracking.environments` lists (production), on a site whose Tracking
     * tab leaves them on, and never in Live Preview.
     */
    public function enabled(): bool
    {
        return Features::on('tracking')
            && $this->onThisSite()
            && app()->environment((array) config('marketing-toolkit.tracking.environments'))
            && ! request()->isLivePreview();
    }

    /**
     * Determines whether the current site prints its tags, which is the Tracking tab's first
     * toggle. A site that never saved it takes its origin's, so a site of a multisite turns
     * them off on its own by turning the toggle off in its localization.
     */
    public function onThisSite(): bool
    {
        return $this->settings->bool('site_tracking', true);
    }

    /**
     * Returns a key of `marketing-toolkit.tracking` for the current site. The key holds one
     * value for every site, or values keyed by site handle (`['default' => 'GTM-…', 'blog' => 'GTM-…']`),
     * where a site left out has none from the config and takes the Tracking tab's.
     */
    public function configured(string $key): ?string
    {
        $value = config('marketing-toolkit.tracking.'.$key);

        if (is_array($value)) {
            $value = $value[Site::current()->handle()] ?? null;
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * Returns each tracker's ID, or null where it has none. It is also null where the entry doesn't
     * look like an ID, because the ID is printed into a script.
     *
     * @return array{gtm: ?string, ga4: ?string, posthog: ?string, meta: ?string, linkedin: ?string}
     */
    public function ids(): array
    {
        return collect($this->entered())->map(fn (?string $id, string $tracker) => $id !== null && preg_match(self::PATTERNS[$tracker], $id) ? $id : null)->all();
    }

    /**
     * Returns the trackers with something entered that isn't an ID, and so isn't printed, as
     * tracker => what was entered.
     *
     * @return array<string, string>
     */
    public function invalid(): array
    {
        return array_diff_key(array_filter($this->entered(), fn (?string $id) => $id !== null), array_filter($this->ids()));
    }

    /**
     * Returns which trackers come from config/marketing-toolkit.php (or .env), and so can't be
     * changed in the control panel.
     *
     * @return array<string, bool>
     */
    public function fromConfig(): array
    {
        return collect(self::FIELDS)->map(fn (string $key) => $this->configured($key) !== null)->all();
    }

    /**
     * Returns what each tracker has, from the config or else the global, tidied but not checked.
     *
     * @return array<string, ?string>
     */
    protected function entered(): array
    {
        return collect(self::FIELDS)->map(function (string $key, string $tracker) {
            $id = trim((string) ($this->configured($key) ?? $this->settings->string($key)));

            return $id === '' ? null : (in_array($tracker, ['gtm', 'ga4'], true) ? strtoupper($id) : $id);
        })->all();
    }

    /**
     * Returns PostHog's API host for the project's region, which is `https://us.i.posthog.com` unless set.
     */
    public function posthogHost(): string
    {
        $host = rtrim(trim((string) ($this->configured('posthog_host') ?? $this->settings->string('posthog_host'))), '/');

        return preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $host) ? $host : 'https://us.i.posthog.com';
    }

    /**
     * Returns the address of PostHog's app, for its toolbar and links back to it. It is the value set
     * for a proxy, as with the host, or else, on PostHog's own cloud, the host without `.i`, or else null.
     */
    public function posthogUiHost(): ?string
    {
        $host = rtrim(trim((string) ($this->configured('posthog_ui_host') ?? $this->settings->string('posthog_ui_host'))), '/');

        if (preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $host)) {
            return $host;
        }

        $api = $this->posthogHost();

        return str_ends_with($api, '.i.posthog.com') ? str_replace('.i.posthog.com', '.posthog.com', $api) : null;
    }

    /**
     * Returns the names of the trackers loaded beside Google Tag Manager, for a warning, because each
     * visit is counted twice if GTM loads them as well.
     *
     * @return list<string>
     */
    public function besideGtm(): array
    {
        $ids = $this->ids();

        if ($ids['gtm'] === null) {
            return [];
        }

        return array_map(
            fn (string $tracker) => (string) __('marketing-toolkit::cp.tracking.names.'.$tracker),
            array_keys(array_filter(array_diff_key($ids, ['gtm' => true]))),
        );
    }

    /**
     * Returns the Consent Mode v2 defaults for a cookie banner that updates them, or
     * null while Consent Mode is off. When regions are set, the
     * defaults apply there, and everything is granted elsewhere.
     *
     * @return array{defaults: array<string, string>, regions: list<string>, wait_for_update: int}|null
     */
    public function consent(): ?array
    {
        if (! $this->settings->bool('consent_mode')) {
            return null;
        }

        $defaults = collect(self::SIGNALS)->mapWithKeys(fn (string $signal) => [
            $signal => $this->settings->string('consent_'.$signal) === 'granted' ? 'granted' : 'denied',
        ])->all();

        return [
            'defaults' => $defaults,
            'regions' => $this->regions(),
            'wait_for_update' => max(0, min(10000, (int) ($this->settings->string('consent_wait_for_update') ?? 500))),
        ];
    }

    /**
     * Returns ISO 3166-1 or 3166-2 codes (`FR`, `US-CA`), with EEA standing for the EEA, the UK and Switzerland.
     *
     * @return list<string>
     */
    protected function regions(): array
    {
        return collect($this->settings->list('consent_regions'))
            ->flatMap(fn (string $region) => strtoupper(trim($region)) === self::EEA ? self::EEA_REGIONS : [strtoupper(trim($region))])
            ->filter(fn (string $region) => preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $region) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Returns what goes high in the <head>, after <meta charset>, which is the Consent Mode defaults and then the tags.
     */
    public function head(): string
    {
        return $this->enabled() ? view('marketing-toolkit::tracking-head', $this->viewData())->render() : '';
    }

    /**
     * Returns what goes right after <body>, which is the tags' <noscript> fallbacks.
     */
    public function body(): string
    {
        return $this->enabled() ? view('marketing-toolkit::tracking-body', $this->viewData())->render() : '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        $ids = $this->ids();
        $consent = $this->consent();

        $attribution = $this->attribution();
        // Trackers that don't read Google's Consent Mode, and the attribution cookie, wait for the banner's answer.
        $bridge = $consent !== null && ($ids['posthog'] || $ids['meta'] || $ids['linkedin'] || $attribution);
        $host = $this->posthogHost();

        return [
            'ids' => $ids,
            'consent' => $consent,
            'consentDefaults' => $this->consentDefaults($consent),
            'bridge' => $bridge,
            'posthogOptions' => array_filter([
                'api_host' => $host,
                'ui_host' => $this->posthogUiHost(),
                'person_profiles' => 'identified_only',
                'opt_out_capturing_by_default' => $bridge ?: null,
                'persistence' => $bridge ? 'memory' : null,
            ]),
            // This is where PostHog's snippet loads its library from, so the bridge can load it
            // once analytics is granted.
            'posthogScript' => str_replace('.i.posthog.com', '-assets.i.posthog.com', $host).'/static/array.js',
            'conversions' => $this->conversions() && array_filter($ids) !== [],
            'linkedinConversion' => $this->linkedinConversion(),
            'attribution' => $attribution,
            'nonce' => Vite::cspNonce(),
        ];
    }

    /**
     * Determines whether a form submission is sent to the tools as a lead.
     */
    public function conversions(): bool
    {
        return Features::on('leads') && $this->settings->bool('conversions', true);
    }

    /**
     * Returns the LinkedIn conversion that a form submission counts as, if there is one.
     */
    public function linkedinConversion(): ?string
    {
        $id = trim((string) $this->settings->string('linkedin_conversion_id'));

        return $this->conversions() && preg_match(self::PATTERNS['linkedin'], $id) ? $id : null;
    }

    /**
     * Determines whether to remember where each visitor first came from, for their submissions.
     */
    public function attribution(): bool
    {
        return Features::on('leads') && $this->settings->bool('attribution');
    }

    /**
     * Builds the gtag('consent', 'default', …) commands. Without regions, the defaults apply everywhere;
     * with regions, everything is granted and the defaults apply in those regions. Both wait for the
     * banner, because a visitor outside the regions who declined is denied by its update, which must
     * arrive before the first hit.
     *
     * @param  array{defaults: array<string, string>, regions: list<string>, wait_for_update: int}|null  $consent
     * @return list<array<string, mixed>>
     */
    protected function consentDefaults(?array $consent): array
    {
        if ($consent === null) {
            return [];
        }

        $wait = ['wait_for_update' => $consent['wait_for_update']];

        return $consent['regions'] === []
            ? [[...$consent['defaults'], ...$wait]]
            : [[...array_fill_keys(self::SIGNALS, 'granted'), ...$wait], [...$consent['defaults'], 'region' => $consent['regions'], ...$wait]];
    }
}
