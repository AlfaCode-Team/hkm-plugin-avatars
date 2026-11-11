<?php

declare(strict_types=1);

namespace Plugins\Avatars\Domain;

/**
 * Bytes that came back from somebody else's server, and were confirmed to be
 * a raster image.
 *
 * The `contentType` is the one this plugin DETERMINED from the bytes, never
 * the one the remote server declared — see
 * {@see \Plugins\Avatars\Domain\ImageSignature} for why a declared type is
 * worth nothing here.
 */
final readonly class FetchedImage
{
    public function __construct(
        /** The raw bytes, exactly as received. */
        public string $bytes,
        /** Determined from the magic bytes, not from the remote `Content-Type`. */
        public string $contentType,
        /** The URL that was actually fetched, after validation. */
        public string $url,
    ) {}

    public function byteLength(): int
    {
        return \strlen($this->bytes);
    }

    /**
     * Headers for re-serving this image.
     *
     * `nosniff` and a locked-down CSP for the same reason as an initials
     * avatar: these bytes came from a third party, and a browser that decided
     * they were HTML would run them on your origin. The determined type is
     * used, so a server that declared `image/png` and sent something else
     * cannot choose the header.
     *
     * NOT `immutable`: the remote image can change, and a year-long immutable
     * cache on somebody else's content would outlive the reason it was cached.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type'            => $this->contentType,
            'Content-Length'          => (string) $this->byteLength(),
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control'           => 'public, max-age=86400',
        ];
    }

    /** @return array<string, string|int> safe to log — no bytes */
    public function describe(): array
    {
        return [
            'url'          => $this->url,
            'content_type' => $this->contentType,
            'bytes'        => $this->byteLength(),
        ];
    }
}
