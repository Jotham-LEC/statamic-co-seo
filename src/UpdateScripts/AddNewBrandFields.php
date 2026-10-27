<?php

namespace JothamLec\MarketingToolkit\UpdateScripts;

use JothamLec\MarketingToolkit\Commands\Install;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;

/**
 * Adds the fields that the new version brings to the Brand and Marketing settings blueprints, in
 * the tabs the site kept, as `mt:install` would add them. It adds only those, so a field the site
 * removed stays removed through every later update. When updating from before 0.20 (when this
 * script arrived, and fields were added by hand), every field the blueprints lack is added.
 * Statamic runs it on `composer update` (or `php please updates:run`), and you should commit the
 * blueprints it changes.
 */
final class AddNewBrandFields extends UpdateScript
{
    /**
     * Lists the fields each version from 0.20 brought, keyed by that version. A field
     * added to resources/install must be listed here, or no update adds it.
     */
    public const array FIELDS = [
        '0.21.0' => [
            'feature_sitemap', 'feature_robots_txt', 'feature_llms_txt', 'feature_ads_txt', 'feature_hreflang', 'feature_indexnow',
            'feature_redirects', 'feature_automatic_redirects', 'feature_not_found', 'feature_reports', 'feature_share_cards',
            'feature_favicons', 'feature_tracking', 'feature_leads',
        ],
        '0.22.0' => ['feature_toolbar'],
        '0.23.0' => ['title_brand', 'legal_name', 'posthog_ui_host'],
        '0.25.0' => ['og_logo'],
        '0.27.2' => ['site_tracking'],
        '0.30.0' => ['content_signals'],
    ];

    /** @var list<string>|null the fields this update brings, as shouldUpdate() found them */
    private ?array $fields = [];

    public function shouldUpdate($newVersion, $oldVersion)
    {
        $this->fields = self::since((string) $oldVersion, (string) $newVersion);

        return $this->fields !== [] && Blueprint::find('globals.'.config('marketing-toolkit.global')) !== null;
    }

    public function update(): void
    {
        $container = Install::containerOf(Blueprint::find('globals.'.config('marketing-toolkit.global'))) ?? AssetContainer::all()->first()?->handle();

        if ($container === null || $this->fields === []) {
            return;
        }

        foreach (Install::SETS as $key => $set) {
            $blueprint = Blueprint::find('globals.'.config('marketing-toolkit.'.$key));
            $added = $blueprint ? Install::addMissingFields($blueprint, $container, $set['file'], $this->fields) : [];

            if ($added !== []) {
                $this->console()->info("Marketing Toolkit added to {$set['title']}: ".implode(', ', $added).'.');
            }
        }
    }

    /**
     * Returns the fields brought after the old version, up to the new one, or null for all of
     * them when updating from before 0.20. An old version that isn't a release (a branch)
     * brings none, and `mt:install` adds what is missing.
     *
     * @return list<string>|null
     */
    public static function since(string $oldVersion, string $newVersion): ?array
    {
        if (! self::isRelease($oldVersion)) {
            return [];
        }

        if (self::before($oldVersion, '0.20.0')) {
            return null;
        }

        return collect(self::FIELDS)
            ->filter(fn (array $fields, string $version) => self::after($version, $oldVersion) && ! self::after($version, $newVersion))
            ->flatten()
            ->values()
            ->all();
    }
}
