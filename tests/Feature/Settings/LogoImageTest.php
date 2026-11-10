<?php

declare(strict_types=1);

/*
 * Port of the old tests/lib/image.test.ts against App\Support\LogoImage.
 * Real 1x1 images written by actual encoders (the old test's own fixtures):
 * a parser proven only on hand-made bytes proves nothing about an upload.
 */

use App\Domain\Errors\ValidationError;
use App\Support\LogoImage;

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';
const WEBP_1X1 = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

/** A real 1x1 PNG whose header claims another size. */
function pngClaiming(int $width, int $height): string
{
    $png = base64_decode(PNG_1X1);

    return substr_replace($png, pack('N', $width).pack('N', $height), 16, 8);
}

it('reads a real PNG, JPEG and WebP', function (string $base64, string $mime) {
    expect(LogoImage::inspect(base64_decode($base64)))->toMatchArray(['mime' => $mime, 'width' => 1, 'height' => 1]);
})->with([
    'PNG' => [PNG_1X1, 'image/png'],
    'JPEG' => [JPEG_1X1, 'image/jpeg'],
    'WebP' => [WEBP_1X1, 'image/webp'],
]);

it('reports the byte length it actually received', function () {
    $png = base64_decode(PNG_1X1);
    expect(LogoImage::inspect($png)['bytes'])->toBe(strlen($png));
});

it('refuses an SVG, and says why', function (string $svg) {
    // An SVG served from the shop's own origin runs script with the viewer's session.
    expect(fn () => LogoImage::inspect($svg))->toThrow(ValidationError::class, 'SVG images are not accepted');
})->with([
    'bare' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'with an XML declaration' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>',
]);

it('refuses HTML, a script and a GIF as not a PNG, JPEG or WebP', function (string $data) {
    // The filename and the browser's Content-Type never reach the parser.
    expect(fn () => LogoImage::inspect($data))->toThrow(ValidationError::class, 'That does not look like a PNG, JPEG or WebP image.');
})->with([
    'HTML' => '<html><script>alert(document.cookie)</script></html>',
    'a script' => "#!/bin/sh\nrm -rf /",
    'a GIF' => base64_decode('R0lGODlhAQABAAAAACw='),
]);

it('refuses an empty file', function () {
    expect(fn () => LogoImage::inspect(''))->toThrow(ValidationError::class, 'That file is empty.');
});

it('refuses a PNG header with nothing behind it as damaged', function () {
    expect(fn () => LogoImage::inspect(substr(base64_decode(PNG_1X1), 0, 12)))
        ->toThrow(ValidationError::class, 'That image appears to be damaged');
});

it('refuses a file over the size cap and accepts one just under it', function () {
    $png = base64_decode(PNG_1X1);
    // Padding after a complete PNG: the parser reads the header, not the tail.
    expect(fn () => LogoImage::inspect(str_pad($png, LogoImage::MAX_BYTES + 1, "\x00")))
        ->toThrow(ValidationError::class, 'Please use one under 1 MB.')
        ->and(LogoImage::inspect(str_pad($png, LogoImage::MAX_BYTES - 1, "\x00"))['width'])->toBe(1);
});

it('refuses absurd dimensions, stating the actual size', function () {
    // Small file, huge picture: the shape of a decompression bomb.
    $over = LogoImage::MAX_DIMENSION + 1;
    expect(fn () => LogoImage::inspect(pngClaiming($over, $over)))->toThrow(ValidationError::class, 'pixels')
        ->and(fn () => LogoImage::inspect(pngClaiming(9000, 7000)))->toThrow(ValidationError::class, 'That image is 9000x7000 pixels.');
});
