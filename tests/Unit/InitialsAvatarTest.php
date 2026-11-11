<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Avatars\Domain\AvatarPalette;
use Plugins\Avatars\Domain\Initials;
use Plugins\Avatars\Domain\InitialsAvatar;

/**
 * The SVG document.
 *
 * The document is served with `Content-Type: image/svg+xml`, and an SVG from
 * your own origin is a SCRIPTABLE document — so a name reaching it unescaped
 * is stored XSS. Most of this file is about that.
 */
#[CoversClass(InitialsAvatar::class)]
final class InitialsAvatarTest extends TestCase
{
    private function avatar(
        string $initials = 'JS',
        int $size = 128,
        string $shape = InitialsAvatar::SHAPE_CIRCLE,
    ): InitialsAvatar {
        return new InitialsAvatar(
            initials:   $initials,
            background: '#5b6b7c',
            ink:        AvatarPalette::LIGHT_INK,
            size:       $size,
            shape:      $shape,
        );
    }

    /** Parse the document, and fail with the parser's own complaint. */
    private function parse(string $svg): \DOMDocument
    {
        $document = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $loaded = $document->loadXML($svg);
        $errors = array_map(static fn ($e): string => trim($e->message), libxml_get_errors());

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($loaded, 'the SVG must be well-formed XML: ' . implode('; ', $errors));
        self::assertSame([], $errors, implode('; ', $errors));

        return $document;
    }

    // ── Well-formedness ─────────────────────────────────────────────────────

    public function test_the_document_is_well_formed_xml(): void
    {
        // Parsed rather than string-matched. A `<text>` attribute built by
        // concatenation is exactly the kind of thing that produces output that
        // looks right in a diff and is rejected by a renderer.
        $document = $this->parse($this->avatar()->toSvg());

        self::assertSame('svg', $document->documentElement?->nodeName);
        self::assertSame(
            'http://www.w3.org/2000/svg',
            $document->documentElement?->namespaceURI,
            'without the namespace a browser renders nothing at all',
        );
    }

    public function test_the_size_reaches_width_height_and_the_viewbox(): void
    {
        $svg = $this->parse($this->avatar(size: 64)->toSvg())->documentElement;

        self::assertSame('64', $svg?->getAttribute('width'));
        self::assertSame('64', $svg?->getAttribute('height'));
        // The viewBox is what makes the document scale when a consumer
        // overrides width/height — without it, an <img width="32"> would crop.
        self::assertSame('0 0 64 64', $svg?->getAttribute('viewBox'));
    }

    public function test_the_initials_are_the_text_content(): void
    {
        $document = $this->parse($this->avatar('MW')->toSvg());
        $texts    = $document->getElementsByTagName('text');

        self::assertSame(1, $texts->length);
        self::assertSame('MW', $texts->item(0)?->textContent);
    }

    public function test_the_text_is_centred_in_a_way_librsvg_also_honours(): void
    {
        // `dy=".35em"` rather than `dominant-baseline="central"`. Both centre
        // text in a browser; `dominant-baseline` is unsupported or mishandled
        // by librsvg, which is what ImageMagick and most server-side
        // SVG-to-PNG converters use — so an avatar centred in Chrome would sit
        // high when rasterised for an email.
        $text = $this->parse($this->avatar()->toSvg())->getElementsByTagName('text')->item(0);

        self::assertInstanceOf(\DOMElement::class, $text);
        self::assertSame('64', $text->getAttribute('x'));
        self::assertSame('64', $text->getAttribute('y'));
        self::assertSame('.35em', $text->getAttribute('dy'));
        self::assertSame('middle', $text->getAttribute('text-anchor'));
        self::assertSame('', $text->getAttribute('dominant-baseline'), 'deliberately absent');
    }

    public function test_an_empty_initials_renders_a_tile_with_no_text(): void
    {
        // A name with no letters gives a plain coloured tile. Not a `'?'`:
        // that is a character the user never typed appearing where their name
        // should be.
        $document = $this->parse($this->avatar('')->toSvg());

        self::assertSame(0, $document->getElementsByTagName('text')->length);
        self::assertSame(1, $document->getElementsByTagName('circle')->length, 'the tile is still there');
    }

    public function test_the_avatar_is_labelled_for_a_screen_reader(): void
    {
        // An avatar IS content — a screen reader would otherwise announce
        // nothing where a person's name is.
        $svg = $this->parse($this->avatar('JS')->toSvg())->documentElement;

        self::assertSame('img', $svg?->getAttribute('role'));
        self::assertSame('JS', $svg?->getAttribute('aria-label'));
    }

    // ── Shapes ───────────────────────────────────────────────────────────────

    /** @return list<array{string, string}> */
    public static function shapes(): array
    {
        return [
            [InitialsAvatar::SHAPE_CIRCLE, 'circle'],
            [InitialsAvatar::SHAPE_SQUARE, 'rect'],
            [InitialsAvatar::SHAPE_ROUNDED, 'rect'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_each_shape_renders_its_own_backdrop(string $shape, string $element): void
    {
        $document = $this->parse($this->avatar(shape: $shape)->toSvg());

        self::assertSame(1, $document->getElementsByTagName($element)->length, $shape);
    }

    public function test_a_rounded_square_has_a_radius_and_a_plain_one_does_not(): void
    {
        $rounded = $this->parse($this->avatar(shape: InitialsAvatar::SHAPE_ROUNDED)->toSvg())
            ->getElementsByTagName('rect')->item(0);
        $square = $this->parse($this->avatar(shape: InitialsAvatar::SHAPE_SQUARE)->toSvg())
            ->getElementsByTagName('rect')->item(0);

        self::assertInstanceOf(\DOMElement::class, $rounded);
        self::assertInstanceOf(\DOMElement::class, $square);
        // An eighth of the box — the radius every platform's app icon uses,
        // and therefore the one that looks deliberate.
        self::assertSame('16', $rounded->getAttribute('rx'));
        self::assertSame('', $square->getAttribute('rx'));
    }

    public function test_an_unknown_shape_falls_back_to_a_square(): void
    {
        // The class takes any string; the clamping to a known shape is the
        // config's job. Rendering nothing would be worse than rendering a
        // square.
        $document = $this->parse($this->avatar(shape: 'hexagon')->toSvg());

        self::assertSame(1, $document->getElementsByTagName('rect')->length);
    }

    // ── Escaping: the XSS surface ───────────────────────────────────────────

    /** @return list<array{string}> */
    public static function hostileNames(): array
    {
        return [
            ['<script>alert(document.domain)</script>'],
            ['"><script>alert(1)</script>'],
            ["'><script>alert(1)</script>"],
            ['</text><script>alert(1)</script><text>'],
            ['</svg><script>alert(1)</script>'],
            ['<img src=x onerror=alert(1)>'],
            ['& < > " \''],
            [']]><!--'],
            ['&#x3C;script&#x3E;'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_a_hostile_name_cannot_inject_anything(string $name): void
    {
        // Driven END TO END, from the raw name through Initials and into the
        // document — because that composition is what is actually deployed,
        // and either half changing could break it.
        $svg = new InitialsAvatar(
            initials:   Initials::of($name),
            background: AvatarPalette::backgroundFor($name),
            ink:        AvatarPalette::inkFor(AvatarPalette::backgroundFor($name)),
            size:       128,
            shape:      InitialsAvatar::SHAPE_CIRCLE,
        )->toSvg();

        // It still parses — an injection that broke the XML would be caught
        // here even if the next assertion missed it.
        $document = $this->parse($svg);

        self::assertSame(0, $document->getElementsByTagName('script')->length, 'no script element');
        self::assertSame(0, $document->getElementsByTagName('img')->length, 'no img element');
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringNotContainsString('onerror', $svg);
        self::assertStringNotContainsString('onload', $svg);

        // Exactly one text element at most, and one svg root: an escape that
        // closed `</text>` early would produce more.
        self::assertLessThanOrEqual(1, $document->getElementsByTagName('text')->length);
        self::assertSame(1, substr_count($svg, '<svg '));
    }

    /** @return list<array{string}> */
    public static function hostileInitials(): array
    {
        // Values that `Initials::of()` would never produce. That is the point:
        // these test the ESCAPING LAYER ON ITS OWN.
        return [
            ['</text><script>alert(1)</script><text>'],
            ['<script>alert(1)</script>'],
            ['"><circle onload="alert(1)'],
            ['A&B'],
            [']]><!--'],
            ['</svg>'],
        ];
    }

    #[DataProvider('hostileInitials')]
    public function test_the_escaping_holds_on_its_own(string $initials): void
    {
        // A REVERT CHECK IS WHY THIS EXISTS. Removing the `htmlspecialchars`
        // call passed the whole suite, because every hostile-NAME test goes
        // through `Initials::of()` first and arrives with nothing left to
        // escape. The escaping was therefore defence in depth that no test
        // exercised — which is indistinguishable from dead code.
        //
        // Constructing the avatar directly, with text `Initials` would never
        // produce, is exactly what a future change to the stripping would
        // amount to. Now both layers are held independently.
        $svg = new InitialsAvatar(
            initials:   $initials,
            background: '#5b6b7c',
            ink:        '#ffffff',
            size:       64,
            shape:      InitialsAvatar::SHAPE_CIRCLE,
        )->toSvg();

        $document = $this->parse($svg);

        self::assertSame(0, $document->getElementsByTagName('script')->length);
        self::assertSame(1, $document->getElementsByTagName('text')->length);
        self::assertSame(1, $document->getElementsByTagName('circle')->length);
        // The hostile string survives as TEXT, which is the correct outcome:
        // escaped, not deleted.
        self::assertSame($initials, $document->getElementsByTagName('text')->item(0)?->textContent);
    }

    public function test_an_injected_background_cannot_add_an_attribute(): void
    {
        // The class can be constructed with any string, and an ATTRIBUTE is as
        // injectable as an element body — `fill="…"` closed early would let a
        // new attribute in.
        //
        // Asserted on the DOM and not on the raw text, deliberately: the
        // escaped value legitimately CONTAINS the characters `onload=` inside
        // `fill="#fff&quot; onload=&quot;alert(1)"`, so a substring check
        // would fail on safe output. What matters is that the parser sees one
        // attribute with a silly value, not two attributes.
        $svg = new InitialsAvatar(
            initials:   'JS',
            background: '#fff" onload="alert(1)',
            ink:        '#000',
            size:       64,
            shape:      InitialsAvatar::SHAPE_SQUARE,
        )->toSvg();

        $rect = $this->parse($svg)->getElementsByTagName('rect')->item(0);

        self::assertInstanceOf(\DOMElement::class, $rect);
        self::assertFalse($rect->hasAttribute('onload'), 'no attribute was injected');
        self::assertSame(
            '#fff" onload="alert(1)',
            $rect->getAttribute('fill'),
            'the whole hostile string is one attribute VALUE',
        );
        // rect carries exactly width, height and fill — nothing extra.
        self::assertSame(3, $rect->attributes?->length);
    }

    public function test_an_apostrophe_survives_as_a_valid_xml_entity(): void
    {
        // `&apos;` is one of the five entities XML 1.0 predefines, so it is
        // valid and round-trips — which an earlier draft of this test got
        // wrong by asserting a numeric reference instead. The property that
        // actually matters is that the document parses and the character comes
        // back intact.
        $svg = new InitialsAvatar("O'", '#000000', '#ffffff', 64, 'square')->toSvg();

        $document = $this->parse($svg);
        $text     = $document->getElementsByTagName('text')->item(0);

        self::assertSame("O'", $text?->textContent);
        self::assertSame("O'", $document->documentElement?->getAttribute('aria-label'));
        self::assertStringNotContainsString("'>", $svg, 'the raw character does not reach the markup');
    }

    public function test_no_html_only_entity_reaches_the_document(): void
    {
        // What ENT_XML1 actually buys: the output is confined to the five
        // XML-predefined entities, so nothing HTML-only such as `&nbsp;` can
        // appear in a document a strict XML parser reads.
        $svg = new InitialsAvatar("A\u{00A0}B", '#000000', '#ffffff', 64, 'square')->toSvg();

        $this->parse($svg);

        self::assertStringNotContainsString('&nbsp;', $svg);
    }

    public function test_the_font_family_attribute_is_single_quoted_so_its_commas_and_quotes_survive(): void
    {
        // The stack contains `"Segoe UI"` with double quotes, so the attribute
        // has to be single-quoted — otherwise the attribute ends at the first
        // inner quote and the rest becomes stray markup.
        $svg = $this->avatar()->toSvg();

        $this->parse($svg);
        self::assertStringContainsString("font-family='", $svg);

        $text = $this->parse($svg)->getElementsByTagName('text')->item(0);
        self::assertInstanceOf(\DOMElement::class, $text);
        self::assertStringContainsString('Segoe UI', $text->getAttribute('font-family'));
    }

    // ── Data URI and headers ────────────────────────────────────────────────

    public function test_the_data_uri_round_trips(): void
    {
        $avatar = $this->avatar('AB');
        $uri    = $avatar->toDataUri();

        self::assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        self::assertSame(
            $avatar->toSvg(),
            base64_decode(substr($uri, \strlen('data:image/svg+xml;base64,')), true),
        );
    }

    public function test_the_data_uri_is_base64_and_not_percent_encoded(): void
    {
        // Base64 is a third larger and cannot be got wrong. A raw SVG in a URI
        // has to escape `#`, `<`, `>` and quotes, and missing one produces a
        // URI that works in one browser and not another — and `#` is in every
        // colour this plugin emits.
        $uri = $this->avatar()->toDataUri();

        self::assertStringNotContainsString('<', $uri);
        self::assertStringNotContainsString('#', $uri);
        self::assertMatchesRegularExpression('#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#D', $uri);
    }

    public function test_the_headers_lock_the_document_down(): void
    {
        // Not advice. An SVG from your own origin is a scriptable document, so
        // a missing CSP or a sniffable type turns a cosmetic feature into a
        // stored-XSS surface.
        $headers = $this->avatar()->headers();

        self::assertSame('image/svg+xml', $headers['Content-Type']);
        self::assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        // Immutable is safe here and only here: the document is a pure
        // function of the name and the size, so it can never change for a URL.
        self::assertStringContainsString('immutable', $headers['Cache-Control']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────

    public function test_the_document_is_byte_identical_across_renders(): void
    {
        // What makes `immutable` honest, and what makes the avatar cacheable
        // at a CDN. Anything that reached for a random id or a timestamp would
        // break it.
        $avatar = $this->avatar('ZZ');

        self::assertSame($avatar->toSvg(), $avatar->toSvg());
        self::assertSame($this->avatar('ZZ')->toSvg(), $this->avatar('ZZ')->toSvg());
    }

    public function test_the_document_stays_small(): void
    {
        // A few hundred bytes is the whole argument for SVG over a rasteriser.
        // If it grew past a kilobyte, something has been embedded that should
        // not have been.
        self::assertLessThan(1024, \strlen($this->avatar()->toSvg()));
    }

    public function test_the_font_size_scales_with_the_box(): void
    {
        foreach ([32, 128, 512] as $size) {
            $text = $this->parse($this->avatar(size: $size)->toSvg())
                ->getElementsByTagName('text')->item(0);

            self::assertInstanceOf(\DOMElement::class, $text);
            self::assertSame((string) round($size * 0.4, 2), $text->getAttribute('font-size'));
        }
    }
}
