<?php

declare(strict_types=1);

namespace Plugins\Avatars\API\Contracts;

use Plugins\Avatars\Domain\FetchedImage;
use Plugins\Avatars\Domain\InitialsAvatar;

/**
 * An avatar for a person who has not uploaded one, and a guarded way to fetch
 * one they have.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TWO THINGS, AND ONLY TWO
 * ────────────────────────────────────────────────────────────────────────────
 *
 * {@see initials()} is a pure function of a name: no network, no disk, no
 * image library, no font file. It returns an SVG of a few hundred bytes with a
 * deterministic colour, so the same person is the same colour forever.
 *
 * {@see remoteImage()} fetches a URL somebody supplied, through an SSRF guard,
 * a size ceiling and a magic-byte check. It is a service method and NOT a
 * route, because Appwrite's equivalent — `GET /avatars/image?url=…` — is an
 * open proxy: anyone can use the server to reach what it can reach and they
 * cannot, and read the answer back. Only the application can decide who may
 * ask.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY ABSENT
 * ────────────────────────────────────────────────────────────────────────────
 *
 * NO ICON SETS. Appwrite serves browser logos, credit-card logos and ~250
 * country flags. Those are several hundred binary assets with a licence each,
 * carried by every install, and a project can use any icon set it likes —
 * including the flag emoji, which needs no files at all.
 *
 * NO QR CODES. A correct QR encoder is Reed-Solomon arithmetic over GF(256)
 * and several hundred lines that cannot be verified without known-answer
 * vectors. A thin wrapper around a library this plugin does not depend on
 * would be untested glue, which is worse than not having it:
 * `bacon/bacon-qr-code` does the job and belongs where the project requires it.
 *
 * NO FAVICON FETCH. Appwrite's version has the same open-proxy problem as the
 * image endpoint, and the reliable implementation needs the site's HTML parsed
 * for `<link rel="icon">` — a second fetch, an HTML parser, and a much wider
 * surface. `/favicon.ico` alone fails for most modern sites, so shipping it
 * would be a feature that mostly does not work.
 *
 * NO PNG. Rasterising needs GD or Imagick AND a font file to embed, with the
 * licence that implies. An SVG renders crisply at any size and is a tenth of
 * the bytes. The one place that costs something is an EMAIL — many clients do
 * not render SVG — and that is stated in the README rather than solved badly.
 */
interface AvatarContract
{
    /**
     * An initials avatar for a name.
     *
     * Deterministic: the same name always gives the same colour, on every
     * server and after every deploy. Case and surrounding whitespace are
     * normalised first, so a profile form trimming a space cannot change
     * somebody's colour.
     *
     * A name with no letters or digits in it — `''`, `'   '`, `'...'` —
     * produces a plain coloured tile with no text. Not a `'?'`: that is a
     * character the user never typed, appearing where their name should be.
     *
     * @param int    $size  pixels, clamped to the configured range
     * @param string $shape one of {@see InitialsAvatar}'s three shapes;
     *                      anything else falls back to the configured default
     */
    public function initials(string $name, ?int $size = null, ?string $shape = null): InitialsAvatar;

    /**
     * Fetch an avatar from a URL somebody supplied.
     *
     * Refuses anything but http/https, refuses userinfo credentials, refuses
     * private and reserved destinations at CONNECT time, does not follow
     * redirects, bounds the response, and determines the content type from the
     * bytes rather than from the remote header. Remote SVG is refused outright
     * — re-serving a stranger's scriptable document from your own origin is
     * stored XSS with no exploit required.
     *
     * @throws \Plugins\Avatars\Infrastructure\Exceptions\AvatarException
     *         on a refused URL, a failed fetch, or bytes that are not a
     *         supported raster image — and, when `http.client` is not in the
     *         request's dependency graph, with a message naming the `requires`
     *         line that fixes it
     */
    public function remoteImage(string $url): FetchedImage;

    /** Whether {@see remoteImage()} can be used at all in this request. */
    public function canFetch(): bool;

    /** A status line. Never a credential. */
    public function describe(): string;
}
