<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Avatars\Domain\Initials;

/**
 * Initials from a name.
 *
 * Every naive implementation of this is wrong in a way that only shows up on
 * somebody else's name — which is to say in production and never in the
 * fixture whose author is called John Smith. So most of this file is other
 * people's names.
 */
#[CoversClass(Initials::class)]
final class InitialsTest extends TestCase
{
    /** @return list<array{string, string, string}> */
    public static function names(): array
    {
        return [
            // ── The ordinary case ────────────────────────────────────────
            ['John Smith',          'JS', 'two words'],
            ['john smith',          'JS', 'lower case is upper-cased'],
            ['  John   Smith  ',    'JS', 'extra whitespace'],

            // ── First and LAST, never the first two ─────────────────────
            ['José María García',   'JG', 'the short form is José García, not José María'],
            ['Ana Paula de Souza',  'AS', 'four words still give first and last'],
            ['Mary Jane Watson',    'MW', 'the middle name is not an initial'],

            // ── A single word gives ONE letter ──────────────────────────
            ['Cher',                'C',  'two letters from one word would imply a surname'],
            ['Prince',              'P',  'likewise'],

            // ── Scripts without spaces ──────────────────────────────────
            ['山田太郎',              '山', 'one word, so one grapheme — not two of a given name'],
            ['李明',                 '李', 'likewise'],

            // ── Scripts without case ────────────────────────────────────
            ['محمد علي',             'مع', 'Arabic has no case, so upper-casing is a no-op'],
            ['Пётр Ильич',          'ПИ', 'Cyrillic does have case'],

            // ── Punctuation ─────────────────────────────────────────────
            ['John (Jack) Smith',   'JS', 'a parenthesised nickname is not a word'],
            ['John "Jack" Smith',   'JS', 'nor a quoted one'],
            ['(Jack)',              'J',  'leading punctuation is skipped, not taken'],
            ["O'Brien",             'O',  'an apostrophe inside a word is part of it'],
            ['Jean-Pierre Dupont',  'JD', 'split on whitespace only — not on the hyphen'],

            // ── Digits count ────────────────────────────────────────────
            ['7-Eleven Store',      '7S', 'a digit is a usable initial'],

            // ── Nothing usable ──────────────────────────────────────────
            ['',                    '',   'empty'],
            ['   ',                 '',   'whitespace only'],
            ['...',                 '',   'punctuation only'],
            ['###',                 '',   'symbols only'],
        ];
    }

    #[DataProvider('names')]
    public function test_initials_are_taken_from_a_name(string $name, string $expected, string $why): void
    {
        self::assertSame($expected, Initials::of($name), $why);
    }

    // ── Graphemes, not code points ───────────────────────────────────────────

    public function test_a_decomposed_accent_stays_attached_to_its_letter(): void
    {
        // MEASURED on PHP 8.5 / ICU 79: for "école" with a decomposed é
        // (e + U+0301), `mb_substr($s, 0, 1)` returns just 0x65 — the letter
        // with the accent dropped — while `grapheme_substr` keeps both bytes.
        // An avatar showing "E" for "École" is a different letter.
        $decomposed = "E\u{0301}cole Normale"; // E + combining acute

        $initial = Initials::of($decomposed, 1);

        self::assertSame("E\u{0301}", $initial, 'the combining mark survives');
        self::assertSame(2, mb_strlen($initial), 'two code points');
        self::assertSame(1, grapheme_strlen($initial), 'but one grapheme');
    }

    public function test_a_precomposed_accent_also_works(): void
    {
        self::assertSame('É', Initials::of('École Normale', 1));
    }

    public function test_an_emoji_zwj_sequence_is_not_cut_in_half(): void
    {
        // MEASURED: a family emoji (👨‍👩‍👧) is 5 code points and 1 grapheme, so
        // `mb_substr($s, 0, 1)` emits a lone man emoji followed by a dangling
        // zero-width joiner — a broken glyph or a replacement box.
        //
        // A name is not usually an emoji, but a display name very often
        // contains one, and the FIRST character is exactly where people put it.
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        $initial = Initials::of($family . ' Family', 1);

        // An emoji is neither a letter nor a digit, so it is skipped and the
        // next usable grapheme is taken — which is the right answer. The point
        // of this test is that nothing is EMITTED half-cut.
        self::assertSame('F', $initial);
        self::assertSame('', Initials::of($family), 'an emoji-only name yields nothing');
    }

    public function test_a_sharp_s_stays_one_glyph_when_upper_cased(): void
    {
        // `mb_strtoupper('ß')` is `'SS'` — two characters from one. Without
        // re-taking the first grapheme, a German name beginning with ß would
        // widen the avatar's text by a letter.
        $initial = Initials::of('ßeta', 1);

        self::assertSame('S', $initial);
        self::assertSame(1, mb_strlen($initial));
    }

    // ── Hostile input ────────────────────────────────────────────────────────

    /** @return list<array{string, string, string}> */
    public static function controlCharacterNames(): array
    {
        return [
            ["John\r\nSmith",      'JS', 'a CR rewrites the line it is on in a terminal'],
            ["John\x00Smith",      'JS', 'a NUL can truncate C-side string handling'],
            ["John\tSmith",        'JS', 'a tab breaks a column in a CLI table'],

            // The ESC becomes a SEPARATOR, so what follows it is its own word
            // — `[2JSmith`, whose first usable grapheme is the digit `2`. That
            // is the documented rule working, not a surprise: a digit is a
            // usable initial, and the alternative (deleting the control
            // character and joining the two halves) would silently change a
            // name into a different one.
            ["John\x1b[2JSmith",   'J2', 'an ESC separates, so a digit becomes the second initial'],
        ];
    }

    #[DataProvider('controlCharacterNames')]
    public function test_control_characters_never_reach_an_initial(
        string $name,
        string $expected,
        string $why,
    ): void {
        // They would otherwise reach a log line through an error message about
        // the name. The invariant that matters is the second assertion: the
        // output is letters and digits only, whatever went in.
        $initials = Initials::of($name);

        self::assertSame($expected, $initials, $why);
        self::assertMatchesRegularExpression('/^[\p{L}\p{N}]*$/u', $initials);
    }

    public function test_a_markup_name_yields_only_letters(): void
    {
        // The first half of the XSS defence: by the time anything reaches the
        // SVG there is no `<` left to escape. The second half is the escaping
        // itself, and InitialsAvatarTest drives this name all the way through.
        //
        // `<svg onload=alert(1)>` is TWO whitespace-separated words, so it
        // gives `S` and `O` — first and last, like any other two-word name.
        self::assertSame('SA', Initials::of('<script>alert(1)</script> Attacker'));
        self::assertSame('SO', Initials::of('<svg onload=alert(1)>'));
        self::assertSame('S', Initials::of('<svg>'), 'one word, one initial');

        foreach (['<script>alert(1)</script> Attacker', '<svg onload=alert(1)>', '"><img src=x>'] as $name) {
            self::assertDoesNotMatchRegularExpression(
                '/[<>&"\']/',
                Initials::of($name),
                $name,
            );
        }
    }

    public function test_unicode_spaces_separate_words(): void
    {
        // A non-breaking space (U+00A0) and an ideographic space (U+3000) are
        // both what a person typed as a space, and both arrive through a paste.
        self::assertSame('JS', Initials::of("John\u{00A0}Smith"));
        self::assertSame('JS', Initials::of("John\u{3000}Smith"));
    }

    public function test_invalid_utf8_does_not_produce_an_exception(): void
    {
        // A name column can hold whatever was inserted into it. Whatever comes
        // back, this must not throw — an avatar is cosmetic and must never be
        // the reason a page 500s.
        $result = Initials::of("Jo\xC3\x28hn Smith");

        self::assertIsString($result);
    }

    // ── The max argument ─────────────────────────────────────────────────────

    public function test_one_initial_can_be_asked_for(): void
    {
        self::assertSame('J', Initials::of('John Smith', 1));
    }

    public function test_the_max_is_clamped_to_two(): void
    {
        // Three initials in a 40-pixel circle is a smudge, so the cap is not
        // negotiable — and a 0 or a negative would otherwise yield nothing.
        self::assertSame('JS', Initials::of('John Michael Smith', 5));
        self::assertSame('J', Initials::of('John Smith', 0));
        self::assertSame('J', Initials::of('John Smith', -3));
    }

    public function test_the_result_is_never_more_than_two_graphemes(): void
    {
        foreach (self::names() as [$name]) {
            self::assertLessThanOrEqual(
                2,
                grapheme_strlen(Initials::of($name)) ?: 0,
                $name,
            );
        }
    }

    public function test_initials_are_deterministic(): void
    {
        // Not a tautology worth skipping: it is the property the avatar's
        // caching depends on, and anything that reached for randomness or a
        // locale-dependent case fold would break it.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('JG', Initials::of('José María García'));
        }
    }
}
