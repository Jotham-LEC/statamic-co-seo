<?php

use Illuminate\Support\Facades\Event;
use JothamLec\MarketingToolkit\Redirects\AutoRedirects;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

// These run on both drivers: composer test runs the group again on files (MT_TEST_REDIRECTS=file).
pest()->group('redirects');

/**
 * @return array<string, string> source => target
 */
function redirectMap(): array
{
    return redirectRules()->sortBy('source')->pluck('target', 'source')->all();
}

/**
 * The entry as Statamic hands it to the control panel: freshly loaded, so it
 * remembers its saved state.
 */
function reloaded(Statamic\Contracts\Entries\Entry $entry): Statamic\Contracts\Entries\Entry
{
    return Entry::find($entry->id())->syncOriginal();
}

test('a changed slug leaves a marked 301 behind', function () {
    $entry = reloaded(entryIn('essays', 'on-reading', date: '2026-01-02'));

    $entry->slug('on-slow-reading')->save();

    $redirect = redirectRules()->sole();

    expect(collect($redirect->toArray())->only(['source', 'target', 'status', 'active', 'automatic'])->all())->toBe([
        'source' => '/essays/on-reading', 'target' => '/essays/on-slow-reading', 'status' => 301, 'active' => true, 'automatic' => true,
    ]);

    $this->get('/essays/on-reading')->assertRedirect('https://example.test/essays/on-slow-reading');
});

test('a changed date moves a dated address', function () {
    Collection::make('news')->routes('news/{year}/{slug}')->dated(true)->save();
    $entry = reloaded(entryIn('news', 'launch', date: '2025-06-01'));

    $entry->date('2026-06-01')->save();

    expect(redirectMap())->toBe(['/news/2025/launch' => '/news/2026/launch']);
});

test('saving without a new address, a new entry or a draft adds nothing', function () {
    $entry = reloaded(entryIn('pages', 'about'));
    $entry->set('description', 'Edited.')->save();

    entryIn('pages', 'brand-new');

    $draft = entryIn('pages', 'draft');
    $draft->published(false)->save();
    reloaded($draft)->slug('still-a-draft')->save();

    expect(redirectMap())->toBe([]);
});

test('a draft published under a new slug in the same save leaves no redirect from an address that was never public', function () {
    $draft = entryIn('pages', 'draft-a');
    $draft->published(false)->save();

    reloaded($draft)->slug('final')->published(true)->save();

    expect(redirectMap())->toBe([]);
});

test('a chain collapses: A→B then B→C leaves A→C and B→C', function () {
    $entry = reloaded(entryIn('pages', 'a'));
    $entry->slug('b')->save();
    reloaded($entry)->slug('c')->save();

    expect(redirectMap())->toBe(['/a' => '/c', '/b' => '/c']);
});

test('moving back drops the rule out of the live address instead of looping', function () {
    $entry = reloaded(entryIn('pages', 'a'));
    $entry->slug('b')->save();
    reloaded($entry)->slug('a')->save();

    expect(redirectMap())->toBe(['/b' => '/a']);
});

test('a manual rule into the old address follows the content too', function () {
    redirectRule(['source' => '/legacy', 'target' => '/a']);
    $entry = reloaded(entryIn('pages', 'a'));

    $entry->slug('b')->save();

    expect(redirectMap())->toBe(['/a' => '/b', '/legacy' => '/b']);
});

test('with case_sensitive off, the rule from the old address in another case is the one updated', function () {
    config(['marketing-toolkit.redirects.case_sensitive' => false]);
    redirectRule(['source' => '/About', 'target' => '/elsewhere']);
    $entry = reloaded(entryIn('pages', 'about'));

    $entry->slug('company')->save();

    expect(redirectMap())->toBe(['/about' => '/company']);
});

test('a rule into a similar-looking address is left alone', function () {
    redirectRule(['source' => '/legacy', 'target' => '/a_b/x']);
    redirectRule(['source' => '/other', 'target' => '/aXb/y']);
    $entry = reloaded(entryIn('pages', 'a_b'));

    $entry->slug('c')->save();

    expect(redirectMap())->toBe(['/a_b' => '/c', '/legacy' => '/c/x', '/other' => '/aXb/y']);
});

test('the editor can decline the redirect in the save dialog', function () {
    $entry = reloaded(entryIn('pages', 'a'));

    app(AutoRedirects::class)->remember($entry->id(), false);
    $entry->slug('b')->save();

    expect(redirectMap())->toBe([]);

    // The answer covers one save only.
    reloaded($entry)->slug('c')->save();
    expect(redirectMap())->toBe(['/b' => '/c']);
});

test('a page moved in a tree takes the pages under it along', function () {
    $collection = Collection::make('docs')->routes('{parent_uri}/{slug}')->structureContents(['max_depth' => 3])->save();
    $guide = entryIn('docs', 'guide');
    $setup = entryIn('docs', 'setup');
    $install = entryIn('docs', 'install');

    $tree = $collection->structure()->in('default');
    $tree->tree([['entry' => $guide->id()], ['entry' => $setup->id(), 'children' => [['entry' => $install->id()]]]])->save();
    expect(redirectMap())->toBe([]);

    $tree = $collection->structure()->in('default')->syncOriginal();
    $tree->tree([['entry' => $guide->id(), 'children' => [['entry' => $setup->id(), 'children' => [['entry' => $install->id()]]]]]])->save();

    expect(redirectMap())->toBe([
        '/setup' => '/guide/setup',
        '/setup/install' => '/guide/setup/install',
    ]);
});

test('renaming a page in a tree redirects the pages under it as well', function () {
    $collection = Collection::make('docs')->routes('{parent_uri}/{slug}')->structureContents(['max_depth' => 3])->save();
    $setup = entryIn('docs', 'setup');
    $install = entryIn('docs', 'install');
    $collection->structure()->in('default')->tree([['entry' => $setup->id(), 'children' => [['entry' => $install->id()]]]])->save();

    reloaded($setup)->slug('getting-started')->save();

    expect(redirectMap())->toBe([
        '/setup' => '/getting-started',
        '/setup/install' => '/getting-started/install',
    ]);
});

test('moving a collection\'s mount page redirects its entries with one wildcard rule', function () {
    $pages = Collection::make('site')->routes('{parent_uri}/{slug}')->structureContents(['max_depth' => 3])->save();
    $about = entryIn('site', 'about');
    $journal = entryIn('site', 'journal');
    $pages->structure()->in('default')->tree([['entry' => $about->id()], ['entry' => $journal->id()]])->save();
    Collection::make('posts')->routes('{mount}/{slug}')->mount($journal->id())->save();
    entryIn('posts', 'first-post');

    $tree = $pages->structure()->in('default')->syncOriginal();
    $tree->tree([['entry' => $about->id(), 'children' => [['entry' => $journal->id()]]]])->save();

    expect(redirectMap())->toBe(['/journal' => '/about/journal', '/journal/*' => '/about/journal/$1']);
    $this->get('/journal/first-post')->assertRedirect('https://example.test/about/journal/first-post');

    // Renamed rather than moved: the same, and the earlier wildcard follows.
    reloaded($journal)->slug('notes')->save();

    expect(redirectMap())->toBe([
        '/about/journal' => '/about/notes',
        '/about/journal/*' => '/about/notes/$1',
        '/journal' => '/about/notes',
        '/journal/*' => '/about/notes/$1',
    ]);
});

test('a renamed term leaves a 301 behind', function () {
    Taxonomy::make('topics')->termTemplate('default')->save();
    tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();

    $term = Term::find('topics::gardens')->term()->syncOriginal();
    $term->slug('gardening')->save();

    expect(redirectMap())->toBe(['/topics/gardens' => '/topics/gardening']);
});

/**
 * Statamic answers a term's address only when its template exists. A
 * taxonomy used only to tag content (no `topics.show` view) has addresses
 * that 404 before and after the rename; a rule between them is noise.
 */
test('a renamed term without a page of its own leaves no redirect', function () {
    Taxonomy::make('topics')->save();
    tap(Term::make()->taxonomy('topics')->slug('gardens')->data(['title' => 'Gardens']))->save();

    Term::find('topics::gardens')->term()->syncOriginal()->slug('gardening')->save();

    expect(redirectMap())->toBe([]);
});

test('automatic redirects can be turned off', function () {
    config(['marketing-toolkit.redirects.automatic' => false]);

    reloaded(entryIn('pages', 'a'))->slug('b')->save();

    expect(redirectMap())->toBe([]);
});

test('a manual wildcard under the new address is kept', function () {
    redirectRule(['source' => '/shop/*', 'target' => 'https://store.example.com/$1']);

    reloaded(entryIn('pages', 'store'))->slug('shop')->save();

    expect(redirectMap())->toBe(['/shop/*' => 'https://store.example.com/$1', '/store' => '/shop']);
});

test('a wildcard that would come to point at itself is dropped', function () {
    redirectRule(['source' => '/x/*', 'target' => '/old/$1']);

    reloaded(entryIn('pages', 'old'))->slug('x')->save();

    expect(redirectMap())->toBe(['/old' => '/x']);
});

test('a save that is cancelled leaves nothing behind for the next save', function () {
    $entry = reloaded(entryIn('pages', 'a'));
    $cancel = true;
    Event::listen(EntrySaving::class, function () use (&$cancel) {
        return $cancel ? false : null;
    });

    expect($entry->slug('b')->save())->toBeFalse();

    $cancel = false;
    expect(reloaded($entry)->set('description', 'Edited.')->save())->toBeTrue();

    expect(redirectMap())->toBe([]);
});

test('on a site in another timezone, an edit that keeps a dated address adds nothing, and a move starts from the right day', function () {
    config(['app.timezone' => 'Asia/Kuala_Lumpur']);
    date_default_timezone_set('Asia/Kuala_Lumpur');
    Collection::make('news')->routes('news/{year}/{month}/{day}/{slug}')->dated(true)->save();

    try {
        // 20:00 UTC is the next morning in Kuala Lumpur, the day the address shows.
        $entry = Entry::make()->collection('news')->slug('launch')->data(['title' => 'Launch']);
        $entry->date(Carbon\Carbon::parse('2026-01-01 20:00', 'UTC'))->save();
        $entry = reloaded($entry);
        expect($entry->uri())->toBe('/news/2026/01/02/launch');

        $entry->set('description', 'Edited.')->save();
        expect(redirectMap())->toBe([]);

        reloaded($entry)->slug('lift-off')->save();
        expect(redirectMap())->toBe(['/news/2026/01/02/launch' => '/news/2026/01/02/lift-off']);
    } finally {
        date_default_timezone_set('UTC');
    }
});
