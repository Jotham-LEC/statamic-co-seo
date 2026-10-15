<?php

namespace JothamLec\MarketingToolkit\NotFound;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Facades\Site;

/**
 * This class counts a 404 against its path (and its site, where there are several). Bots (known by their
 * user agent) and scanner probes (known by their path) are left out. The table keeps about
 * `marketing-toolkit.not_found.max_rows` paths, dropping one-off misses first and then paths first seen
 * in the last day, with the least recently seen first in each group (see MissingPath::prunable()).
 */
class Recorder
{
    public function shouldRecord(Request $request): bool
    {
        // HandleMissing only asks about GET and HEAD requests, so the method isn't checked here.
        if (! Features::on('not_found')) {
            return false;
        }

        $agent = strtolower((string) $request->userAgent());

        foreach ((array) config('marketing-toolkit.not_found.ignore_user_agents') as $bot) {
            if ($bot !== '' && str_contains($agent, strtolower($bot))) {
                return false;
            }
        }

        $path = $this->path($request);
        $ignore = (array) config('marketing-toolkit.not_found.ignore_paths');

        // Postgres refuses text that isn't UTF-8, and such a path can only be a probe. A probe is recognised
        // by the path within the site (`/fr/.env` is `/.env` there) or by the path as requested.
        return self::isText($path) && ! Str::is($ignore, $path) && ! Str::is($ignore, '/'.trim($request->decodedPath(), '/'));
    }

    public function record(Request $request): void
    {
        $path = $this->path($request);

        if (strlen($path) > Rule::MAX_SOURCE) {
            return;
        }

        $site = Sites::scope(Site::current()->handle());
        $referrer = self::webAddress($request->headers->get('referer'));
        $now = now();

        $updated = MissingPath::query()->ofSite($site)->where('path', $path)->update(array_filter([
            'hits' => DB::raw('hits + 1'),
            'last_seen_at' => $now,
            'referrer' => $referrer,
        ]));

        if ($updated) {
            return;
        }

        try {
            MissingPath::query()->create(['site' => $site, 'path' => $path, 'hits' => 1, 'referrer' => $referrer, 'first_seen_at' => $now, 'last_seen_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            // Another request recorded the same path a moment ago, so we add to its count instead.
            MissingPath::query()->ofSite($site)->where('path', $path)->increment('hits', 1, ['last_seen_at' => $now]);

            return;
        }

        $this->trim();
    }

    /**
     * Returns the missed path within the current site (without its folder), in the form that redirects take
     * their sources, so a row's "Create redirect" makes a rule that matches it. Rows logged earlier kept
     * the folder (`/fr/old`), and the matcher still accepts a source like that.
     */
    public function path(Request $request): string
    {
        $path = '/'.trim($request->decodedPath(), '/');

        return '/'.trim(Sites::within($path, Site::current()->handle()) ?? $path, '/');
    }

    /**
     * Returns an http(s) address that is fit to store and to link to, or null. We check it because the
     * referrer is whatever the request says, including a `javascript:` address.
     */
    public static function webAddress(?string $url): ?string
    {
        return $url !== null && strlen($url) <= 2048 && self::isText($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    private static function isText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /**
     * Trims the log on a site without the scheduler, which otherwise prunes it daily (`model:prune`).
     * Counting the rows for every new path would cost a full count per 404, so only one new path in a
     * tenth of the cap trims the log, as a lottery, in the same way Laravel sweeps sessions. The log can
     * run over by about a tenth, and a small cap is kept exactly.
     */
    private function trim(): void
    {
        $max = MissingPath::maxRows();

        if (random_int(1, max(1, intdiv($max, 10))) === 1 && MissingPath::query()->count() > $max) {
            (new MissingPath)->pruneAll();
        }
    }
}
