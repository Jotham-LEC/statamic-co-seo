<?php

namespace JothamLec\MarketingToolkit\Preview;

use Illuminate\Http\Request;
use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\SiteSeo;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Site;

/**
 * Works out what a search result and a share card show for an entry or a term,
 * using the same SiteSeo rules that the page uses. It serves the SEO tab's preview
 * (the form as it stands) and the front-end toolbar (the page as saved). You
 * should run it on the content's site (Sites::as()).
 */
final class MetaPayload
{
    public function __construct(private SiteSeo $seo) {}

    /**
     * @return array{title: string, og_title: string, description: ?string, url: string, canonical: ?string, robots: string, site_name: string, twitter_site: ?string, image: ?array{url: string, alt: ?string, generated: bool}}
     */
    public function forContent(Entry|Term $content): array
    {
        $meta = $this->seo->meta(self::context($content));
        $generated = $content instanceof Entry ? $this->seo->generatedImageUrl($content) : $this->seo->generatedTermImageUrl($content);

        return [
            'title' => $meta->title,
            'og_title' => $meta->ogTitle,
            'description' => $meta->description,
            'url' => $meta->url,
            'canonical' => $meta->canonical,
            'robots' => $meta->robots,
            'site_name' => $meta->siteName,
            'twitter_site' => $meta->twitterSite,
            'image' => $meta->image === null ? null : [
                'url' => $meta->image['url'],
                'alt' => $meta->image['alt'],
                // A generated card is drawn from the form by card(), because its public URL
                // would show the saved entry's card instead. The URLs are compared without the
                // ?v= stamp, because an unsaved entry's stamp is "now", read twice.
                'generated' => $generated !== null && strtok($meta->image['url'], '?') === strtok($generated, '?'),
            ],
        ];
    }

    /**
     * The rules fall back to the request's URL when the content has none. For a
     * draft that would be the control panel's route, so this gives them the page's
     * own address instead (or where a new page would land).
     */
    public static function context(Entry|Term $content): Context
    {
        $url = $content->absoluteUrl() ?? rtrim(Site::get($content->locale())?->absoluteUrl() ?? Site::current()->absoluteUrl(), '/').'/'.$content->slug();

        return Context::make($content, Request::create($url));
    }
}
