<?php

namespace JothamLec\MarketingToolkit\Support;

use Statamic\Facades\Addon;
use Throwable;

/**
 * Lists the modules, each of which is switched on by one config key. A site can switch them off
 * in config/marketing-toolkit.php or under Features in the control panel, which keeps them in the
 * addon settings (`features_off`). A module that's off is set off in the config at boot, before
 * the routes, listeners and middleware register, so it costs nothing on a request.
 */
final class Features
{
    /** Maps each module to the config key that switches it on. */
    public const array MODULES = [
        'sitemap' => 'marketing-toolkit.sitemap.enabled',
        'robots_txt' => 'marketing-toolkit.robots_txt.enabled',
        'llms_txt' => 'marketing-toolkit.llms_txt.enabled',
        'markdown_negotiation' => 'marketing-toolkit.markdown.enabled',
        'ads_txt' => 'marketing-toolkit.ads_txt.enabled',
        'hreflang' => 'marketing-toolkit.hreflang.enabled',
        'indexnow' => 'marketing-toolkit.indexnow.enabled',
        'share_cards' => 'marketing-toolkit.og.enabled',
        'redirects' => 'marketing-toolkit.redirects.enabled',
        'automatic_redirects' => 'marketing-toolkit.redirects.automatic',
        'not_found' => 'marketing-toolkit.not_found.enabled',
        'reports' => 'marketing-toolkit.reports.enabled',
        'tracking' => 'marketing-toolkit.tracking.enabled',
        'leads' => 'marketing-toolkit.leads.enabled',
        'favicons' => 'marketing-toolkit.favicons.enabled',
        'toolbar' => 'marketing-toolkit.toolbar.enabled',
    ];

    public const string SETTING = 'features_off';

    public static function on(string $module): bool
    {
        return (bool) config(self::MODULES[$module]);
    }

    /**
     * Returns the modules switched off, as saved.
     *
     * @return list<string>
     */
    public static function off(): array
    {
        try {
            $saved = Addon::get(Package::NAME)?->settings()->get(self::SETTING);
        } catch (Throwable $exception) {
            // This is asked while booting, so the error is logged but never gets in the way of the boot.
            rescue(fn () => report($exception), report: false);

            return [];
        }

        return array_values(array_intersect(array_keys(self::MODULES), is_array($saved) ? $saved : []));
    }

    /**
     * Returns the modules that config/marketing-toolkit.php switches off, rather than this screen. They
     * are shown as off there, and locked, since a switch can't turn them back on.
     *
     * @return list<string>
     */
    public static function offInConfig(): array
    {
        $off = self::off();

        return array_values(array_filter(array_keys(self::MODULES), fn (string $module) => ! in_array($module, $off, true)
            && ! config(self::MODULES[$module], true)));
    }

    /**
     * Sets the switched-off modules to off in the config. This runs at every boot rather than in the
     * merged config, which isn't merged once it is cached.
     */
    public static function apply(): void
    {
        foreach (self::off() as $module) {
            config([self::MODULES[$module] => false]);
        }
    }

    /**
     * @param  list<string>  $off
     */
    public static function save(array $off): void
    {
        $settings = Addon::get(Package::NAME)->settings();
        $settings->set(self::SETTING, array_values(array_intersect(array_keys(self::MODULES), $off)) ?: null);
        $settings->save();
    }
}
