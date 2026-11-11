<?php

declare(strict_types=1);

namespace Plugins\Avatars\Domain;

/**
 * What these bytes actually are, read from the bytes.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE REMOTE `Content-Type` IS WORTH NOTHING
 * ════════════════════════════════════════════════════════════════════════════
 *
 * When this plugin fetches an avatar from a URL a user supplied, the server at
 * the other end chose the `Content-Type` header. Trusting it and re-serving
 * the bytes with it means:
 *
 *   - a server declares `image/png` and sends HTML with a `<script>` in it;
 *   - the bytes are re-served from YOUR origin as `image/png`;
 *   - a browser without `nosniff` sniffs the real type, decides it is HTML,
 *     and runs the script with your origin's privileges.
 *
 * So the type is determined here, from the first bytes, and the declared one
 * is discarded. `nosniff` on the way back out is the second layer; this is the
 * first, and it is the one that does not depend on the browser.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * SVG IS REFUSED, DELIBERATELY
 * ════════════════════════════════════════════════════════════════════════════
 *
 * An SVG is a scriptable document. Fetching a remote SVG and re-serving it
 * from your origin is handing an attacker stored XSS with a `<script>` tag and
 * no exploit required — they only need to host a file and paste its URL into
 * an avatar field.
 *
 * It is also not detectable by magic bytes: SVG is XML, so it can begin with
 * an XML declaration, a comment, a DOCTYPE, a BOM, or whitespace. Any
 * allowlist based on a prefix would be bypassable.
 *
 * So remote SVG is not supported and will not be. This plugin GENERATES SVG
 * for initials — content it wrote itself — and ACCEPTS only raster formats
 * from third parties. Those two facts are not in tension: one is a document
 * whose entire text this code chose, the other is a file a stranger uploaded.
 */
final class ImageSignature
{
    /**
     * Raster formats only, in the order the signatures are tested.
     *
     * Each entry is [offset, magic bytes, content type]. The offsets matter:
     * WebP and the JPEG-2000 family are container formats whose marker is not
     * at byte zero.
     *
     * @var list<array{int, string, string}>
     */
    private const SIGNATURES = [
        // PNG: the 8-byte signature, which includes \r\n and \x1a so that a
        // file mangled by a text-mode transfer fails to match.
        [0, "\x89PNG\r\n\x1a\n", 'image/png'],

        // JPEG: SOI marker. The third byte varies by encoder (\xE0 JFIF,
        // \xE1 EXIF, \xDB, \xEE …), so only the two-byte SOI is matched.
        [0, "\xFF\xD8\xFF", 'image/jpeg'],

        [0, 'GIF87a', 'image/gif'],
        [0, 'GIF89a', 'image/gif'],

        // BMP, which a Windows upload still produces.
        [0, 'BM', 'image/bmp'],

        // ICO/CUR: a 4-byte header whose third/fourth bytes are the type.
        [0, "\x00\x00\x01\x00", 'image/x-icon'],
    ];

    /** RIFF containers need two checks: the magic at 0 and the form type at 8. */
    private const RIFF_FORMS = ['WEBP' => 'image/webp'];

    /**
     * The content type of these bytes, or null when they are not a supported
     * raster image.
     *
     * Null means REFUSE. It does not mean "fall back to the declared type":
     * that fallback is the whole vulnerability.
     */
    public static function of(string $bytes): ?string
    {
        foreach (self::SIGNATURES as [$offset, $magic, $type]) {
            if (substr($bytes, $offset, \strlen($magic)) === $magic) {
                return $type;
            }
        }

        // RIFF: `RIFF` at 0, four length bytes, then the form type at 8. A
        // bare `RIFF` prefix is not enough — a WAV file has it too.
        if (substr($bytes, 0, 4) === 'RIFF') {
            $form = substr($bytes, 8, 4);

            if (isset(self::RIFF_FORMS[$form])) {
                return self::RIFF_FORMS[$form];
            }
        }

        return null;
    }

    /**
     * How many bytes {@see of()} needs to decide.
     *
     * Twelve, for the RIFF form type at offset 8. Stated so a caller can
     * refuse an absurdly short body before doing anything else — a one-byte
     * response is not an image and should not reach the signature check as if
     * it might be.
     */
    public const PROBE_BYTES = 12;

    /** @return list<string> the types this plugin will accept from a third party */
    public static function acceptedTypes(): array
    {
        $types = array_column(self::SIGNATURES, 2);

        return array_values(array_unique([...$types, ...array_values(self::RIFF_FORMS)]));
    }
}
