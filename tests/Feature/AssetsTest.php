<?php

use JothamLec\MarketingToolkit\Support\Assets;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Value;

test('the one asset a field holds, in whatever shape it comes', function () {
    AssetContainer::find('assets')->disk()->put('share.png', file_get_contents(__DIR__.'/../fixtures/share.png'));
    $asset = Asset::find('assets::share.png');

    expect(Assets::from('assets::share.png')?->id())->toBe('assets::share.png')
        ->and(Assets::from($asset))->toBe($asset)
        ->and(Assets::from([$asset]))->toBe($asset)
        ->and(Assets::from(new Value($asset)))->toBe($asset)
        ->and(Assets::from(Asset::query()->where('container', 'assets')->where('path', 'share.png'))?->id())->toBe('assets::share.png')
        ->and(Assets::from('assets::missing.png'))->toBeNull()
        ->and(Assets::from(null))->toBeNull()
        ->and(Assets::from([]))->toBeNull();
});
