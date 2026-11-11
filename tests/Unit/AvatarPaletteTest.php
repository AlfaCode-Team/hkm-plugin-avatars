<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Avatars\Domain\AvatarPalette;

#[CoversClass(AvatarPalette::class)]
final class AvatarPaletteTest extends TestCase
{
    // ── Accessibility: the test that guards the palette ─────────────────────

    /** @return list<array{string}> */
    public static function backgrounds(): array
    {
        return array_map(static fn (string $c): array => [$c], AvatarPalette::BACKGROUNDS);
    }

    #[DataProvider('backgrounds')]
    public function test_every_palette_colour_has_a_readable_ink(string $background): void
    {
        // THE test that makes the palette safe to edit. The usual mistake is
        // white text on a fixed palette, which fails the moment somebody adds
        // a pale colour — and initials in white on light yellow are
        // unreadable rather than merely ugly.
        //
        // Because the ink is COMPUTED, a palette edit that breaks WCAG AA
        // fails here instead of shipping.
        $ink      = AvatarPalette::inkFor($background);
        $contrast = AvatarPalette::contrast($background, $ink);

        self::assertGreaterThanOrEqual(
            AvatarPalette::MIN_CONTRAST,
            $contrast,
            \sprintf('%s on %s is only %.2f:1, below WCAG AA 4.5:1', $ink, $background, $contrast),
        );
    }

    public function test_the_ink_is_whichever_of_the_two_has_more_contrast(): void
    {
        // Not "white unless the colour is light": the better of the two, so
        // the answer stays right whatever the palette becomes.
        self::assertSame(AvatarPalette::LIGHT_INK, AvatarPalette::inkFor('#000000'));
        self::assertSame(AvatarPalette::DARK_INK, AvatarPalette::inkFor('#ffffff'));
        self::assertSame(AvatarPalette::DARK_INK, AvatarPalette::inkFor('#ffff00'), 'yellow');
    }

    public function test_contrast_is_symmetric_and_at_least_one(): void
    {
        // `(L1 + 0.05) / (L2 + 0.05)` with the lighter luminance on top, so
        // the argument order cannot change the answer and the ratio is never
        // below 1 — which it would be if the order were taken literally.
        self::assertEqualsWithDelta(
            AvatarPalette::contrast('#000000', '#ffffff'),
            AvatarPalette::contrast('#ffffff', '#000000'),
            0.0001,
        );
        self::assertEqualsWithDelta(21.0, AvatarPalette::contrast('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, AvatarPalette::contrast('#777777', '#777777'), 0.0001);
    }

    public function test_luminance_linearises_each_channel(): void
    {
        // The step people skip. A plain average, or even the weighted sum of
        // the RAW channels, is not luminance and misjudges saturated colours
        // badly — pure blue would look as bright as pure green.
        self::assertEqualsWithDelta(0.0, AvatarPalette::luminance('#000000'), 0.0001);
        self::assertEqualsWithDelta(1.0, AvatarPalette::luminance('#ffffff'), 0.0001);

        // The WCAG coefficients, which are nothing like equal.
        self::assertEqualsWithDelta(0.2126, AvatarPalette::luminance('#ff0000'), 0.0005);
        self::assertEqualsWithDelta(0.7152, AvatarPalette::luminance('#00ff00'), 0.0005);
        self::assertEqualsWithDelta(0.0722, AvatarPalette::luminance('#0000ff'), 0.0005);

        // Green is nearly ten times brighter than blue at full intensity. An
        // unlinearised average would call them identical.
        self::assertGreaterThan(
            AvatarPalette::luminance('#0000ff') * 9,
            AvatarPalette::luminance('#00ff00'),
        );
    }

    public function test_mid_grey_is_not_half_luminance(): void
    {
        // The clearest demonstration that linearisation happens: #808080 is
        // halfway in sRGB and about 21.6% in luminance. A naive
        // `128/255 = 0.502` would be wrong by more than a factor of two.
        self::assertEqualsWithDelta(0.2159, AvatarPalette::luminance('#808080'), 0.002);
    }

    public function test_a_three_digit_hex_is_expanded(): void
    {
        self::assertEqualsWithDelta(
            AvatarPalette::luminance('#ffffff'),
            AvatarPalette::luminance('#fff'),
            0.0001,
        );
    }

    public function test_an_unparseable_colour_becomes_black(): void
    {
        // Black on purpose: it is the value that makes the contrast test fail
        // LOUDLY rather than pass with a plausible colour. These strings come
        // from a constant in the source, so a bad one is a developer error and
        // should be caught by the suite.
        self::assertEqualsWithDelta(0.0, AvatarPalette::luminance('nonsense'), 0.0001);
        self::assertEqualsWithDelta(0.0, AvatarPalette::luminance(''), 0.0001);
        self::assertEqualsWithDelta(0.0, AvatarPalette::luminance('#gggggg'), 0.0001);
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    public function test_the_same_name_always_gets_the_same_colour(): void
    {
        // The whole point. An avatar that changes colour between two page
        // loads reads as a different person.
        $first = AvatarPalette::backgroundFor('José María García');

        for ($i = 0; $i < 20; $i++) {
            self::assertSame($first, AvatarPalette::backgroundFor('José María García'));
        }
    }

    public function test_casing_and_whitespace_do_not_change_the_colour(): void
    {
        // Without normalisation, an avatar would change colour the day a
        // profile form trimmed a trailing space.
        $expected = AvatarPalette::backgroundFor('John Smith');

        foreach (['john smith', 'JOHN SMITH', '  John   Smith  ', "John\tSmith"] as $variant) {
            self::assertSame($expected, AvatarPalette::backgroundFor($variant), $variant);
        }
    }

    public function test_case_folding_is_unicode_aware(): void
    {
        // `strtolower` is byte-wise and leaves `É` alone, so "Égypte" and
        // "égypte" would be two different colours for one word.
        self::assertSame(
            AvatarPalette::backgroundFor('Égypte'),
            AvatarPalette::backgroundFor('égypte'),
        );
    }

    public function test_every_colour_returned_is_from_the_palette(): void
    {
        for ($i = 0; $i < 200; $i++) {
            self::assertContains(
                AvatarPalette::backgroundFor('person-' . $i),
                AvatarPalette::BACKGROUNDS,
            );
        }
    }

    public function test_similar_names_do_not_collide_systematically(): void
    {
        // The reason this is SHA-256 and not `crc32`, which clusters badly on
        // short similar strings — exactly the input here. Two colleagues with
        // near-identical names should not reliably share a colour.
        $buckets = [];

        foreach (['Ann Smith', 'Ann Smyth', 'Anna Smith', 'Ann Smithe', 'Anne Smith'] as $name) {
            $buckets[] = AvatarPalette::backgroundFor($name);
        }

        self::assertGreaterThanOrEqual(
            4,
            \count(array_unique($buckets)),
            'five near-identical names should land in at least four buckets',
        );
    }

    public function test_the_distribution_uses_the_whole_palette(): void
    {
        // Not a test of SHA-256 — it catches an implementation that took too
        // few hash bits, or applied the modulus to something nearly constant.
        $seen = [];

        for ($i = 0; $i < 2000; $i++) {
            $seen[AvatarPalette::backgroundFor('user ' . $i)] = true;
        }

        self::assertCount(
            \count(AvatarPalette::BACKGROUNDS),
            $seen,
            'every palette entry should be reachable',
        );
    }

    public function test_the_palette_has_no_duplicates(): void
    {
        self::assertCount(
            \count(AvatarPalette::BACKGROUNDS),
            array_unique(AvatarPalette::BACKGROUNDS),
        );
    }

    public function test_every_palette_entry_is_a_full_hex_colour(): void
    {
        foreach (AvatarPalette::BACKGROUNDS as $colour) {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/D', $colour, $colour);
        }
    }

    public function test_an_empty_name_still_gets_a_colour(): void
    {
        // A nameless user gets a tile, not a crash and not a transparent gap.
        self::assertContains(AvatarPalette::backgroundFor(''), AvatarPalette::BACKGROUNDS);
        self::assertContains(AvatarPalette::backgroundFor('   '), AvatarPalette::BACKGROUNDS);
        self::assertSame(
            AvatarPalette::backgroundFor(''),
            AvatarPalette::backgroundFor('   '),
            'and both normalise to the same one',
        );
    }
}
