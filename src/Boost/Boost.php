<?php

namespace JothamLec\MarketingToolkit\Boost;

use JothamLec\MarketingToolkit\Boost\Tools\MarketingToolkitPageSeo;
use JothamLec\MarketingToolkit\Boost\Tools\MarketingToolkitRedirect;
use JothamLec\MarketingToolkit\Boost\Tools\MarketingToolkitReport;
use Laravel\Boost\BoostServiceProvider;

/**
 * The addon's place in Laravel Boost, for AI coding agents. Boost finds the guidelines and skills
 * in resources/boost by itself (offered by `php artisan boost:install`); the read-only MCP tools
 * join Boost's own server here, beside its tools and named so as not to clash with them. Without
 * Boost, or with `marketing-toolkit.boost.tools` off, nothing is added.
 */
final class Boost
{
    /** @var list<class-string> */
    public const array TOOLS = [
        MarketingToolkitPageSeo::class,
        MarketingToolkitReport::class,
        MarketingToolkitRedirect::class,
    ];

    public static function register(): void
    {
        if (! class_exists(BoostServiceProvider::class) || ! config('marketing-toolkit.boost.tools', true)) {
            return;
        }

        config(['boost.mcp.tools.include' => array_values(array_unique([...(array) config('boost.mcp.tools.include', []), ...self::TOOLS]))]);
    }
}
