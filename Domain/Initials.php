<?php

declare(strict_types=1);

namespace Plugins\Avatars\Domain;

/**
 * Initials from a person's name.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THIS IS NOT `$name[0]`, AND THE REASONS ARE ALL MEASURED
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Every naive implementation of this is wrong in a way that only shows up on
 * somebody else's name, which is to say in production and never in the test
 * fixture whose author is called John Smith.
 *
 * ── GRAPHEMES, NOT CHARACTERS ──────────────────────────────────────────────
 *
 * `mb_substr()` cuts at a CODE POINT, and a user-perceived character is often
 * several. Measured on PHP 8.5 / ICU 79:
 *
 *   "école" with a DECOMPOSED é (e + U+0301):
 *       mb_substr($s, 0, 1)        → 0x65          just "e" — the accent is gone
 *       grapheme_substr($s, 0, 1)  → 0x65 0xCC81   "é"
 *
 *   A family emoji (👨‍👩‍👧, three people joined by zero-width joiners):
 *       mb_strlen($s)       → 5
 *       grapheme_strlen($s) → 1
 *
 *   So `mb_substr($s, 0, 1)` on that name emits HALF A ZWJ SEQUENCE — a lone
 *   man emoji followed by a dangling joiner, which renders as a broken glyph
 *   or a replacement box.
 *
 * `grapheme_*` is therefore used throughout, with a hand-rolled UTF-8 fallback
 * for a build that has neither `ext-intl` nor the Symfony polyfill. The
 * fallback keeps combining marks attached, which is the common case; it does
 * not understand ZWJ sequences, and says so.
 *
 * ── FIRST AND LAST WORD, NOT THE FIRST TWO ─────────────────────────────────
 *
 * "José María García" → `JG`, not `JM`. Taking the first two words gives the
 * given name and the MIDDLE name, which is not how anyone abbreviates a name:
 * the short form of that name is "José García". Slack, Gravatar and every
 * address book do first-and-last, and so does this.
 *
 * ── A SINGLE WORD GIVES ONE LETTER ─────────────────────────────────────────
 *
 * "Cher" → `C`, never `CH`. Two letters taken from one word read as the
 * initials of two names, so a mononym would appear to have a surname.
 *
 * Which also handles scripts that do not use spaces: "山田太郎" is one word, so
 * it yields the single grapheme 山 — correct, and what a Japanese reader
 * expects, rather than two characters of a given name.
 *
 * ── A NAME WITH NO LETTERS GIVES NOTHING ───────────────────────────────────
 *
 * `"..."`, `"   "` and `""` all return `''`. Not `'?'`: a question mark is a
 * character the user never typed, appearing where their name should be. An
 * empty result lets {@see InitialsAvatar} render a plain coloured tile, which
 * reads as "no name" because that is what is true.
 */
final class Initials
{
    /** Never more than two. Three initials in a 40-pixel circle is a smudge. */
    public const MAX = 2;

    /**
     * @param int $max 1 or 2; anything else is clamped into that range
     */
    public static function of(string $name, int $max = self::MAX): string
    {
        $max   = max(1, min(self::MAX, $max));
        $words = self::words($name);

        if ($words === []) {
            return '';
        }

        // First and last. With one word they are the same word, and `$max`
        // being 1 collapses to the same thing — so the single-letter case
        // needs no branch of its own.
        $picked = \count($words) === 1 || $max === 1
            ? [$words[0]]
            : [$words[0], $words[\count($words) - 1]];

        $initials = '';

        foreach ($picked as $word) {
            $initials .= self::firstLetter($word);
        }

        return $initials;
    }

    /**
     * The words of a name: whitespace-separated runs containing a letter or a
     * digit.
     *
     * Split on WHITESPACE only, and deliberately not on a hyphen.
     * "Jean-Pierre Dupont" is a person with a compound given name, so the
     * initials are `JD`; splitting on the hyphen would make it `JP` and lose
     * the surname entirely.
     *
     * A run with no letter or digit is dropped, so `"John (Jack) Smith"` gives
     * `JS` rather than `J(` — and a name that is nothing but punctuation gives
     * no words at all.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        // Control characters out first. They cannot appear in a rendered
        // initial, and leaving them in would let a CR or an ESC reach a log
        // line through an error message about the name.
        $clean = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $name)
            ?? preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $name)
            ?? $name;

        // `\s` with /u covers the Unicode spaces a paste can carry: a
        // non-breaking space (U+00A0) and an ideographic space (U+3000) are
        // both word separators a person typed as a space.
        $parts = preg_split('/\s+/u', trim($clean)) ?: [];

        $words = [];

        foreach ($parts as $part) {
            if ($part !== '' && preg_match('/[\p{L}\p{N}]/u', $part) === 1) {
                $words[] = $part;
            }
        }

        return $words;
    }

    /**
     * The first letter-or-digit grapheme of a word, upper-cased.
     *
     * Leading punctuation is skipped rather than taken: `"(Jack)"` yields `J`.
     */
    private static function firstLetter(string $word): string
    {
        $length = self::graphemeLength($word);

        for ($i = 0; $i < $length; $i++) {
            $grapheme = self::graphemeAt($word, $i);

            if ($grapheme !== '' && preg_match('/^[\p{L}\p{N}]/u', $grapheme) === 1) {
                return self::upper($grapheme);
            }
        }

        return '';
    }

    /**
     * Upper-case one grapheme, and keep it one grapheme.
     *
     * `mb_strtoupper('ß')` is `'SS'` — two characters from one. Re-taking the
     * first grapheme afterwards keeps the result a single glyph, so a German
     * name beginning with ß gives `S` rather than widening the avatar's text
     * by one letter. Scripts without case (CJK, Arabic, Hebrew) are unchanged,
     * which is also correct.
     */
    private static function upper(string $grapheme): string
    {
        $upper = mb_strtoupper($grapheme, 'UTF-8');

        return self::graphemeLength($upper) > 1 ? self::graphemeAt($upper, 0) : $upper;
    }

    // ── Grapheme access, with a fallback ─────────────────────────────────────

    private static function graphemeLength(string $value): int
    {
        if (\function_exists('grapheme_strlen')) {
            $length = grapheme_strlen($value);

            if (\is_int($length)) {
                return $length;
            }
        }

        return \count(self::splitUtf8($value));
    }

    private static function graphemeAt(string $value, int $index): string
    {
        if (\function_exists('grapheme_substr')) {
            $slice = grapheme_substr($value, $index, 1);

            if (\is_string($slice)) {
                return $slice;
            }
        }

        return self::splitUtf8($value)[$index] ?? '';
    }

    /**
     * Split UTF-8 into base-character-plus-combining-marks clusters.
     *
     * The fallback for a build with neither `ext-intl` nor
     * `symfony/polyfill-intl-grapheme`. It keeps combining marks attached to
     * their base, which is the case that matters for names — a decomposed é
     * stays one cluster.
     *
     * It does NOT understand zero-width joiner sequences, so an emoji family
     * would be split at its first joiner. That is a limitation and not a
     * silent one: it is stated here and in the README, and it does not arise
     * where `grapheme_*` is available, which is every build with `ext-intl`.
     *
     * @return list<string>
     */
    private static function splitUtf8(string $value): array
    {
        // \X in PCRE is "an extended grapheme cluster" and handles combining
        // marks. PCRE's \X does not join ZWJ sequences, which is the stated
        // limitation above.
        $matches = [];

        if (preg_match_all('/\X/u', $value, $matches) === false) {
            // Invalid UTF-8. Byte-wise is wrong but bounded, and returning
            // nothing would turn a mangled name into a blank avatar.
            return $value === '' ? [] : str_split($value);
        }

        return $matches[0];
    }
}
