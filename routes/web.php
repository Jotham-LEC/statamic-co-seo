<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use JothamLec\MarketingToolkit\Favicons\Favicons;
use JothamLec\MarketingToolkit\Http\Controllers\FaviconController;
use JothamLec\MarketingToolkit\Http\Controllers\IndexNowKeyController;
use JothamLec\MarketingToolkit\Http\Controllers\OgImageController;
use JothamLec\MarketingToolkit\Http\Controllers\RobotsController;
use JothamLec\MarketingToolkit\Http\Controllers\SitemapController;
use JothamLec\MarketingToolkit\Http\Controllers\TextFileController;
use JothamLec\MarketingToolkit\IndexNow\IndexNow;

/*
 * Statamic registers these routes with its front-end routes (the `web` group),
 * ahead of the catch-all. They aren't pages, because Statamic's own
 * `statamic.web` middleware, static caching included, runs only for the
 * catch-all. None of them needs a session or a CSRF token, and a Set-Cookie
 * header would stop Cloudflare and browsers from caching the images and the
 * sitemap.
 */
Route::withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    AddQueuedCookiesToResponse::class,
    // These are Laravel 13's CSRF middleware and its older names. Statamic's own routes list the same three.
    'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
    'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
    'App\\Http\\Middleware\\VerifyCsrfToken',
])
    ->name('mt.')
    ->group(function () {
        // These routes are registered whatever the config says, so cached routes follow a module
        // switched on or off later, and each controller answers 404 while its module is off. A
        // file of the same name in public/ wins, since the web server serves it before Laravel
        // runs. So does a route of the site's own for the address (its own sitemap.xml, say),
        // because $get registers a route only for an address the site hasn't taken. It returns
        // null for one it has, which the `?->` after each call then skips.
        $taken = fn (string $uri) => array_key_exists($uri, Route::getRoutes()->get('GET'));
        $get = fn (string $uri, array|string $action) => $taken($uri) ? null : Route::get($uri, $action);

        $get('sitemap.xml', [SitemapController::class, 'index'])?->name('sitemap');
        $get('sitemap_{page}.xml', [SitemapController::class, 'page'])?->whereNumber('page')->name('sitemap.page');
        $get('news-sitemap.xml', [SitemapController::class, 'news'])?->name('sitemap.news');
        $get('robots.txt', RobotsController::class)?->name('robots');
        $get(app(IndexNow::class)->key().'.txt', IndexNowKeyController::class)?->name('indexnow.key');
        $get('llms.txt', [TextFileController::class, 'llms'])?->name('llms');
        $get('ads.txt', [TextFileController::class, 'ads'])?->name('ads');

        foreach (array_keys(Favicons::FILES) as $file) {
            $get($file, FaviconController::class)?->name('favicons.'.$file);
        }

        $get('og.png', OgImageController::class)?->name('og.home');
        $get('og.{shape}.png', [OgImageController::class, 'home'])?->where('shape', '1x1|4x3')->name('og.home.shape');
        $get('og/{path}.png', OgImageController::class)?->where('path', '.*')->name('og');
    });
