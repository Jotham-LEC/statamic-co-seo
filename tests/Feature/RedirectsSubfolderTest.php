<?php

use JothamLec\MarketingToolkit\Actions\CreateRedirect;
use JothamLec\MarketingToolkit\NotFound\MissingPath;
use Statamic\Facades\Entry;

// These run on both drivers: composer test runs the group again on files (MT_TEST_REDIRECTS=file).
pest()->group('redirects');

/*
 * Sites in folders of one domain (multilang(): French under /fr/, British
 * English under /uk/): rules and the 404 log keep paths within the site, as
 * Statamic's uri() does.
 */
beforeEach(fn () => multilang());

$browser = ['User-Agent' => 'Mozilla/5.0'];

test('a page renamed on a site in a folder redirects from its old address there', function () {
    $entry = entryOn('fr', 'pages', 'a-propos');

    Entry::find($entry->id())->syncOriginal()->slug('qui-sommes-nous')->save();

    expect(redirectRules()->map(fn ($rule) => collect($rule->toArray())->only(['site', 'source', 'target'])->all())->all())->toBe([
        ['site' => 'fr', 'source' => '/a-propos', 'target' => '/qui-sommes-nous'],
    ]);

    $this->get('https://example.test/fr/a-propos')->assertStatus(301)->assertRedirect('https://example.test/fr/qui-sommes-nous');
    // Not on the sites at the domain's root or in another folder.
    $this->get('https://example.test/a-propos')->assertNotFound();
    $this->get('https://example.test/uk/a-propos')->assertNotFound();
});

test('a rule for every site applies within each site\'s folder', function () {
    redirectRule(['source' => '/old', 'target' => '/new']);
    redirectRule(['source' => '/blog/*', 'target' => '/essays/$1']);

    $this->get('https://example.test/old')->assertRedirect('https://example.test/new');
    $this->get('https://example.test/fr/old')->assertRedirect('https://example.test/fr/new');
    $this->get('https://example.test/uk/blog/on-reading')->assertRedirect('https://example.test/uk/essays/on-reading');
    $this->get('https://de.example.test/old')->assertRedirect('https://de.example.test/new');
});

test('a rule typed with the site\'s folder, as before, still applies, and its target isn\'t given the folder twice', function () {
    redirectRule(['site' => 'fr', 'source' => '/fr/old', 'target' => '/fr/a-propos']);
    redirectRule(['site' => 'fr', 'source' => '/fr/older', 'target' => '/a-propos']);
    redirectRule(['site' => 'fr', 'source' => '/fr/blog/*', 'target' => '/fr/essais/$1']);

    $this->get('https://example.test/fr/old')->assertRedirect('https://example.test/fr/a-propos');
    $this->get('https://example.test/fr/older')->assertRedirect('https://example.test/fr/a-propos');
    $this->get('https://example.test/fr/blog/lire')->assertRedirect('https://example.test/fr/essais/lire');
});

test('within the site wins over a rule typed with the folder, and a rule leading back to the address asked for is not served', function () {
    redirectRule(['site' => 'fr', 'source' => '/fr/old', 'target' => '/legacy']);
    redirectRule(['site' => 'fr', 'source' => '/old', 'target' => '/current']);
    storedRule(['site' => 'fr', 'source' => '/loop', 'target' => '/fr/loop']);

    $this->get('https://example.test/fr/old')->assertRedirect('https://example.test/fr/current');
    $this->get('https://example.test/fr/loop')->assertNotFound();
});

test('the 404 log keeps a path within its site, and a redirect made from it applies there', function () use ($browser) {
    $this->get('https://example.test/fr/manquant', $browser)->assertNotFound();

    $row = MissingPath::query()->sole();
    expect([$row->site, $row->path])->toBe(['fr', '/manquant']);

    $this->actingAs(cpUser(super: true));
    session(['statamic.cp.selected-site' => 'fr']);
    $this->postJson(cp_route('mt.actions.run'), ['action' => CreateRedirect::handle(), 'selections' => [$row->id], 'context' => ['type' => '404s'], 'values' => []])
        ->assertJsonPath('redirect', cp_route('mt.redirects.create', ['source' => '/manquant', 'site' => 'fr']));

    redirectRule(['site' => 'fr', 'source' => '/manquant', 'target' => '/a-propos']);

    $this->get('https://example.test/fr/manquant', $browser)->assertRedirect('https://example.test/fr/a-propos');
});

test('a 404 path logged with the site\'s folder, as before, makes a redirect that still applies', function () use ($browser) {
    // As logged before paths were kept within the site.
    MissingPath::query()->create(['site' => 'fr', 'path' => '/fr/ancien', 'hits' => 3, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    redirectRule(['site' => 'fr', 'source' => '/fr/ancien', 'target' => '/a-propos']);

    $this->get('https://example.test/fr/ancien', $browser)->assertRedirect('https://example.test/fr/a-propos');
});

test('scanner probes are left out of the log within a folder too', function () use ($browser) {
    $this->get('https://example.test/fr/wp-login.php', $browser);
    $this->get('https://example.test/uk/.env', $browser);

    expect(MissingPath::query()->count())->toBe(0);
});
