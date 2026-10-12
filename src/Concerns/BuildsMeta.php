<?php

namespace JothamLec\MarketingToolkit\Concerns;

use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\Meta;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Og\Shape;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Fields;
use JothamLec\MarketingToolkit\Support\Text;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;

/**
 * This trait builds the page's meta tags, which are the title, description,
 * share image, canonical, robots, and the Open Graph extras.
 *
 * @phpstan-require-extends SiteSeo
 */
trait BuildsMeta
{
    /** @api */
    public function meta(Context $context): Meta
    {
        $canonical = $this->canonical($context);
        $title = $this->title($context);
        $alternates = $context->status >= 400 ? [] : $this->alternates($context);

        return new Meta(
            title: $title,
            description: $this->description($context),
            canonical: $canonical,
            robots: $this->robots($context),
            ogTitle: $this->ogTitle($context),
            ogType: $this->ogType($context),
            url: $this->url($context),
            siteName: $this->settings->siteName(),
            locale: $this->ogLocale($this->contentSite($context)),
            image: $this->image($context),
            published: $this->published($context),
            modified: $this->modified($context),
            twitterSite: $this->twitterSite(),
            verification: $this->verification(),
            graph: $context->status >= 400 ? [] : $this->graph($context),
            alternates: $alternates,
            localeAlternates: $alternates === [] ? [] : $this->localeAlternates($context),
        );
    }

    /** @api */
    public function title(Context $context): string
    {
        $site = $this->settings->siteName();
        $suffix = $context->page() > 1
            ? $this->settings->separator().__('marketing-toolkit::frontend.page', ['n' => $context->page()], $this->contentSite($context)->lang())
            : '';

        // The editor's SEO title is used as the whole <title>, exactly as typed.
        if ($title = $context->seo()['title'] ?? null) {
            return $title.$suffix;
        }

        $fieldTitle = $this->fieldTitle($context);

        if ($context->isHome() && ! $context->override('title') && $fieldTitle === null) {
            return $site.$suffix;
        }

        // A template's title, or one from the collection's title_fields, replaces the content's and gets the site name.
        $title = $context->override('title') ?? $fieldTitle ?? $this->contentTitle($context);

        if ($title === null) {
            return $site.$suffix;
        }

        if (! $this->settings->titleSiteName()) {
            return $title.$suffix;
        }

        $full = $title.$this->settings->separator().$this->settings->titleName();

        return (mb_strlen($full.$suffix) <= (int) config('marketing-toolkit.title.max') ? $full : $title).$suffix;
    }

    /** @api */
    public function ogTitle(Context $context): string
    {
        return $context->override('title') ?? $context->seo()['title'] ?? $this->fieldTitle($context) ?? $this->contentTitle($context) ?? $this->settings->siteName();
    }

    /**
     * Returns the page's title from a field that the collection (or taxonomy)
     * names in `title_fields`, ahead of its own title, for example the
     * `meta_title` or `seo_title` a site kept from another SEO addon. The first
     * field that has text wins (a text or a Bard field, read as plain text),
     * and a name with dots is a field in a Replicator's sets, as it is for
     * description_fields.
     */
    protected function fieldTitle(Context $context): ?string
    {
        $content = $context->content();

        foreach ($content ? $this->contentConfig($context, 'title_fields', []) : [] as $field) {
            if ($value = $this->rawValues($content, $field)->map(fn ($value) => is_array($value) ? $this->fieldText($value) : $value)->first(fn ($value) => is_string($value) && filled($value))) {
                return trim($value);
            }
        }

        return null;
    }

    /** @api */
    public function description(Context $context): ?string
    {
        // The meta tags and the JSON-LD nodes each ask for this, so the body is read only once per page.
        return $this->once($context, 'description', function () use ($context) {
            $text = $context->override('description')
                ?? $context->seo()['description']
                ?? $this->contentDescription($context)
                ?? $this->settings->string('default_description');

            return $text === null ? null : Text::limit(Text::plain($text), (int) config('marketing-toolkit.description.length'));
        });
    }

    /**
     * Returns the share image. It tries an override, the entry's uploaded
     * image, an image field the collection names, the generated card, and
     * then the site default, in that order.
     *
     * @return array{url: string, width: int, height: int, alt: ?string}|null
     *
     * @api
     */
    public function image(Context $context): ?array
    {
        // The meta tags and the Article node both ask for this, so it is worked out once per page.
        return $this->once($context, 'image', function () use ($context) {
            if ($url = $context->override('image')) {
                return ['url' => $this->absolute($url), 'width' => $this->imageWidth(), 'height' => $this->imageHeight(), 'alt' => null];
            }

            if ($asset = $this->shareAsset($context)) {
                return $this->cropped($asset);
            }

            // The card shows the page's title, so its alt text gives that title to someone who can't see it.
            $content = $context->content();
            $url = match (true) {
                $context->status >= 400 => null,
                $content instanceof Entry => $this->generatedImageUrl($content),
                $content instanceof Term => $this->generatedTermImageUrl($content),
                default => null,
            };
            if ($url) {
                return ['url' => $url, 'width' => $this->imageWidth(), 'height' => $this->imageHeight(), 'alt' => $context->seo()['og_title'] ?? $this->contentTitle($context)];
            }

            if ($asset = $this->settings->asset('default_image')) {
                return $this->cropped($asset);
            }

            return null;
        });
    }

    /**
     * Returns the page's own uploaded share image, which is its SEO image or
     * else an image field the collection names. A name with dots is a field in
     * a Replicator's sets (`sections.hero.image`, with `*` for any set), and
     * the first visible set that has one is used.
     */
    public function shareAsset(Context $context): ?Asset
    {
        $content = $context->content();

        foreach ($content ? ['seo', ...$this->contentConfig($context, 'image_fields', [])] : [] as $field) {
            if ($asset = $this->assetFrom($content, $field)) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Returns the URL of the entry's generated card. It returns null when cards
     * are off, when this host can't draw them (without Imagick), or when the
     * entry is protected, because its card would show what it protects. The
     * `v` parameter changes with each edit, so link previews fetch it again.
     * In a Live Preview of the entry, the URL carries the preview's token, so
     * the card shows the unsaved values, and a draft has one too.
     *
     * The URL sits on the root of the entry's domain, which serves the card
     * routes, followed by the page's path from that root. For example, the
     * card for /fr/a-propos is /og/fr/a-propos.png, and the controller finds
     * the site from the path in the same way Statamic finds a page's site.
     */
    public function generatedImageUrl(Entry $entry): ?string
    {
        return $this->shareCardUrl($entry);
    }

    /**
     * Returns the URL of a taxonomy term's generated card, as
     * generatedImageUrl() does for an entry: /og/topics/gardens.png for the
     * term page /topics/gardens.
     */
    public function generatedTermImageUrl(Term $term): ?string
    {
        return $this->shareCardUrl($term);
    }

    /**
     * Returns the address of the entry's card in a shape, such as
     * /og/fr/a-propos.1x1.png, without checking that it has one (see
     * generatedImageUrl()).
     */
    public function cardUrl(Entry $entry, Shape $shape): string
    {
        return $this->cardAddress($entry, $shape);
    }

    private function shareCardUrl(Entry|Term $content): ?string
    {
        $previewed = Fields::isPreviewed($content);

        if (! Features::on('share_cards') || (! $previewed && $content->status() !== 'published') || ! $content->url() || $this->isProtected($content)
            || ! app(Generator::class)->available()) {
            return null;
        }

        // A term being created has no file yet, so no time to version its card with, and no card.
        if ($content instanceof Term && ! is_file((string) $content->term()->path())) {
            return null;
        }

        return $this->cardAddress($content, Shape::Landscape);
    }

    private function cardAddress(Entry|Term $content, Shape $shape): string
    {
        $absolute = (string) $content->absoluteUrl();
        $path = trim((string) parse_url($absolute, PHP_URL_PATH), '/');
        $route = ($path === '' ? 'og' : 'og/'.$path).$shape->suffix().'.png';
        $token = Fields::isPreviewed($content) ? '&token='.request()->statamicToken()?->token() : '';

        return self::domainRoot($absolute).'/'.$route.'?v='.$content->lastModified()->timestamp.$token;
    }

    /**
     * Returns where search engines should send the ranking. That is an
     * override, the original a republished piece points at, or else this page
     * (with `?page=N` after the first page). An override of `false` prints no
     * canonical at all.
     *
     * @api
     */
    public function canonical(Context $context): ?string
    {
        $override = $context->override('canonical');

        if ($override === false || $context->status >= 400) {
            return null;
        }

        if ($override || ($override = $context->seo()['canonical'] ?? null)) {
            return $override;
        }

        return $this->url($context);
    }

    /**
     * Returns this page's own address (og:url), which is the entry's or else
     * the request's, and never has a query string other than `page`.
     */
    public function url(Context $context): string
    {
        $url = $context->content()?->absoluteUrl() ?? $context->request->url();

        return $context->page() > 1 ? $url.'?page='.$context->page() : $url;
    }

    /** @api */
    public function robots(Context $context): string
    {
        $seo = $context->seo();
        $noindex = $context->override('noindex') ?? (bool) ($seo['noindex'] ?? false);
        $nofollow = (bool) ($seo['nofollow'] ?? false);

        if ($noindex || $this->shouldNoindex($context)) {
            return implode(', ', ['noindex', $nofollow ? 'nofollow' : 'follow']);
        }

        // Not following links leaves the snippet and image preview rules as they were.
        return implode(', ', array_filter([$nofollow ? 'nofollow' : null, $this->snippetRules($context)]));
    }

    /**
     * Returns the default rules with the page's own snippet limit applied.
     * `nosnippet` keeps the page's text out of results and out of Google's AI
     * Overviews and AI Mode, while a maximum length caps what is quoted.
     *
     * @api
     */
    protected function snippetRules(Context $context): string
    {
        $seo = $context->seo();
        $rules = (string) config('marketing-toolkit.robots.default');

        if ($seo['nosnippet'] ?? false) {
            return trim((string) preg_replace('/max-snippet:-?\d+/', 'nosnippet', $rules)) ?: 'nosnippet';
        }

        if (isset($seo['max_snippet']) && is_numeric($seo['max_snippet']) && (int) $seo['max_snippet'] >= 0) {
            $limit = 'max-snippet:'.(int) $seo['max_snippet'];

            return str_contains($rules, 'max-snippet:') ? (string) preg_replace('/max-snippet:-?\d+/', $limit, $rules) : trim("{$limit}, {$rules}", ', ');
        }

        return $rules;
    }

    /**
     * Determines whether search engines are kept off this copy of the site,
     * with every page noindexed and robots.txt disallowing all. This applies
     * unless APP_ENV is production (config
     * `marketing-toolkit.robots.noindex_outside_production`).
     *
     * @api
     */
    protected function hiddenOutsideProduction(): bool
    {
        return config('marketing-toolkit.robots.noindex_outside_production') && ! app()->isProduction();
    }

    /**
     * Checks the site-wide reasons to keep a page out of the index. Override
     * this method to add your own, such as an empty taxonomy listing or a
     * thank-you page.
     *
     * @api
     */
    public function shouldNoindex(Context $context): bool
    {
        if ($this->hiddenOutsideProduction()) {
            return true;
        }

        if ($context->status >= 400) {
            return true;
        }

        foreach ((array) config('marketing-toolkit.robots.noindex_params') as $param) {
            if (filled($context->request->query($param))) {
                return true;
            }
        }

        $route = $context->request->route()?->getName();

        return $route !== null && in_array($route, (array) config('marketing-toolkit.robots.noindex_routes'), true);
    }

    /** @api */
    public function ogType(Context $context): string
    {
        return $context->override('og_type')
            ?? ($context->isHome() ? 'website' : $this->contentConfig($context, 'og_type', 'website'));
    }

    public function published(Context $context): ?string
    {
        $entry = $context->entry;

        return $this->ogType($context) === 'article' && $entry?->hasDate() ? $entry->date()->toAtomString() : null;
    }

    public function modified(Context $context): ?string
    {
        return $this->ogType($context) === 'article' ? $context->entry?->lastModified()?->toAtomString() : null;
    }

    public function twitterSite(): ?string
    {
        $handle = $this->settings->string('twitter_handle');

        return $handle === null ? null : '@'.ltrim($handle, '@');
    }

    /**
     * @return array<string, string>
     */
    public function verification(): array
    {
        return array_filter([
            'google-site-verification' => $this->settings->string('google_verification'),
            'msvalidate.01' => $this->settings->string('bing_verification'),
            'yandex-verification' => $this->settings->string('yandex_verification'),
            'p:domain_verify' => $this->settings->string('pinterest_verification'),
        ]);
    }
}
