<?php

declare(strict_types=1);

use Tests\Support\ProviderLeak;

/*
| Self-test of the M-23 scan helper: the image-URL exemptions cover only
| http(s) URLs, never other text that happens to sit in an image list.
*/

it('flags or exempts each kind of value', function (array $data, array $expected): void {
    expect(ProviderLeak::find($data))->toBe($expected);
})->with([
    'provider URL under a url key' => [['imageUrl' => 'https://pixelforge.promptedit.dev/images/gen_a.png'], []],
    'provider URL in an image list' => [['referenceImages' => ['https://pixelforge.promptedit.dev/images/gen_a.png']], []],
    'non-URL text in an image list' => [['referenceImages' => ['pixelforge refused gen_a']], [
        'value referenceImages.0 contains pixelforge',
        'value referenceImages.0 contains gen_',
    ]],
    'non-http URL in an image list' => [['referenceImages' => ['ftp://pixelforge.example/a.png']], ['value referenceImages.0 contains pixelforge']],
    'provider word in a plain value' => [['message' => 'Pixelforge is busy'], ['value message contains pixelforge']],
    'provider word in a key' => [['pixelforgeImages' => []], ['key pixelforgeImages']],
]);
