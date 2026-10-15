<?php

use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use JothamLec\MarketingToolkit\Redirects\Csv;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use Statamic\Facades\Site;
use Statamic\Facades\URL;

// These run on both drivers: composer test runs the group again on files (MT_TEST_REDIRECTS=file).
pest()->group('redirects');

function rule(string $source, ?string $target, int $status = 301, bool $active = true): Rule
{
    return redirectRule(['source' => $source, 'target' => $target, 'status' => $status, 'active' => $active]);
}

test('an exact rule sends a missing address on, and counts the hit', function () {
    $redirect = rule('/old-page', '/new-page');

    $this->get('/old-page')->assertStatus(301)->assertRedirect('https://example.test/new-page');

    expect(freshRule($redirect)->hits)->toBe(1)
        ->and(freshRule($redirect)->lastHitAt)->not->toBeNull();
});

test('rules are stored and matched without the trailing slash or query string', function () {
    $redirect = rule('old-page/?ref=x', 'new-page/');

    expect($redirect->source)->toBe('/old-page')->and($redirect->target)->toBe('/new-page');

    $this->get('/old-page?utm_source=mail')->assertRedirect('https://example.test/new-page?utm_source=mail');
});

test('a wildcard passes what it matched to the target, and the longest source wins', function () {
    rule('/blog/*', '/essays/$1');
    rule('/blog/2019/*', '/archive/$1', 302);
    rule('/a/*/b/*', '/x/$2/$1');

    $this->get('/blog/on-reading')->assertStatus(301)->assertRedirect('https://example.test/essays/on-reading');
    $this->get('/blog/2019/old/post')->assertStatus(302)->assertRedirect('https://example.test/archive/old/post');
    $this->get('/a/one/b/two')->assertRedirect('https://example.test/x/two/one');
});

test('a rule with several wildcards matches a long address in a few steps, each * taking what it would on a short one', function () {
    rule('/*-*-*-x*', '/to/$1/$2/$3$4');
    rule('/*/*/*/*/end', '/never');
    rule('/*', '/fallback');
    $tail = str_repeat('-y', 300);
    // Statamic lifts PCRE's limit (pcre_backtrack_limit -1). Forwards, each `*` a greedy `(.*)`, a
    // made-up 800-character address took seconds against the second rule; here a few thousand steps
    // must do, which forwards ran out on the first address and silently matched nothing.
    $limit = ini_set('pcre.backtrack_limit', '5000');

    try {
        $this->get('/p-q-r-x'.$tail)->assertRedirect('https://example.test/to/p/q/r'.$tail);
        $this->get('/a-b-c-d-x')->assertRedirect('https://example.test/to/a-b/c/d');
        $this->get(str_repeat('/a', 400).'/endx')->assertRedirect('https://example.test/fallback');
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }
});

test('an exact rule wins over a wildcard', function () {
    rule('/blog/*', '/essays/$1');
    rule('/blog/special', '/special');

    $this->get('/blog/special')->assertRedirect('https://example.test/special');
});

test('410 answers "gone" with the site\'s error page', function () {
    rule('/withdrawn', null, 410);

    $this->get('/withdrawn')->assertStatus(410);
});

test('a page that exists wins over a rule, and inactive rules do nothing', function () {
    entryIn('pages', 'about');
    rule('/about', '/elsewhere');
    rule('/paused', '/elsewhere', active: false);

    $this->get('https://example.test/about')->assertOk();
    $this->get('/paused')->assertNotFound();
});

test('another site\'s address is kept as typed', function () {
    rule('/shop', 'https://shop.example.com/');

    $this->get('/shop')->assertRedirect('https://shop.example.com/');
});

test('targets get the trailing slash on a site that has Statamic add them, so there is one hop', function () {
    URL::enforceTrailingSlashes();
    rule('/old', '/new');
    rule('/old-file', '/files/report.pdf');

    // The test client strips trailing slashes, so the slashed request goes straight to the kernel.
    $location = fn (string $uri) => app(Kernel::class)->handle(Request::create('https://example.test'.$uri))->headers->get('Location');

    expect($location('/old/'))->toBe('https://example.test/new/')
        ->and($location('/old-file/'))->toBe('https://example.test/files/report.pdf');
});

test('the cached rules follow saves and deletes', function () {
    $redirect = rule('/old', '/one');
    $this->get('/old')->assertRedirect('https://example.test/one');

    updateRule($redirect, ['target' => '/two']);
    $this->get('/old')->assertRedirect('https://example.test/two');

    deleteRule($redirect);
    $this->get('/old')->assertNotFound();
});

test('redirects can be turned off', function () {
    config(['marketing-toolkit.redirects.enabled' => false]);
    rule('/old', '/new');

    $this->get('/old')->assertNotFound();
});

test('what a wildcard matched is passed on encoded, and an encoded ? stays part of the path', function () {
    rule('/old/*', '/new/$1');
    rule('/page', '/elsewhere');

    expect($this->get('/old/a%3Fb')->headers->get('Location'))->toBe('https://example.test/new/a%3Fb')
        ->and($this->get('/old/a%20b/caf%C3%A9')->headers->get('Location'))->toBe('https://example.test/new/a%20b/caf%C3%A9');

    $this->get('/page%3Fx')->assertNotFound();
});

test('a source typed percent-encoded, as copied from the address bar, matches', function () {
    $redirect = rule('/caf%C3%A9', '/x');
    rule('/a%20b', '/y');

    expect($redirect->source)->toBe('/café');
    $this->get('/caf%C3%A9')->assertRedirect('https://example.test/x');
    $this->get('/a%20b')->assertRedirect('https://example.test/y');
});

test('a source saved encoded before it was decoded on save still matches', function () {
    storedRule(['source' => '/caf%C3%A9', 'target' => '/x']);

    $this->get('/caf%C3%A9')->assertRedirect('https://example.test/x');
});

test('a target keeps its #fragment, after the visitor\'s query string', function () {
    expect(rule('/old', '/faq#shipping')->target)->toBe('/faq#shipping');

    expect($this->get('/old?utm=x')->headers->get('Location'))->toBe('https://example.test/faq?utm=x#shipping');
});

test('a rule that sends an address back to itself, directly or through another rule, is refused', function () {
    $fails = fn (string $source, string $target) => Rule::validator(['source' => $source, 'target' => $target, 'status' => 301, 'active' => true])->fails();
    rule('/a', '/b');

    expect($fails('/same', '/same/'))->toBeTrue()
        ->and($fails('/x/*', '/x/$1'))->toBeTrue()
        ->and($fails('/b', '/a?ref=loop'))->toBeTrue()
        // Under its own source is fine: the pages there may exist.
        ->and($fails('/blog/*', '/blog/new/$1'))->toBeFalse()
        ->and($fails('/c', '/a'))->toBeFalse();
});

test('a rule that comes back to its own address through a longer chain of rules is refused', function () {
    $validator = fn (string $source, string $target, ?string $ignore = null) => Rule::validator(['source' => $source, 'target' => $target, 'status' => 301, 'active' => true], $ignore);
    rule('/b', '/c');
    rule('/c', '/d/x');
    rule('/d/*', '/a');
    // A chain that loops without passing through here stops being followed.
    rule('/p', '/q');
    rule('/q', '/p');

    expect($validator('/a', '/b')->errors()->first('target'))->toBe('The redirects from that address lead back here after 3 steps, so visitors would go round in a loop.')
        ->and($validator('/z', '/b')->fails())->toBeFalse()
        ->and($validator('/z', '/p')->fails())->toBeFalse()
        // Editing the rule at the end of the chain: its old version doesn't count.
        ->and($validator('/d/*', '/elsewhere', app(RedirectRepository::class)->forSource('/d/*')->id)->fails())->toBeFalse();
});

test('a rule saved before that check, sending an address to itself, is not served', function () {
    storedRule(['source' => '/a', 'target' => '/a/']);

    $this->get('/a')->assertNotFound();
});

test('an address with a line break or another control character is refused, as it would go into the Location header', function () {
    $fails = fn (string $source, string $target) => Rule::validator(['source' => $source, 'target' => $target, 'status' => 301, 'active' => true])->fails();

    expect($fails('/a', "/b\r\nSet-Cookie: x=1"))->toBeTrue()
        ->and($fails('/a', "https://elsewhere.test/\nx"))->toBeTrue()
        ->and($fails("/a\nb", '/b'))->toBeTrue()
        ->and($fails('/a', '/b'))->toBeFalse();
});

test('what a visitor typed can\'t choose the site a wildcard sends them to', function () {
    $fails = fn (string $target) => Rule::validator(['source' => '/go/*', 'target' => $target, 'status' => 301, 'active' => true])->fails();

    expect($fails('https://example.com$1'))->toBeTrue()
        ->and($fails('https://$1'))->toBeTrue()
        ->and($fails('https://example.com/$1'))->toBeFalse()
        ->and($fails('/new/$1'))->toBeFalse();
});

test('a redirect goes to the site\'s own address, whatever Host header the request carried', function () {
    redirectRule(['source' => '/old', 'target' => '/new']);

    $this->get('https://example.test/old', ['Host' => 'evil.test'])
        ->assertRedirect('https://example.test/new');
    $this->get('https://evil.test/old')->assertRedirect('https://example.test/new');
});

test('on a site whose URL is relative, a redirect goes to a path alone, not to the request\'s Host header', function () {
    Site::setSites(['default' => ['name' => 'Acme', 'url' => '/', 'locale' => 'en_US']]);
    redirectRule(['source' => '/old', 'target' => '/new']);
    redirectRule(['source' => '/away', 'target' => 'https://elsewhere.test/page']);

    expect($this->get('https://evil.test/old')->assertStatus(301)->headers->get('Location'))->toBe('/new')
        ->and($this->get('https://evil.test/away')->headers->get('Location'))->toBe('https://elsewhere.test/page');
});

test('on sites in folders with relative URLs, the path keeps the site\'s folder', function () {
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'Acme', 'url' => '/', 'locale' => 'en_US'],
        'fr' => ['name' => 'Acme', 'url' => '/fr/', 'locale' => 'fr_FR'],
    ]);
    redirectRule(['site' => 'fr', 'source' => '/old', 'target' => '/new']);

    expect($this->get('https://evil.test/fr/old')->assertStatus(301)->headers->get('Location'))->toBe('/fr/new');
});

test('a missing address is never kept by Statamic\'s static cache, so a redirect added later applies', function () {
    config(['statamic.static_caching.strategy' => 'half']);

    $this->get('https://example.test/old')->assertNotFound();
    rule('/old', '/new');

    $this->get('https://example.test/old')->assertRedirect('https://example.test/new');
});

test('letter case counts by default', function () {
    rule('/About-Us', '/about');
    rule('/Blog/*', '/essays/$1');

    $this->get('/about-us')->assertNotFound();
    $this->get('/blog/post')->assertNotFound();
    expect(Rule::validator(['source' => '/about-us', 'target' => '/x', 'status' => 301, 'active' => true])->fails())->toBeFalse();
});

test('with case_sensitive off, a source matches in any letter case, and a wildcard passes on what it matched as typed', function () {
    config(['marketing-toolkit.redirects.case_sensitive' => false]);
    rule('/about-us', '/about');
    rule('/Café', '/coffee');
    rule('/blog/*', '/essays/$1');
    rule('/ÉTÉ/*', '/summer/$1');

    $this->get('/ABOUT-US/')->assertRedirect('https://example.test/about');
    $this->get('/About-Us')->assertRedirect('https://example.test/about');
    $this->get('/CAF%C3%89')->assertRedirect('https://example.test/coffee');
    $this->get('/BLOG/My-Post')->assertRedirect('https://example.test/essays/My-Post');
    $this->get('/%C3%A9t%C3%A9/Plage')->assertRedirect('https://example.test/summer/Plage');
});

test('turning case_sensitive off takes effect though the rules are cached', function () {
    rule('/about-us', '/about');
    $this->get('/ABOUT-US')->assertNotFound();

    config(['marketing-toolkit.redirects.case_sensitive' => false]);

    $this->get('/ABOUT-US')->assertRedirect('https://example.test/about');
});

test('with case_sensitive off, a source in the very case asked for wins over one differing only in case', function () {
    rule('/Old', '/one');
    rule('/old', '/two');
    config(['marketing-toolkit.redirects.case_sensitive' => false]);

    $this->get('/old')->assertRedirect('https://example.test/two');
    $this->get('/Old')->assertRedirect('https://example.test/one');
    // Neither in that case: the older rule.
    $this->get('/OLD')->assertRedirect('https://example.test/one');
});

test('with case_sensitive off, a source differing only in case is the same one, on the form and in a CSV import', function () {
    config(['marketing-toolkit.redirects.case_sensitive' => false]);
    $existing = rule('/about-us', '/about');

    expect(Rule::validator(['source' => '/ABOUT-US/', 'target' => '/x', 'status' => 301, 'active' => true])->errors()->first('source'))
        ->toBe('Another redirect already starts from this address.')
        ->and(Rule::validator(['source' => '/ABOUT-US', 'target' => '/x', 'status' => 301, 'active' => true], $existing->id)->fails())->toBeFalse();

    rule('/Café', '/coffee');
    expect(Rule::validator(['source' => '/CAFÉ', 'target' => '/x', 'status' => 301, 'active' => true])->fails())->toBeTrue();

    $result = app(Csv::class)->import("source,target,status,active\n/About-Us,/company,301,1\n");

    expect($result)->toBe(['created' => 0, 'updated' => 1, 'errors' => []])
        ->and(redirectRules()->count())->toBe(2)
        ->and(freshRule($existing)->target)->toBe('/company');
});

test('with case_sensitive off, a CSV import folds the stored sources once and clears the cached rules once', function () {
    config(['marketing-toolkit.redirects.case_sensitive' => false]);
    $about = rule('/about-us', '/about');
    // Two rules differing only in case, kept from when matching heeded it.
    rule('/Old', '/one');
    rule('/OLD', '/two');
    app(Matcher::class)->match('/x', site: 'default');

    $scans = 0;
    DB::listen(function ($query) use (&$scans) {
        $scans += preg_match('/^select .* from "mt_redirects"( where "site" is null)? order by "id" asc$/', $query->sql);
    });

    $result = app(Csv::class)->import("source,target,status,active\n/ABOUT-US,/company,301,1\n/new,/a,301,1\n/NEW,/b,301,1\n/old,/three,301,1\n");

    expect($scans)->toBe(1)
        ->and($result['created'])->toBe(1)
        ->and($result['updated'])->toBe(2)
        ->and($result['errors'])->toBe(['Row 5: Another redirect already starts from this address.'])
        ->and(freshRule($about)->target)->toBe('/company')
        ->and(app(RedirectRepository::class)->forSource('/new')->target)->toBe('/b')
        ->and(Cache::has('mt:redirect-rules:default:any-case'))->toBeFalse();
})->skip(testingOnFiles(), 'rows written to the database alone');

test('a CSV import clears the cached rules once, after its rows are saved', function () {
    $forgotten = [];
    Event::listen(ForgettingKey::class, function (ForgettingKey $event) use (&$forgotten) {
        if (str_starts_with($event->key, 'mt:redirect-rules:')) {
            $forgotten[] = [$event->key, redirectRules()->count()];
        }
    });

    app(Csv::class)->import("/a,/b\n/c,/d\n/e,/f\n");

    // Both drivers' keys, for each letter-case setting, each once and after all three rows.
    expect($forgotten)->toHaveCount(4)
        ->and(collect($forgotten)->pluck(0)->unique())->toHaveCount(4)
        ->and(collect($forgotten)->pluck(1)->unique()->all())->toBe([3]);
});

test('a CSV import reads the redirects a fixed number of times, however many rows it has', function (bool $caseSensitive) {
    config(['marketing-toolkit.redirects.case_sensitive' => $caseSensitive]);
    rule('/shop/*', '/store/$1');
    rule('/kept', '/elsewhere');

    $reads = function (int $rows): int {
        $csv = "source,target,status,active\n";

        for ($row = 1; $row <= $rows; $row++) {
            // Each row leads on to the next, so the loop check follows earlier rows of the file.
            $csv .= "/r{$rows}-{$row},/r{$rows}-".($row + 1)."\n";
        }

        $count = 0;
        DB::listen(function ($query) use (&$count) {
            $count += (int) preg_match('/^select .* from "mt_redirects"/', $query->sql);
        });

        expect(app(Csv::class)->import($csv)['created'])->toBe($rows);

        return $count;
    };

    expect($reads(2))->toBe($reads(20));
})->with(['case-sensitive' => true, 'any case' => false])->skip(testingOnFiles(), 'counts queries of the database');

test('a CSV cell in quotes may hold a line break, and rows are counted as records', function () {
    $result = app(Csv::class)->import("source,target,status,active\n\"/a\nb\",/x,301,1\n/two,/2,301,1\n\n/two,/two,301,1\n");

    expect($result['created'])->toBe(1)
        ->and($result['errors'])->toBe([
            'Row 2: '.__('marketing-toolkit::validation.redirect.control_characters'),
            'Row 4: '.__('marketing-toolkit::validation.redirect.points_back'),
        ])
        ->and(redirectRules()->pluck('source')->all())->toBe(['/two']);
});

test('a CSV row that isn\'t UTF-8 is reported by its number, and the other rows are imported', function () {
    $result = app(Csv::class)->import("source,target,status,active\n/one,/1,301,1\n/caf\xE9,/cafe,301,1\n/two,/2,301,1\n");

    expect($result['created'])->toBe(2)
        ->and($result['errors'])->toBe(['Row 3: '.__('marketing-toolkit::validation.redirect.encoding')])
        ->and(redirectRules()->sortBy('source')->pluck('source')->values()->all())->toBe(['/one', '/two']);
});

test('a CSV row the database refuses is reported, and the rows before and after it are kept', function () {
    // On Postgres a failed statement aborts the transaction it is in: each row has its own savepoint.
    Redirect::creating(function (Redirect $redirect) {
        if ($redirect->source === '/bad') {
            DB::select('select * from no_such_table');
        }
    });
    Exceptions::fake();

    $result = app(Csv::class)->import("/one,/1\n/bad,/x\n/two,/2\n");

    expect($result['created'])->toBe(2)
        ->and($result['errors'])->toBe([__('marketing-toolkit::validation.csv_row_failed', ['row' => 2])])
        ->and(redirectRules()->sortBy('source')->pluck('source')->values()->all())->toBe(['/one', '/two']);
    Exceptions::assertReported(QueryException::class);
})->skip(testingOnFiles(), 'rows written to the database alone');

test('a CSV import that updates an automatic redirect makes it the user\'s own, as the form does', function () {
    $redirect = rule('/old', '/new');
    updateRule($redirect, ['automatic' => true]);

    app(Csv::class)->import("/old,/newer\n");

    expect(freshRule($redirect))->target->toBe('/newer')->automatic->toBeFalse();
});

test('with case_sensitive off, a chain of rules that comes back in another letter case is refused', function () {
    config(['marketing-toolkit.redirects.case_sensitive' => false]);
    $validator = fn (string $source, string $target) => Rule::validator(['source' => $source, 'target' => $target, 'status' => 301, 'active' => true]);
    rule('/b', '/A');
    rule('/c', '/d');
    rule('/D', '/E/x');
    rule('/e/*', '/F');

    expect($validator('/a', '/B')->errors()->first('target'))->toBe('The redirect from that address leads back here, so the two would loop.')
        ->and($validator('/f', '/C')->errors()->first('target'))->toBe('The redirects from that address lead back here after 3 steps, so visitors would go round in a loop.')
        // A capitalised old address sent to its page is how this option is used, not a loop.
        ->and($validator('/About', '/about')->fails())->toBeFalse();
});

/**
 * Between `composer require` (or an upgrade that adds a table) and `migrate`,
 * the tables are missing. A missing page there answered 500: a site's first
 * deploy of the addon turned one /favicon.ico 404 into a 500 that way.
 */
test('a missing address still answers 404 when the tables are missing, and the error is reported', function () {
    Exceptions::fake();
    Schema::drop('mt_redirects');
    Schema::drop('mt_404s');

    $this->get('/nowhere')->assertNotFound();

    Exceptions::assertReported(QueryException::class);
});
