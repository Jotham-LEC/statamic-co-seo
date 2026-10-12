<?php

namespace JothamLec\MarketingToolkit\Concerns;

use ArrayAccess;
use Illuminate\Support\Collection;
use JothamLec\MarketingToolkit\Context;
use JothamLec\MarketingToolkit\Settings;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Assets;
use JothamLec\MarketingToolkit\Support\Fields;
use JothamLec\MarketingToolkit\Support\Text;
use Statamic\Auth\Protect\Protection;
use Statamic\Auth\Protect\Protectors\NullProtector;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Image;
use Statamic\Facades\Markdown;
use Statamic\Facades\Site;
use Statamic\Fields\Value;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;

/**
 * This trait holds the helpers the rules share. They read the settings, the
 * collection rules, fields, and images, and they work out the site's address.
 *
 * @phpstan-require-extends SiteSeo
 */
trait InteractsWithContent
{
    /** @api */
    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Returns a key of this page's rules, from its collection's settings (config
     * `marketing-toolkit.collections`), or for a term, from its taxonomy's (`marketing-toolkit.taxonomies`).
     *
     * @api
     */
    public function contentConfig(Context $context, string $key, mixed $default = null): mixed
    {
        if ($context->entry) {
            return $this->collectionConfig($context, $key, $default);
        }

        $handle = $context->term?->taxonomyHandle();

        return $handle ? config("marketing-toolkit.taxonomies.{$handle}.{$key}", $default) : $default;
    }

    /**
     * Returns a key of this entry's collection settings (config `marketing-toolkit.collections`).
     *
     * @api
     */
    public function collectionConfig(Context $context, string $key, mixed $default = null): mixed
    {
        $handle = $context->entry?->collectionHandle();

        return $handle ? config("marketing-toolkit.collections.{$handle}.{$key}", $default) : $default;
    }

    /**
     * Determines whether Statamic keeps this content behind a protection
     * scheme, such as a password, a login, or an IP list. It reads the
     * content's `protect` value, or else the site-wide
     * `statamic.protect.default`, as Statamic reads them, so a scheme that
     * doesn't exist counts too, because Statamic denies it. Protected content
     * stays out of the sitemap, llms.txt, and IndexNow, and it has no share
     * card, because its title and text aren't public.
     *
     * @api
     */
    public function isProtected(Entry|Term $content): bool
    {
        return ! (app(Protection::class)->setData($content)->driver() instanceof NullProtector);
    }

    protected function contentTitle(Context $context): ?string
    {
        $content = $context->content();
        $title = $content instanceof Term ? (Fields::value($content, 'title') ?? $content->slug()) : $context->value('title');

        return filled($title) ? (string) $title : null;
    }

    /**
     * Returns the entry's `description`, or a field the collection names, or
     * else the first paragraph of its `content`. A field may be text or Bard.
     */
    protected function contentDescription(Context $context): ?string
    {
        $content = $context->content();

        if (! $content) {
            return null;
        }

        foreach (['description', ...$this->contentConfig($context, 'description_fields', [])] as $field) {
            if ($value = $this->rawValues($content, $field)->map(fn ($value) => $this->fieldText($value))->first(fn ($value) => $value !== null)) {
                return $value;
            }
        }

        $html = $this->html($content->augmentedValue('content'));

        if (blank($html)) {
            return null;
        }

        // A body that is not rendered HTML (with no blueprint field, or a plain textarea) is read as Markdown.
        if (! str_contains($html, '<p')) {
            $html = Markdown::parse($html);
        }

        return Text::firstParagraph($html);
    }

    /**
     * Returns a stored field value as text, or null when it has none: a string
     * as it is, and a Bard field's nodes (block or inline, as Bard stores them
     * unless it saves HTML) rendered as Bard renders them, without its sets,
     * then as plain text with a space where one block ends and the next begins.
     */
    protected function fieldText(mixed $value): ?string
    {
        if (is_string($value)) {
            return filled($value) ? $value : null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $nodes = array_values(array_filter($value, fn ($node) => is_array($node) && is_string($node['type'] ?? null) && $node['type'] !== 'set'));
        if ($nodes === []) {
            return null;
        }

        $html = (string) (new Augmentor(new Bard))->convertToHtml($nodes);
        $text = Text::plain(preg_replace('#<br\s*/?>|</(?:p|h[1-6]|li|blockquote|pre|td|th)>#i', '$0 ', $html));

        return $text === '' ? null : $text;
    }

    /**
     * Returns the HTML from an augmented Markdown or Bard value.
     */
    protected function html(mixed $value): ?string
    {
        $value = $this->unwrap($value);

        if (is_string($value)) {
            return $value;
        }

        if (is_iterable($value)) {
            return collect($value)
                ->map(fn ($set) => ($set['type'] ?? null) === 'text' ? (string) ($set['text'] ?? '') : '')
                ->implode('');
        }

        return null;
    }

    protected function assetFrom(Entry|Term $content, string $field): ?Asset
    {
        if ($set = $this->setPath($field)) {
            return $this->visibleSets($content->augmentedValue($set['field'])->value(), $set['type'])
                ->map(fn ($values) => Assets::from($values[$set['key']] ?? null))
                ->first(fn ($asset) => $asset !== null);
        }

        return Assets::from($field === 'seo'
            ? ($content->augmentedValue('seo')->value()['image'] ?? null)
            : $content->augmentedValue($field)->value());
    }

    /**
     * Returns the stored value of a field as a list, with one value for a plain
     * field and one per visible matching set for a path into a Replicator. It
     * reads a translation's own value, or else its origin's, and in a Live
     * Preview of the content, the form's unsaved value (see Support\Fields).
     *
     * @return Collection<int, mixed>
     */
    protected function rawValues(Entry|Term $content, string $field): Collection
    {
        if ($set = $this->setPath($field)) {
            return $this->visibleSets(Fields::value($content, $set['field']), $set['type'])->map(fn ($values) => $values[$set['key']] ?? null)->values();
        }

        /** @var mixed $value */
        $value = Fields::value($content, $field);

        return collect([$value]);
    }

    /**
     * Splits a path such as `sections.hero.image` into the Replicator field,
     * the set type (`*` for any), and the field in the set. Statamic handles
     * have no dots, so a plain name is never a path.
     *
     * @return array{field: string, type: string, key: string}|null
     */
    protected function setPath(string $field): ?array
    {
        $parts = explode('.', $field);

        return count($parts) === 3 && ! in_array('', $parts, true) ? array_combine(['field', 'type', 'key'], $parts) : null;
    }

    /**
     * Returns a Replicator's sets of a type, in order, without those switched
     * off. It accepts the stored rows or the augmented ones, which have none switched off.
     *
     * @return Collection<int, mixed>
     */
    protected function visibleSets(mixed $sets, string $type): Collection
    {
        $sets = $this->unwrap($sets);

        return collect(is_iterable($sets) ? $sets : [])
            ->filter(fn ($set) => (is_array($set) || $set instanceof ArrayAccess)
                && ($set['enabled'] ?? true) !== false
                && ($type === '*' || ($set['type'] ?? null) === $type))
            ->values();
    }

    /**
     * Returns a field's value without its augmented Value wrapper, whichever of
     * the two a caller (or a subclass) passes.
     */
    private function unwrap(mixed $value): mixed
    {
        return $value instanceof Value ? $value->value() : $value;
    }

    /**
     * Turns an uploaded image into a share image. It is cropped on its focal
     * point and served as JPEG, because WhatsApp and others do not preview WebP.
     *
     * @return array{url: string, width: int, height: int, alt: ?string}
     */
    protected function cropped(Asset $asset, ?int $width = null, ?int $height = null): array
    {
        $width ??= $this->imageWidth();
        $height ??= $this->imageHeight();

        // The manipulation is built fluently, not as an array, because only fit() turns `crop_focal`
        // into Glide's `crop-{x}-{y}`. Glide treats an unknown fit as `contain`, which neither
        // fills the card nor enlarges a small image.
        $url = Image::manipulate($asset)
            ->width($width)
            ->height($height)
            ->fit('crop_focal')
            ->format('jpg')
            ->quality(85)
            ->build();

        $alt = $asset->get('alt');

        return [
            'url' => $this->absolute((string) $url),
            'width' => $width,
            'height' => $height,
            'alt' => filled($alt) ? (string) $alt : null,
        ];
    }

    /**
     * Returns the share image's width. The size is 1200×630, as Facebook,
     * LinkedIn, and X expect, so override both methods for another size.
     *
     * @api
     */
    protected function imageWidth(): int
    {
        return 1200;
    }

    /** @api */
    protected function imageHeight(): int
    {
        return 630;
    }

    protected function websiteId(): string
    {
        return $this->home().'#website';
    }

    protected function publisherId(): string
    {
        return $this->home().'#publisher';
    }

    protected function home(): string
    {
        return rtrim(Site::current()->absoluteUrl(), '/').'/';
    }

    /**
     * Makes a relative URL absolute on the current site's domain. A path from
     * the root (`/img/…`, an asset's URL, or a route) is on the domain's root,
     * not under a site's folder (`/fr/`), while a path without the slash is
     * under the site's address.
     *
     * @api
     */
    public function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $home = $this->home();

        if (str_starts_with($url, '//')) {
            return (parse_url($home, PHP_URL_SCHEME) ?: 'https').':'.$url;
        }

        return str_starts_with($url, '/') ? self::domainRoot($home).$url : $home.$url;
    }

    /**
     * Returns the domain's root, such as `https://example.test` from `https://example.test/fr/`.
     */
    public static function domainRoot(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].$port : rtrim($url, '/');
    }
}
