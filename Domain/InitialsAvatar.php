<?php

declare(strict_types=1);

namespace Plugins\Avatars\Domain;

/**
 * An initials avatar, as SVG.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * SVG, NOT PNG — AND THAT CHOICE HAS A SECURITY CONSEQUENCE
 * ════════════════════════════════════════════════════════════════════════════
 *
 * SVG needs no GD, no Imagick, no font file and no licence for one. It is a
 * few hundred bytes, scales to any size, and renders crisply on every display.
 *
 * The consequence is that **SVG is a scriptable document**. An SVG served from
 * your own origin can carry `<script>` and run with your origin's privileges,
 * which makes a user-controlled SVG stored XSS. The initials here come from a
 * person's NAME, so that is exactly the shape of the risk.
 *
 * Two defences, and they are independent on purpose:
 *
 *   1. {@see Initials} strips everything that is not a letter or a digit, so by
 *      construction the text cannot contain `<`, `>`, `&` or a quote.
 *   2. The text is escaped on the way into the document anyway.
 *
 * Either alone would do today. Both together mean a future change to the
 * stripping cannot silently become an injection, and there is a test that
 * drives a hostile name all the way through and asserts the output.
 *
 * It also means the SERVING matters, which this class cannot control and the
 * README states: `Content-Type: image/svg+xml`, plus
 * `Content-Security-Policy: default-src 'none'` and
 * `X-Content-Type-Options: nosniff`. Or use {@see toDataUri()} inside an
 * `<img src>`, where a browser will not run script at all.
 */
final readonly class InitialsAvatar
{
    public const SHAPE_SQUARE = 'square';

    public const SHAPE_CIRCLE = 'circle';

    public const SHAPE_ROUNDED = 'rounded';

    /**
     * A system font stack: no web font, no embedded font, no network request.
     *
     * The consequence is honest and worth knowing: the GLYPH depends on the
     * viewer's installed fonts. Latin initials are safe everywhere; a CJK or
     * Arabic initial renders only if the viewer has a font covering it, and
     * falls back to a replacement box if not. Embedding a font that covered
     * every script would add megabytes to every avatar.
     */
    public const FONT_STACK = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, '
        . '"Helvetica Neue", Arial, "Noto Sans", sans-serif';

    public function __construct(
        /** Already stripped and upper-cased by {@see Initials}. May be ''. */
        public string $initials,
        /** `#rrggbb`. */
        public string $background,
        /** `#rrggbb`, chosen for contrast by {@see AvatarPalette::inkFor()}. */
        public string $ink,
        public int $size,
        public string $shape,
    ) {}

    /**
     * The SVG document.
     *
     * ──────────────────────────────────────────────────────────────────────
     * `dy=".35em"` RATHER THAN `dominant-baseline="central"`
     * ──────────────────────────────────────────────────────────────────────
     *
     * Both centre text vertically in a browser. `dominant-baseline` is
     * unsupported or mishandled by librsvg, which is what ImageMagick,
     * Inkscape's CLI and most server-side SVG-to-PNG converters use — so an
     * avatar that looked centred in Chrome would sit high when rasterised for
     * an email. The `dy` offset works in both.
     *
     * `viewBox` plus `width`/`height` so the document scales if a consumer
     * overrides the attributes, and `shape-rendering="geometricPrecision"` so
     * the circle edge does not alias at small sizes.
     */
    public function toSvg(): string
    {
        $size = $this->size;
        $half = $size / 2;

        // Escaped even though Initials guarantees it is safe.
        //
        // ENT_XML1 confines the output to the five entities XML 1.0 predefines
        // — `&lt; &gt; &amp; &quot; &apos;` — so no HTML-only named entity
        // such as `&nbsp;` can appear in a document a strict XML parser will
        // read. (`&apos;` IS one of the five and is valid here; it was checked,
        // after a comment in an earlier draft claimed otherwise.)
        //
        // ENT_SUBSTITUTE so invalid UTF-8 becomes U+FFFD rather than silently
        // emptying the whole string — the default would turn a mangled name
        // into a blank avatar with no indication why.
        $text = htmlspecialchars(
            $this->initials,
            \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE,
            'UTF-8',
        );

        // The palette's colours come from a constant, but this class can be
        // constructed with any string, so they are escaped too — an attribute
        // is as injectable as an element body.
        $background = htmlspecialchars($this->background, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        $ink        = htmlspecialchars($this->ink, \ENT_QUOTES | \ENT_XML1, 'UTF-8');

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '"'
            . ' viewBox="0 0 ' . $size . ' ' . $size . '"'
            // A role and a label, because an avatar IS content: a screen
            // reader otherwise announces nothing where a person's name is.
            . ' role="img" aria-label="' . $text . '"'
            . ' shape-rendering="geometricPrecision">'
            . $this->backdrop($size, $background);

        if ($this->initials !== '') {
            $svg .= '<text x="' . $half . '" y="' . $half . '" dy=".35em"'
                . ' text-anchor="middle"'
                . ' font-family=\'' . self::FONT_STACK . '\''
                // 0.4 of the box: two initials fit inside a circle at this
                // ratio without touching the edge, and one looks proportionate
                // rather than enormous.
                . ' font-size="' . round($size * 0.4, 2) . '"'
                . ' font-weight="600"'
                . ' fill="' . $ink . '">' . $text . '</text>';
        }

        return $svg . '</svg>';
    }

    /**
     * A `data:` URI for an `<img src>`.
     *
     * Base64 rather than percent-encoded: a raw SVG in a URI has to escape
     * `#`, `<`, `>` and quotes, and getting one of them wrong produces a URI
     * that works in one browser and not another. Base64 is a third larger and
     * cannot be got wrong.
     *
     * This is the SAFEST way to use an initials avatar: a browser will not
     * execute script in an SVG loaded through `<img>`, whatever the document
     * contains, so the question of whether the text was escaped stops being a
     * security question at all.
     */
    public function toDataUri(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->toSvg());
    }

    public function contentType(): string
    {
        return 'image/svg+xml';
    }

    /**
     * Headers this document must be served with when it is served as a FILE.
     *
     * Not advice — the reason the list exists is that an SVG from your own
     * origin is a scriptable document, so a wrong `Content-Type` or a missing
     * CSP turns a cosmetic feature into a stored-XSS surface. `nosniff` stops
     * a browser from deciding the bytes are HTML.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type'                 => $this->contentType(),
            'Content-Security-Policy'      => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options'       => 'nosniff',
            // Immutable: the document is a pure function of the name and the
            // size, so it can never change for a given URL.
            'Cache-Control'                => 'public, max-age=31536000, immutable',
        ];
    }

    private function backdrop(int $size, string $background): string
    {
        return match ($this->shape) {
            self::SHAPE_CIRCLE => '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '"'
                . ' r="' . ($size / 2) . '" fill="' . $background . '"/>',
            self::SHAPE_ROUNDED => '<rect width="' . $size . '" height="' . $size . '"'
                // An eighth of the box, which is the radius every platform's
                // app icon uses and therefore the one that looks deliberate.
                . ' rx="' . round($size / 8, 2) . '" fill="' . $background . '"/>',
            default => '<rect width="' . $size . '" height="' . $size . '"'
                . ' fill="' . $background . '"/>',
        };
    }
}
