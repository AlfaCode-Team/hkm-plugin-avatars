<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Avatars\Domain\ImageSignature;
use Tests\Plugins\Avatars\Support\RecordingHttpClient as Bytes;

#[CoversClass(ImageSignature::class)]
final class ImageSignatureTest extends TestCase
{
    /** @return list<array{string, string, string}> */
    public static function recognised(): array
    {
        return [
            [Bytes::pngBytes(),  'image/png',  'the 8-byte PNG signature'],
            [Bytes::jpegBytes(), 'image/jpeg', 'the JPEG SOI marker'],
            [Bytes::gifBytes(),  'image/gif',  'GIF89a'],
            ['GIF87a' . str_repeat("\x00", 20), 'image/gif', 'and the older GIF87a'],
            [Bytes::webpBytes(), 'image/webp', 'RIFF with a WEBP form type at offset 8'],
            ['BM' . str_repeat("\x00", 20), 'image/bmp', 'BMP, which a Windows upload still makes'],
            ["\x00\x00\x01\x00" . str_repeat("\x00", 20), 'image/x-icon', 'ICO'],
        ];
    }

    #[DataProvider('recognised')]
    public function test_a_raster_format_is_recognised(string $bytes, string $expected, string $why): void
    {
        self::assertSame($expected, ImageSignature::of($bytes), $why);
    }

    // ── The refusals that matter ────────────────────────────────────────────

    public function test_svg_is_not_recognised(): void
    {
        // REFUSED, and it will stay refused. An SVG is a scriptable document,
        // so fetching a remote one and re-serving it from your origin is
        // stored XSS with no exploit required — an attacker only needs to host
        // a file and paste its URL into an avatar field.
        self::assertNull(ImageSignature::of(Bytes::svgBytes()));
        self::assertNotContains('image/svg+xml', ImageSignature::acceptedTypes());
    }

    /** @return list<array{string, string}> */
    public static function svgPreambles(): array
    {
        // Why SVG cannot be detected by a prefix even if we wanted to accept
        // it: XML can legally begin with any of these.
        return [
            ['<?xml version="1.0"?><svg/>',          'an XML declaration'],
            ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'the element directly'],
            ['<!-- a comment --><svg/>',             'a comment'],
            ['<!DOCTYPE svg><svg/>',                 'a DOCTYPE'],
            ["\xEF\xBB\xBF<svg/>",                   'a UTF-8 BOM'],
            ["\n\n   <svg/>",                        'leading whitespace'],
            ["<?xml-stylesheet href='x'?><svg/>",    'a processing instruction'],
        ];
    }

    #[DataProvider('svgPreambles')]
    public function test_no_svg_preamble_is_ever_recognised(string $bytes, string $why): void
    {
        self::assertNull(ImageSignature::of($bytes), $why);
    }

    public function test_html_declaring_itself_an_image_is_not_recognised(): void
    {
        // THE attack this class exists for: a host declares `image/png` and
        // sends HTML with a script in it. If the declared Content-Type were
        // trusted, those bytes would be re-served from our origin as an image,
        // and a browser without `nosniff` would sniff the real type and run it.
        self::assertNull(ImageSignature::of(Bytes::htmlBytes()));
    }

    public function test_a_riff_container_that_is_not_a_webp_is_not_recognised(): void
    {
        // A WAV file also begins with `RIFF`, which is why a bare four-byte
        // prefix check is not enough and the form type at offset 8 is read.
        self::assertNull(ImageSignature::of(Bytes::wavBytes()));
    }

    /** @return list<array{string, string}> */
    public static function nonImages(): array
    {
        return [
            ['',                                  'empty'],
            ['x',                                 'one byte'],
            ['not an image at all',               'plain text'],
            ["PK\x03\x04" . str_repeat("\x00", 20), 'a ZIP, which an SVGZ would be'],
            ["%PDF-1.7\n",                         'a PDF'],
            ["\x7fELF" . str_repeat("\x00", 20),  'an ELF binary'],
            ["#!/bin/sh\necho hi",                'a shell script'],
            ["\x89PNG",                           'a truncated PNG signature'],
            ["\x89PNG\n\r\x1a\n" . str_repeat("\x00", 20), 'a PNG signature mangled by a text-mode transfer'],
        ];
    }

    #[DataProvider('nonImages')]
    public function test_something_that_is_not_an_image_is_refused(string $bytes, string $why): void
    {
        // Null means REFUSE. It explicitly does not mean "fall back to the
        // declared type" — that fallback is the whole vulnerability.
        self::assertNull(ImageSignature::of($bytes), $why);
    }

    public function test_the_png_signature_catches_a_text_mode_transfer(): void
    {
        // The PNG signature includes \r\n and \x1a precisely so a file mangled
        // by an FTP text-mode transfer or a CRLF-rewriting proxy fails to
        // match rather than being served as a broken image.
        self::assertNotNull(ImageSignature::of("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 20)));
        self::assertNull(ImageSignature::of("\x89PNG\n\x1a\n" . str_repeat("\x00", 20)));
    }

    public function test_the_probe_length_covers_the_deepest_signature(): void
    {
        // RIFF's form type sits at offset 8, so twelve bytes are needed before
        // a decision is possible. Stated as a constant so a caller can refuse
        // an absurdly short body before the signature check rather than
        // passing it in as if it might be an image.
        self::assertSame(12, ImageSignature::PROBE_BYTES);
        self::assertSame('image/webp', ImageSignature::of(substr(Bytes::webpBytes(), 0, 12)));
    }

    public function test_the_accepted_types_are_raster_only_and_unique(): void
    {
        $types = ImageSignature::acceptedTypes();

        self::assertSame($types, array_values(array_unique($types)));
        self::assertNotSame([], $types);

        foreach ($types as $type) {
            self::assertStringStartsWith('image/', $type);
            self::assertStringNotContainsString('svg', $type);
            self::assertStringNotContainsString('xml', $type);
        }
    }
}
