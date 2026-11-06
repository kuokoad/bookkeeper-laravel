<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Errors\ValidationError;

/**
 * Whether an uploaded file is really an image, from its own bytes: a port of
 * the old lib/image.ts. The browser's type and the filename are chosen by
 * whoever uploads, so neither is consulted. SVG is refused (it can carry
 * script), and dimensions are capped as well as bytes.
 */
final class LogoImage
{
    public const MAX_BYTES = 1_000_000;

    public const MAX_DIMENSION = 4000;

    /** @return array{mime: string, width: int, height: int, bytes: int} */
    public static function inspect(string $data): array
    {
        $length = strlen($data);
        if ($length === 0) {
            throw new ValidationError('That file is empty.');
        }
        if ($length > self::MAX_BYTES) {
            throw new ValidationError(self::tooLarge($length));
        }

        $mime = null;
        $size = null;
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            $mime = 'image/png';
            $size = $length < 24 ? null : ['width' => self::u32be($data, 16), 'height' => self::u32be($data, 20)];
        } elseif (str_starts_with($data, "\xff\xd8\xff")) {
            $mime = 'image/jpeg';
            $size = self::jpegSize($data);
        } elseif (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
            $mime = 'image/webp';
            $size = self::webpSize($data);
        }

        if ($mime === null) {
            $looksLikeSvg = preg_match('/^\s*(<\?xml|<svg)/i', substr($data, 0, 200)) === 1;
            throw new ValidationError($looksLikeSvg
                ? 'SVG images are not accepted, because they can carry hidden instructions that run in the browser. Please use a PNG, JPEG or WebP.'
                : 'That does not look like a PNG, JPEG or WebP image.');
        }
        if ($size === null || $size['width'] <= 0 || $size['height'] <= 0) {
            throw new ValidationError('That image appears to be damaged — its size could not be read.');
        }
        if ($size['width'] > self::MAX_DIMENSION || $size['height'] > self::MAX_DIMENSION) {
            throw new ValidationError("That image is {$size['width']}x{$size['height']} pixels. Please use one no larger than ".self::MAX_DIMENSION.' pixels on a side.');
        }

        return ['mime' => $mime, 'width' => $size['width'], 'height' => $size['height'], 'bytes' => $length];
    }

    /** "That image is 1.2 MB. Please use one under 1 MB." (toFixed(1) of a non-tie in practice). */
    public static function tooLarge(int $bytes): string
    {
        return 'That image is '.number_format($bytes / 1_000_000, 1, '.', '').' MB. Please use one under '.(self::MAX_BYTES / 1_000_000).' MB.';
    }

    /** JPEG: walk the marker segments to the frame header; there is no fixed offset. */
    private static function jpegSize(string $data): ?array
    {
        $offset = 2;
        $length = strlen($data);
        while ($offset + 9 < $length) {
            if (ord($data[$offset]) !== 0xFF) {
                return null;
            }
            $marker = ord($data[$offset + 1]);
            // C4, C8 and CC look like frame markers but are tables.
            if ($marker >= 0xC0 && $marker <= 0xCF && ! in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                return ['height' => self::u16be($data, $offset + 5), 'width' => self::u16be($data, $offset + 7)];
            }
            $segment = self::u16be($data, $offset + 2);
            if ($segment < 2) {
                return null;
            }
            $offset += 2 + $segment;
        }

        return null;
    }

    private static function webpSize(string $data): ?array
    {
        if (strlen($data) < 30) {
            return null;
        }

        return match (substr($data, 12, 4)) {
            'VP8 ' => ['width' => self::u16le($data, 26) & 0x3FFF, 'height' => self::u16le($data, 28) & 0x3FFF],
            'VP8L' => (function () use ($data): array {
                $bits = unpack('V', substr($data, 21, 4))[1];

                return ['width' => ($bits & 0x3FFF) + 1, 'height' => (($bits >> 14) & 0x3FFF) + 1];
            })(),
            'VP8X' => [
                'width' => (ord($data[24]) | ord($data[25]) << 8 | ord($data[26]) << 16) + 1,
                'height' => (ord($data[27]) | ord($data[28]) << 8 | ord($data[29]) << 16) + 1,
            ],
            default => null,
        };
    }

    private static function u32be(string $data, int $at): int
    {
        return unpack('N', substr($data, $at, 4))[1];
    }

    private static function u16be(string $data, int $at): int
    {
        return unpack('n', substr($data, $at, 2))[1];
    }

    private static function u16le(string $data, int $at): int
    {
        return unpack('v', substr($data, $at, 2))[1];
    }
}
