<?php

// The SiteSeo methods marked @api keep their names, visibility and signatures
// until the next major version: sites override them in their own subclass.
// Changing one of these lines is a breaking change.

use JothamLec\MarketingToolkit\SiteSeo;

/** By method name. */
const SITE_SEO_API = [
    'public function absolute(string $url): string',
    'public function additionalSitemapUrls(): array',
    'public function alternates(JothamLec\\MarketingToolkit\\Context $context): array',
    'public function articleImages(JothamLec\\MarketingToolkit\\Context $context): array',
    'public function articleNode(JothamLec\\MarketingToolkit\\Context $context): ?array',
    'public function authors(JothamLec\\MarketingToolkit\\Context $context): array',
    'public function breadcrumbNode(JothamLec\\MarketingToolkit\\Context $context): ?array',
    'public function canonical(JothamLec\\MarketingToolkit\\Context $context): ?string',
    'public function cardLabel(JothamLec\\MarketingToolkit\\Context $context): ?string',
    'public function collectionConfig(JothamLec\\MarketingToolkit\\Context $context, string $key, mixed $default = null): mixed',
    'public function contentConfig(JothamLec\\MarketingToolkit\\Context $context, string $key, mixed $default = null): mixed',
    'public function customNodes(JothamLec\\MarketingToolkit\\Context $context): array',
    'public function description(JothamLec\\MarketingToolkit\\Context $context): ?string',
    'public function extraNodes(JothamLec\\MarketingToolkit\\Context $context): array',
    'public function faqNode(JothamLec\\MarketingToolkit\\Context $context): ?array',
    'public function graph(JothamLec\\MarketingToolkit\\Context $context): array',
    'protected function hiddenOutsideProduction(): bool',
    'public function hreflangCodes(): array',
    'public function image(JothamLec\\MarketingToolkit\\Context $context): ?array',
    'protected function imageHeight(): int',
    'protected function imageWidth(): int',
    'public function inSitemap(Statamic\\Contracts\\Entries\\Entry|Statamic\\Contracts\\Taxonomies\\Term $content): bool',
    'public function isProtected(Statamic\\Contracts\\Entries\\Entry|Statamic\\Contracts\\Taxonomies\\Term $content): bool',
    'protected function llmsPerCollection(): int',
    'public function llmsTxt(): string',
    'public function localizations(Statamic\\Contracts\\Entries\\Entry|Statamic\\Contracts\\Taxonomies\\Term $content): array',
    'public function meta(JothamLec\\MarketingToolkit\\Context $context): JothamLec\\MarketingToolkit\\Meta',
    'public function ogTitle(JothamLec\\MarketingToolkit\\Context $context): string',
    'public function ogType(JothamLec\\MarketingToolkit\\Context $context): string',
    'public function productNode(JothamLec\\MarketingToolkit\\Context $context): ?array',
    'public function profileEntity(JothamLec\\MarketingToolkit\\Context $context): array',
    'public function publisherNode(): array',
    'public function publisherTypes(): array',
    'public function robots(JothamLec\\MarketingToolkit\\Context $context): string',
    'public function robotsTxt(): string',
    'public function settings(): JothamLec\\MarketingToolkit\\Settings',
    'public function shouldNoindex(JothamLec\\MarketingToolkit\\Context $context): bool',
    'public function sitemapSites(): array',
    'protected function snippetRules(JothamLec\\MarketingToolkit\\Context $context): string',
    'public function termHasEntries(Statamic\\Contracts\\Taxonomies\\Term $term): bool',
    'public function title(JothamLec\\MarketingToolkit\\Context $context): string',
    'public function webPageNode(JothamLec\\MarketingToolkit\\Context $context): ?array',
    'public function websiteNode(): array',
    'public function xDefaultSite(): ?string',
];

/**
 * A method's signature as SITE_SEO_API writes it.
 */
function apiSignature(ReflectionMethod $method): string
{
    $parameters = array_map(fn (ReflectionParameter $parameter) => trim(
        ($parameter->getType() ? $parameter->getType().' ' : '').'$'.$parameter->getName()
        .($parameter->isDefaultValueAvailable() ? ' = '.strtolower(var_export($parameter->getDefaultValue(), true)) : '')
    ), $method->getParameters());

    return ($method->isPublic() ? 'public' : 'protected').' function '.$method->getName().'('.implode(', ', $parameters).'): '.$method->getReturnType();
}

test('every @api method on SiteSeo keeps its name, visibility and signature', function () {
    $methods = collect((new ReflectionClass(SiteSeo::class))->getMethods())
        ->filter(fn (ReflectionMethod $method) => str_contains((string) $method->getDocComment(), '@api'))
        ->sortBy(fn (ReflectionMethod $method) => $method->getName())
        ->map(fn (ReflectionMethod $method) => apiSignature($method))
        ->values()
        ->all();

    expect($methods)->toBe(SITE_SEO_API);
});
