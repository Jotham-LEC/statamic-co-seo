<?php

use Illuminate\Support\Facades\Vite;
use Inertia\Testing\AssertableInertia;
use JothamLec\MarketingToolkit\Legacy\OldConfig;
use JothamLec\MarketingToolkit\Tracking\Tracking;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Token;
use Statamic\Tokens\Handlers\LivePreview;

function trackingHead(): string
{
    return renderAt('/', '<s:mt:head />');
}

function trackingBody(): string
{
    return renderAt('/', '<s:mt:body />');
}

test('no tags until an ID is set; then each prints in the head, and GTM\'s noscript in the body', function () {
    seoGlobal([]);
    expect(app(Tracking::class)->head())->toBe('')->and(trackingHead())->toContain('<title>');

    seoGlobal([
        'gtm_id' => 'gtm-abc1234',
        'ga4_id' => 'G-ABCDE12345',
        'posthog_key' => 'phc_abcdefghijklmnopqrstuvwxyz0123',
        'posthog_host' => 'https://eu.i.posthog.com',
        'meta_pixel_id' => '123456789012',
        'linkedin_partner_id' => '1234567',
    ]);
    $head = trackingHead();
    $body = trackingBody();

    expect($head)
        ->toContain("'dataLayer',\"GTM-ABC1234\"")
        ->toContain('https://www.googletagmanager.com/gtag/js?id=G-ABCDE12345')
        ->toContain("gtag('config',\"G-ABCDE12345\")")
        ->toContain('posthog.init("phc_abcdefghijklmnopqrstuvwxyz0123",{"api_host":"https:\/\/eu.i.posthog.com","ui_host":"https:\/\/eu.posthog.com","person_profiles":"identified_only"})')
        ->toContain("fbq('init',\"123456789012\")")
        ->toContain('window._linkedin_data_partner_ids.push("1234567")')
        ->not->toContain('mtConsent')
        // Without Consent Mode, the snippets load the scripts themselves, as their makers wrote them.
        ->toContain("s.parentNode.insertBefore(t,s)\n}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');")
        ->toContain("p.src=s.api_host.replace('.i.posthog.com','-assets.i.posthog.com')+'/static/array.js'")
        // Before the meta tags: Google's tags want to be as high in the <head> as they can.
        ->and(strpos($head, 'gtm.js'))->toBeLessThan(strpos($head, '<title>'))
        ->and($body)->toContain('https://www.googletagmanager.com/ns.html?id=GTM-ABC1234')
        ->toContain('https://www.facebook.com/tr?id=123456789012')
        ->toContain('https://px.ads.linkedin.com/collect/?pid=1234567');
});

test('.env wins over the control panel', function () {
    seoGlobal(['gtm_id' => 'GTM-FROMCP1', 'ga4_id' => 'G-FROMCP123']);
    config(['marketing-toolkit.tracking.gtm_id' => 'GTM-FROMENV']);

    expect(app(Tracking::class)->ids())->toMatchArray(['gtm' => 'GTM-FROMENV', 'ga4' => 'G-FROMCP123'])
        ->and(app(Tracking::class)->fromConfig())->toMatchArray(['gtm' => true, 'ga4' => false]);
});

test('each site of a multisite prints its own container, takes its origin\'s until it sets one, and can turn its tags off', function () {
    multisite();
    seoGlobal(['gtm_id' => 'GTM-DEFAULT1']);
    seoGlobal([], 'cothinking');

    Site::setCurrent('cothinking');
    expect(app(Tracking::class)->ids()['gtm'])->toBe('GTM-DEFAULT1');

    seoGlobal(['gtm_id' => 'GTM-COTHINK1'], 'cothinking');
    expect(app(Tracking::class)->head())->toContain('"GTM-COTHINK1"')->not->toContain('GTM-DEFAULT1');

    seoGlobal(['site_tracking' => false], 'cothinking');
    expect(app(Tracking::class)->head())->toBe('')->and(app(Tracking::class)->body())->toBe('');

    Site::setCurrent('default');
    expect(app(Tracking::class)->head())->toContain('"GTM-DEFAULT1"');
});

test('a tracking key in the config can name each site\'s ID, and a site left out takes its own Tracking tab\'s', function () {
    multisite();
    seoGlobal(['gtm_id' => 'GTM-FROMCP1']);
    seoGlobal(['gtm_id' => 'GTM-FROMCP2'], 'cothinking');
    config(['marketing-toolkit.tracking.gtm_id' => ['default' => 'GTM-FROMENV']]);

    expect(app(Tracking::class)->ids()['gtm'])->toBe('GTM-FROMENV')
        ->and(app(Tracking::class)->fromConfig()['gtm'])->toBeTrue();

    Site::setCurrent('cothinking');
    expect(app(Tracking::class)->ids()['gtm'])->toBe('GTM-FROMCP2')
        ->and(app(Tracking::class)->fromConfig()['gtm'])->toBeFalse();
});

test('a config/marketing-toolkit.php published with the old tracking keys keeps working', function () {
    expect(OldConfig::upgrade(['tracking' => ['gtm' => 'GTM-OLDKEY1', 'linkedin' => '1234567', 'ga4_id' => 'G-NEWKEY123', 'ga4' => 'G-OLDKEY123'], 'robots_txt' => false]))
        ->toBe(['tracking' => ['ga4_id' => 'G-NEWKEY123', 'gtm_id' => 'GTM-OLDKEY1', 'linkedin_partner_id' => '1234567'], 'robots_txt' => ['enabled' => false]]);
});

test('an ID that is set but isn\'t one is reported, not printed', function () {
    seoGlobal(['ga4_id' => 'UA-12345-1']);
    config(['marketing-toolkit.tracking.gtm_id' => 'GTM-AB1']);

    expect(app(Tracking::class)->invalid())->toBe(['gtm' => 'GTM-AB1', 'ga4' => 'UA-12345-1'])
        ->and(collect(app(Tracking::class)->ids())->filter()->all())->toBe([]);
});

test('an ID that doesn\'t look like one is never printed', function (string $field, string $value) {
    seoGlobal([$field => $value]);

    expect(app(Tracking::class)->head())->toBe('')
        ->and(collect(app(Tracking::class)->ids())->filter()->all())->toBe([]);
})->with([
    ['gtm_id', 'GTM-1234\');alert(1)//'],
    ['ga4_id', '</script><script>alert(1)</script>'],
    ['meta_pixel_id', '123abc'],
    ['linkedin_partner_id', '12 34'],
    ['posthog_key', 'phc_short'],
]);

test('PostHog\'s host must be an https address; anything else is the US cloud', function () {
    seoGlobal(['posthog_key' => 'phc_abcdefghijklmnopqrstuvwxyz0123', 'posthog_host' => 'javascript:alert(1)']);

    expect(app(Tracking::class)->posthogHost())->toBe('https://us.i.posthog.com');
});

test('behind a proxy, PostHog gets the app address set for its toolbar; on PostHog\'s cloud, the one its host implies', function () {
    $key = 'phc_abcdefghijklmnopqrstuvwxyz0123';
    $init = fn () => str(trackingHead())->match('/posthog\.init\([^,]+,(\{[^}]*\})\)/')->toString();

    seoGlobal(['posthog_key' => $key, 'posthog_host' => 'https://t.example.test', 'posthog_ui_host' => 'https://eu.posthog.com/']);
    expect(json_decode($init(), true))->toMatchArray(['api_host' => 'https://t.example.test', 'ui_host' => 'https://eu.posthog.com']);

    seoGlobal(['posthog_key' => $key, 'posthog_host' => 'https://t.example.test', 'posthog_ui_host' => 'javascript:alert(1)']);
    expect(json_decode($init(), true))->not->toHaveKey('ui_host');

    seoGlobal(['posthog_key' => $key]);
    config(['marketing-toolkit.tracking.posthog_ui_host' => 'https://us.posthog.com']);
    expect(app(Tracking::class)->posthogUiHost())->toBe('https://us.posthog.com');

    config(['marketing-toolkit.tracking.posthog_ui_host' => null]);
    expect(app(Tracking::class)->posthogUiHost())->toBe('https://us.posthog.com')
        ->and(json_decode($init(), true))->toMatchArray(['api_host' => 'https://us.i.posthog.com', 'ui_host' => 'https://us.posthog.com']);
});

test('nothing prints outside production, or in Live Preview', function () {
    seoGlobal(['gtm_id' => 'GTM-ABC1234']);

    $this->app['env'] = 'local';
    expect(trackingHead())->not->toContain('gtm.js')->and(trackingBody())->not->toContain('googletagmanager');

    config(['marketing-toolkit.tracking.environments' => ['production', 'local']]);
    expect(trackingHead())->toContain('gtm.js');

    $this->app['env'] = 'production';
    $token = Token::make(null, LivePreview::class);
    $token->save();
    expect(renderAt('/?token='.$token->token(), '<s:mt:head />'))->not->toContain('gtm.js')
        ->and(request()->isLivePreview())->toBeTrue();
});

test('Consent Mode: the defaults come before the tags, and the banner\'s wait', function () {
    seoGlobal(['gtm_id' => 'GTM-ABC1234', 'consent_mode' => true, 'consent_analytics_storage' => 'granted', 'consent_wait_for_update' => 800]);
    $head = trackingHead();

    expect($head)->toContain("gtag('consent','default',{\"ad_storage\":\"denied\",\"analytics_storage\":\"granted\",\"ad_user_data\":\"denied\",\"ad_personalization\":\"denied\",\"wait_for_update\":800});")
        ->and(strpos($head, "gtag('consent'"))->toBeLessThan(strpos($head, 'gtm.js'))
        // GTM and GA4 read Consent Mode themselves: no bridge for them.
        ->and($head)->not->toContain('mtConsent');
});

test('Consent Mode holds back Meta, LinkedIn and PostHog until the banner says yes, and drops their noscript pixels', function () {
    seoGlobal([
        'consent_mode' => true,
        'posthog_key' => 'phc_abcdefghijklmnopqrstuvwxyz0123',
        'meta_pixel_id' => '123456789012',
        'linkedin_partner_id' => '1234567',
    ]);
    $head = trackingHead();

    expect($head)->toContain('w.mtConsent=function')
        ->toContain("fbq('consent','revoke');mtConsent(")
        ->toContain('"opt_out_capturing_by_default":true,"persistence":"memory"')
        // Banners send the update on every page: opt in once, without an $opt_in event each time.
        ->toContain("if(!posthog.has_opted_in_capturing()){posthog.set_config({persistence:'localStorage+cookie'});posthog.opt_in_capturing({captureEventName:false});}")
        ->toContain("mtConsent(function(s){if(s.ad_storage==='granted')load();});")
        // Meta's and PostHog's scripts aren't downloaded before consent either: only the bridge loads them.
        ->toContain("if(g)mtConsent.load('https://connect.facebook.net/en_US/fbevents.js');")
        ->toContain('mtConsent.load("https:\/\/us-assets.i.posthog.com\/static\/array.js");l=1;')
        ->toContain("else if(s.analytics_storage==='denied'&&l){posthog.opt_out_capturing();}")
        ->not->toContain('insertBefore(t,s)')
        ->not->toContain("p.crossOrigin='anonymous'")
        ->and(strpos($head, 'w.mtConsent=function'))->toBeLessThan(strpos($head, 'fbevents.js'))
        ->and(trackingBody())->not->toContain('facebook.com/tr')->not->toContain('px.ads.linkedin.com');
});

test('Consent Mode drops GTM\'s noscript iframe too: without JavaScript there are no consent defaults', function () {
    seoGlobal(['gtm_id' => 'GTM-ABC1234', 'consent_mode' => true]);

    expect(trackingBody())->not->toContain('googletagmanager.com/ns.html');
});

test('regions (Pro): granted everywhere, the defaults in those regions, and the bridge waits for the banner', function () {
    seoGlobal(['consent_mode' => true, 'consent_regions' => ['EEA', 'us-ca', 'nonsense!'], 'meta_pixel_id' => '123456789012']);
    $consent = app(Tracking::class)->consent();
    $head = trackingHead();

    expect($consent['regions'])->toContain('FR', 'GB', 'CH', 'NO', 'US-CA')->not->toContain('NONSENSE!')
        ->and($head)->toContain("gtag('consent','default',{\"ad_storage\":\"granted\",\"analytics_storage\":\"granted\",\"ad_user_data\":\"granted\",\"ad_personalization\":\"granted\",\"wait_for_update\":500});")
        ->toContain('"region":["AT",')
        ->toContain('r=true');
});

test('regions: outside them, Google\'s tags still wait for the banner, so a visitor who declined sends no granted hit', function () {
    seoGlobal(['consent_mode' => true, 'consent_regions' => ['EEA'], 'consent_wait_for_update' => 1500]);

    expect(substr_count(trackingHead(), '"wait_for_update":1500'))->toBe(2);
});

test('the bridge hands the banner\'s update to Google\'s tags before its own callbacks, and a callback that throws is skipped', function () {
    seoGlobal(['consent_mode' => true, 'meta_pixel_id' => '123456789012']);

    expect(trackingHead())->toContain('d.push=function(){var x=p.apply(d,arguments);')
        ->toContain('function call(fn){try{fn(s);}catch(e){}}')
        ->toContain('w.mtConsent=function(fn){f.push(fn);call(fn);};');
});

test('the CP warns when GTM is set beside another tracker, on the overview', function () {
    seoGlobal(['gtm_id' => 'GTM-ABC1234', 'ga4_id' => 'G-ABCDE12345', 'meta_pixel_id' => '123456789012']);
    $this->actingAs(cpUser(super: true));

    expect(app(Tracking::class)->besideGtm())->toBe(['Google Analytics 4', 'Meta Pixel']);

    $this->get(cp_route('mt.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tracking.overlap', ['Google Analytics 4', 'Meta Pixel'])
        ->where('tracking.tools.0', ['name' => 'Google Tag Manager', 'id' => 'GTM-ABC1234', 'from_env' => false])
        ->where('tracking.consent', false));

    seoGlobal(['ga4_id' => 'G-ABCDE12345']);
    expect(app(Tracking::class)->besideGtm())->toBe([]);
});

test('the overview says where an ID comes from, and which set ones aren\'t IDs', function () {
    seoGlobal(['ga4_id' => 'UA-12345-1', 'meta_pixel_id' => '123456789012']);
    config(['marketing-toolkit.tracking.gtm_id' => 'GTM-AB1', 'marketing-toolkit.tracking.linkedin_partner_id' => '1234567']);
    app()->bind(Tracking::class, LinkedInFromCode::class);
    $this->actingAs(cpUser(super: true));

    $this->get(cp_route('mt.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tracking.tools', [
            ['name' => 'Meta Pixel', 'id' => '123456789012', 'from_env' => false],
            ['name' => 'LinkedIn Insight Tag', 'id' => '7654321', 'from_env' => false],
        ])
        ->where('tracking.invalid', [
            'The Google Tag Manager value “GTM-AB1” in MT_GTM_ID isn’t a valid ID, so the tag isn’t added to the site.',
            'The Google Analytics 4 value “UA-12345-1” in Settings → Tracking isn’t a valid ID, so the tag isn’t added to the site.',
        ]));
});

class LinkedInFromCode extends Tracking
{
    public function ids(): array
    {
        return [...parent::ids(), 'linkedin' => '7654321'];
    }
}

test('the Tracking tab of Marketing settings shows its warning through a custom condition, and validates IDs', function () {
    Blueprint::find('globals.marketing')?->delete();
    $this->artisan('statamic:mt:install')->assertSuccessful();
    $fields = Blueprint::find('globals.marketing')->fields();

    expect($fields->get('tracking_overlap')->config()['if'])->toBe('mtTrackingOverlap')
        ->and($fields->get('gtm_id')->rules()['gtm_id'])->toContain('regex:/^GTM-[A-Z0-9]{4,12}$/i');

    $this->actingAs(cpUser(super: true))
        ->get(GlobalSet::findByHandle('marketing')->in('default')->editUrl())
        ->assertOk();
});

test('a CSP nonce is added to every script', function () {
    seoGlobal(['gtm_id' => 'GTM-ABC1234', 'consent_mode' => true]);
    Vite::useCspNonce('abc123');

    $head = app(Tracking::class)->head();

    expect(substr_count($head, '<script'))->toBeGreaterThan(1)
        ->toBe(substr_count($head, '<script nonce="abc123">'));
});

test('install adds a Marketing settings tab the site removed, on request', function () {
    Blueprint::find('globals.seo')?->delete();
    Blueprint::make('marketing')->setNamespace('globals')->setContents(['tabs' => ['crawlers' => ['sections' => [['fields' => [['handle' => 'robots_extra', 'field' => ['type' => 'textarea']]]]]]]])->save();

    $this->artisan('statamic:mt:install', ['--tab' => ['tracking', 'consent']])->assertSuccessful();

    expect(Blueprint::find('globals.marketing')->fields()->all()->keys())->toContain('gtm_id', 'consent_mode', 'consent_regions')->not->toContain('conversions')
        ->and(Blueprint::find('globals.seo')->fields()->all()->keys())->not->toContain('gtm_id');
});

test('the control panel’s script gets the Tracking tab’s fields from the tracking code', function () {
    expect(Statamic\Statamic::jsonVariables(request())['marketingToolkit']['trackingFields'])->toBe(Tracking::FIELDS);
});
