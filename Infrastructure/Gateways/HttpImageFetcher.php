<?php

declare(strict_types=1);

namespace Plugins\Avatars\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Avatars\Application\Ports\ImageFetcher;
use Plugins\Avatars\Domain\FetchedImage;
use Plugins\Avatars\Domain\ImageSignature;
use Plugins\Avatars\Infrastructure\Exceptions\AvatarException;

/**
 * Fetches a remote image, through every guard there is.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THIS IS THE ONE DANGEROUS THING IN THIS PLUGIN
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Appwrite exposes this capability as `GET /avatars/image?url=…`: an
 * unauthenticated endpoint that fetches an arbitrary URL and returns the
 * bytes. That is an **open proxy**. Anyone on the internet can use the server
 * to reach things it can reach and they cannot — a metadata service, an
 * internal admin panel, a database's HTTP interface — and read the answer out
 * of the response. It is also a free bandwidth amplifier.
 *
 * So this plugin ships **no route**. The capability is a service method, and
 * the application decides who may call it and caches the result. That is the
 * same reasoning as `resource.tokens` having no routes: the authorization
 * question is one only the application can answer.
 *
 * Within that, every outbound default is overridden:
 *
 *   `block_private => true`   The HTTP client allows private ranges by
 *                             default, which is right for a first-party
 *                             gateway calling an internal service and exactly
 *                             wrong for a URL a user typed. Without it,
 *                             `http://169.254.169.254/latest/meta-data/` reads
 *                             IAM credentials. The verdict is re-evaluated on
 *                             every call rather than cached, because a name
 *                             that resolves publicly once and privately the
 *                             next time IS the rebind attack.
 *
 *   redirects not followed    Already the client's behaviour, and it matters
 *                             more here than anywhere: following a 302 would
 *                             let a remote host bounce the request to an
 *                             internal address AFTER the guard approved the
 *                             public one it was given.
 *
 *   `max_response_bytes`      The reply is an avatar. A hostile host could
 *                             otherwise stream filler until the process ran
 *                             out of memory, from one request.
 *
 *   `retry => 0`              A failed avatar fetch is not worth three
 *                             attempts, and retrying multiplies whatever the
 *                             caller is doing by three.
 *
 * And the type is determined FROM THE BYTES, never from the remote header —
 * see {@see ImageSignature}, which also explains why remote SVG is refused
 * outright.
 */
final class HttpImageFetcher implements ImageFetcher
{
    /** Generous for an avatar, small enough that a flood cannot exhaust memory. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TIMEOUT_SECONDS = 8;

    private const SCHEMES = ['http' => true, 'https' => true];

    public function __construct(
        private readonly HttpClientPort $http,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $timeoutSeconds = self::TIMEOUT_SECONDS,
        /**
         * Off by default, and it should stay off outside a test. Turning it on
         * re-opens the SSRF surface this class exists to close.
         */
        private readonly bool $allowPrivate = false,
    ) {}

    public function fetch(string $url): FetchedImage
    {
        $url = $this->validate($url);

        try {
            $response = $this->http->request('GET', $url, [
                'timeout'         => $this->timeoutSeconds,
                'connect_timeout' => min($this->timeoutSeconds, 5),
                // THIS plugin owns no retry policy; one attempt.
                'retry'           => 0,
                // The guard that makes fetching a user-supplied URL safe at
                // all. See the class docblock.
                'block_private'   => !$this->allowPrivate,
                'max_response_bytes' => $this->maxBytes,
                'headers'         => [
                    // Raster only, and said out loud — a well-behaved host
                    // will not send an SVG. It is still refused by signature,
                    // because a header is a request and not a guarantee.
                    'Accept'     => 'image/png, image/jpeg, image/gif, image/webp',
                    'User-Agent' => 'hkm-avatars/0.1',
                ],
            ]);
        } catch (\Throwable $e) {
            // A refused URL and a timeout arrive on the same channel from the
            // adapter, and both mean the same thing to a caller: no image.
            // Matching on the message text to tell them apart would couple
            // this class to another plugin's wording.
            throw AvatarException::fetchFailed($url, $e);
        }

        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            throw AvatarException::fetchRefused($url, $status);
        }

        $bytes = $response->body();

        if (\strlen($bytes) < ImageSignature::PROBE_BYTES) {
            // A body too short to even hold a signature. Refused here rather
            // than passed to the signature check, so "empty response" is a
            // distinct reason in the log from "not an image".
            throw AvatarException::emptyImage($url, \strlen($bytes));
        }

        if (\strlen($bytes) > $this->maxBytes) {
            // The client's own ceiling should have stopped this. Checked again
            // because the ceiling is enforced by a progress callback that can
            // only act between chunks, so the final chunk can overshoot — and
            // "the guard is somewhere else" is how a limit stops being one.
            throw AvatarException::imageTooLarge($url, \strlen($bytes), $this->maxBytes);
        }

        $type = ImageSignature::of($bytes);

        if ($type === null) {
            // NOT a fallback to the declared Content-Type. That fallback is
            // the entire vulnerability: a host declares image/png, sends HTML
            // with a script in it, and the bytes get re-served from our origin.
            throw AvatarException::notAnImage($url, ImageSignature::acceptedTypes());
        }

        return new FetchedImage(bytes: $bytes, contentType: $type, url: $url);
    }

    /**
     * Refuse a URL that cannot be fetched safely, and return it normalised.
     *
     * This is ADVISORY about the destination and authoritative about the
     * SHAPE. A hostname's address can change between this check and the
     * socket, so nothing here can decide where the request ends up — that is
     * what `block_private` does at connect time, and it is the control. What
     * this catches is the scheme, the credentials and the obvious mistake,
     * while the caller can still be told something useful.
     */
    private function validate(string $url): string
    {
        $url = trim($url);

        if ($url === '' || \strlen($url) > 2048) {
            // 2048 is the practical ceiling browsers and proxies enforce.
            throw AvatarException::invalidUrl($url, 'it is empty or longer than 2048 characters');
        }

        // Control characters first. A newline in a URL is a request-splitting
        // primitive in any client that builds a request line by concatenation,
        // and it also lets a URL forge a line in our own log.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw AvatarException::invalidUrl($url, 'it contains control characters');
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw AvatarException::invalidUrl($url, 'it is not an absolute http(s) URL');
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::SCHEMES[$scheme])) {
            // `file://`, `php://`, `data://`, `gopher://` — every one of them a
            // way to read something that is not a remote image. An allowlist
            // and not a denylist, because the set of dangerous schemes is open
            // and the set of useful ones is two.
            throw AvatarException::invalidUrl($url, "the scheme [{$scheme}] is not http or https");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            // `http://user:pass@host/` — refused because it would put a
            // credential into our logs, and because `user@host` is also the
            // oldest way to make a URL look like it points somewhere else.
            throw AvatarException::invalidUrl($url, 'it carries credentials in the userinfo');
        }

        if ($parts['host'] === '') {
            throw AvatarException::invalidUrl($url, 'it has no host');
        }

        return $url;
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return [
            'max_bytes'     => $this->maxBytes,
            'timeout'       => $this->timeoutSeconds,
            // Printed so an operator can see it is off. A deployment that
            // turned it on should have that visible in a status line.
            'block_private' => !$this->allowPrivate,
            'accepts'       => implode(',', ImageSignature::acceptedTypes()),
        ];
    }
}
