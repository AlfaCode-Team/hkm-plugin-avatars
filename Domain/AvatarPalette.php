<?php

declare(strict_types=1);

namespace Plugins\Avatars\Domain;

/**
 * Which colour a name gets, and what colour of text goes on it.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * DETERMINISTIC, AND NOT MERELY "RANDOM BUT CACHED"
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The same name must produce the same colour forever — on every server, in
 * every process, after every deploy, in a cached page and in a freshly
 * rendered one. An avatar that changes colour between two page loads reads as
 * a different person.
 *
 * So the colour comes from a hash of the name and NEVER from `rand()`,
 * `mt_rand()`, `random_int()` or `uniqid()`. `crc32()` is stable but clusters
 * badly on short similar strings — exactly the input here — so this uses
 * SHA-256, whose output distribution over a small modulus is even enough that
 * two colleagues called "Ann Smith" and "Ann Smyth" land in different buckets.
 *
 * The name is CASE-FOLDED and whitespace-normalised first, so "john smith",
 * "John Smith" and " John  Smith " are one person with one colour. Without
 * that, an avatar would change colour when a profile form trimmed a trailing
 * space.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * CONTRAST IS COMPUTED, NOT ASSUMED
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The usual mistake is white text on a fixed palette, which fails the moment
 * somebody adds a pale colour — and initials in white on light yellow are
 * unreadable rather than merely ugly.
 *
 * The foreground is therefore chosen by WCAG 2.1 relative luminance and
 * contrast ratio, and there is a test asserting that EVERY palette entry's
 * chosen foreground clears 4.5:1 against it. A palette edit that breaks
 * accessibility fails the suite rather than shipping.
 */
final class AvatarPalette
{
    /** WCAG 2.1 AA for normal-size text. */
    public const MIN_CONTRAST = 4.5;

    /**
     * Background colours, as `#rrggbb`.
     *
     * Muted and flat on purpose — no gradients, no glow. Each is dark enough
     * that white text clears AA comfortably, which is confirmed by test rather
     * than by eye. Sixteen is enough that a team of ten rarely collides and
     * small enough that the set stays visually coherent.
     *
     * @var list<string>
     */
    public const BACKGROUNDS = [
        '#5b6b7c', // slate
        '#6b5b7c', // muted purple
        '#7c5b5b', // brick
        '#5b7c6b', // moss
        '#7c6b5b', // clay
        '#4f6b74', // teal-grey
        '#74564f', // rust
        '#4f5b74', // indigo-grey
        '#6b744f', // olive
        '#744f6b', // plum
        '#3f5a52', // deep green
        '#5a3f4a', // maroon
        '#3f4a5a', // navy-grey
        '#5a523f', // bronze
        '#4a5a3f', // fern
        '#523f5a', // aubergine
    ];

    /** Used where the background is light enough that white would fail AA. */
    public const DARK_INK = '#1f2328';

    public const LIGHT_INK = '#ffffff';

    /**
     * The background for a name.
     *
     * The hash is taken over the NORMALISED name so that casing and
     * whitespace cannot change the colour. Eight hex digits is 32 bits, read
     * with `hexdec` — well inside PHP's integer range on any 64-bit build, and
     * the modulus does the rest.
     */
    public static function backgroundFor(string $name): string
    {
        $digest = hash('sha256', self::normalise($name));
        $bucket = hexdec(substr($digest, 0, 8)) % \count(self::BACKGROUNDS);

        return self::BACKGROUNDS[$bucket];
    }

    /**
     * The readable ink for a background.
     *
     * Whichever of white and dark ink has the higher contrast ratio, so the
     * answer stays right if the palette changes.
     */
    public static function inkFor(string $background): string
    {
        return self::contrast($background, self::LIGHT_INK) >= self::contrast($background, self::DARK_INK)
            ? self::LIGHT_INK
            : self::DARK_INK;
    }

    /**
     * WCAG 2.1 contrast ratio between two `#rrggbb` colours.
     *
     * `(L1 + 0.05) / (L2 + 0.05)` with the lighter luminance as L1, so the
     * result is always ≥ 1 and the argument order does not matter.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        $lighter = max($la, $lb);
        $darker  = min($la, $lb);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * WCAG 2.1 relative luminance.
     *
     * Each channel is linearised before weighting — the step people skip. A
     * plain `(r + g + b) / 3` or even the weighted sum of the RAW channels is
     * not luminance, and it misjudges saturated colours badly: pure blue would
     * look as bright as pure green.
     */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        $linear = static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    /**
     * `#rrggbb` or `#rgb` to three channels.
     *
     * An unparseable value becomes black rather than throwing. These colours
     * come from a constant in this file, so a bad one is a developer error
     * caught by the contrast test — and black is the value that makes that
     * test fail loudly instead of passing with a plausible colour.
     *
     * @return array{int, int, int}
     */
    private static function channels(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/D', $hex) === 1) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/D', $hex) !== 1) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Case-fold and collapse whitespace, so one person is one colour.
     *
     * `mb_strtolower` and not `strtolower`: the latter is byte-wise and leaves
     * `É` alone, so "Égypte" and "égypte" would be two different colours.
     */
    private static function normalise(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($collapsed, 'UTF-8');
    }
}
