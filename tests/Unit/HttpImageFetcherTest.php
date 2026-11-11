<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Avatars\Domain\FetchedImage;
use Plugins\Avatars\Infrastructure\Exceptions\AvatarException;
use Plugins\Avatars\Infrastructure\Gateways\HttpImageFetcher;
use Tests\Plugins\Avatars\Support\RecordingHttpClient;

/**
 * The one dangerous thing in this plugin.
 *
 * Appwrite exposes this capability as an unauthenticated route, which makes it
 * an open proxy. Here it is a service method with every outbound default
 * overridden, and most of this file asserts that those overrides are still
 * being asked for — because they are invisible in any test that only checks
 * the returned bytes.
 */
#[CoversClass(HttpImageFetcher::class)]
#[CoversClass(FetchedImage::class)]
final class HttpImageFetcherTest extends TestCase
{
    private function fetcher(RecordingHttpClient $client, bool $allowPrivate = false): HttpImageFetcher
    {
        return new HttpImageFetcher(
            http:         $client,
            maxBytes:     1024,
            timeoutSeconds: 4,
            allowPrivate: $allowPrivate,
        );
    }

    // ── The guards are actually requested ───────────────────────────────────

    public function test_the_ssrf_guard_is_requested_on_every_fetch(): void
    {
        // THE assertion. Without `block_private`, a user can point an avatar
        // at http://169.254.169.254/latest/meta-data/ and read IAM credentials
        // out of the response. The HTTP client allows private ranges by
        // DEFAULT — correct for a first-party gateway, exactly wrong here — so
        // this plugin has to ask for the guard explicitly, every time.
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

        $this->fetcher($client)->fetch('https://example.com/a.png');

        self::assertTrue($client->options['block_private']);
    }

    public function test_the_guard_is_requested_again_on_a_second_fetch(): void
    {
        // Re-evaluated per call rather than decided once and cached: a name
        // that resolves publicly on one request and privately on the next IS
        // the DNS rebinding attack, so a cached verdict would be the hole.
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());
        $fetch  = $this->fetcher($client);

        $fetch->fetch('https://example.com/a.png');
        $fetch->fetch('https://example.com/b.png');

        self::assertCount(2, $client->calls);

        foreach ($client->calls as $call) {
            self::assertTrue($call['options']['block_private']);
        }
    }

    public function test_the_response_ceiling_and_a_single_attempt_are_requested(): void
    {
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

        $this->fetcher($client)->fetch('https://example.com/a.png');

        // A hostile host could otherwise stream filler until the process ran
        // out of memory, from one request.
        self::assertSame(1024, $client->options['max_response_bytes']);
        // A failed avatar fetch is not worth three attempts, and retrying
        // multiplies whatever the caller is doing by three.
        self::assertSame(0, $client->options['retry']);
        self::assertSame(4, $client->options['timeout']);
        self::assertSame(4, $client->options['connect_timeout'], 'capped at the overall timeout');
    }

    public function test_it_is_a_get_and_declares_what_it_accepts(): void
    {
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

        $this->fetcher($client)->fetch('https://example.com/a.png');

        self::assertSame('GET', $client->calls[0]['method']);
        // A request, not a guarantee — SVG is still refused by signature.
        self::assertStringNotContainsString('svg', $client->options['headers']['Accept']);
    }

    public function test_allowing_private_destinations_is_opt_in_and_visible(): void
    {
        // It exists for a test and should stay off everywhere else. It is
        // printed by describe() so a deployment that re-opened the surface has
        // that visible in a status line rather than only in an .env file.
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

        $this->fetcher($client, allowPrivate: true)->fetch('https://example.com/a.png');

        self::assertFalse($client->options['block_private']);
        self::assertFalse($this->fetcher($client, allowPrivate: true)->describe()['block_private']);
        self::assertTrue($this->fetcher($client)->describe()['block_private']);
    }

    // ── URL validation ───────────────────────────────────────────────────────

    /** @return list<array{string, string}> */
    public static function refusedUrls(): array
    {
        return [
            ['',                              'empty'],
            ['   ',                           'whitespace'],
            ['/relative/path.png',            'not absolute'],
            ['example.com/a.png',             'no scheme'],

            // An allowlist, not a denylist: the set of dangerous schemes is
            // open-ended and the set of useful ones is two.
            ['file:///etc/passwd',            'file:// reads the local disk'],
            ['php://filter/resource=x',       'a php:// wrapper'],
            ['data:image/png;base64,AAA',     'a data: URI is not a fetch'],
            ['gopher://host/1',               'gopher, a classic SSRF pivot'],
            ['ftp://host/a.png',              'ftp'],
            ['jar:http://host/a.jar!/x.png',  'a jar: URL'],

            // Userinfo: a credential that would land in our logs, and the
            // oldest way to make a URL look like it points somewhere else.
            ['https://user:pass@example.com/a.png', 'credentials in the userinfo'],
            ['https://user@example.com/a.png',      'a bare username'],

            // A newline is a request-splitting primitive in any client that
            // builds a request line by concatenation, and it also lets a URL
            // forge a line in our own log.
            ["https://example.com/a.png\r\nHost: evil", 'CRLF'],
            ["https://example.com/\x00.png",  'a NUL'],
            ["https://example.com/\x1b[2J",   'an ESC'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_a_url_that_cannot_be_fetched_safely_is_refused(string $url, string $why): void
    {
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

        try {
            $this->fetcher($client)->fetch($url);
            self::fail("must refuse: {$why}");
        } catch (AvatarException) {
            // And nothing was requested — the refusal happens before any
            // outbound call, so a hostile URL costs no socket.
            self::assertSame([], $client->calls, $why);
        }
    }

    public function test_a_url_longer_than_the_browser_ceiling_is_refused(): void
    {
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

        $this->expectException(AvatarException::class);

        $this->fetcher($client)->fetch('https://example.com/' . str_repeat('a', 2100) . '.png');
    }

    public function test_http_and_https_are_both_allowed(): void
    {
        foreach (['http://example.com/a.png', 'https://example.com/a.png', 'HTTPS://example.com/a.png'] as $url) {
            $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes());

            self::assertSame('image/png', $this->fetcher($client)->fetch($url)->contentType, $url);
        }
    }

    public function test_a_refused_url_message_is_clipped(): void
    {
        // The URL is attacker-supplied text that will be read in a terminal
        // and a log, so it is truncated before it reaches the message.
        $client = RecordingHttpClient::serving('');

        try {
            $this->fetcher($client)->fetch('gopher://' . str_repeat('x', 500));
            self::fail('must refuse');
        } catch (AvatarException $e) {
            self::assertLessThan(400, mb_strlen($e->getMessage()));
            self::assertStringContainsString('…', $e->getMessage());
        }
    }

    // ── What comes back is checked ──────────────────────────────────────────

    public function test_the_content_type_comes_from_the_bytes_and_not_the_header(): void
    {
        // THE attack: a host declares image/png and sends HTML with a script
        // in it. Trusting the header would re-serve those bytes from our
        // origin as an image, and a browser without nosniff would sniff the
        // real type and run it.
        $client = RecordingHttpClient::serving(
            RecordingHttpClient::jpegBytes(),
            200,
            ['Content-Type' => 'image/png'],
        );

        self::assertSame(
            'image/jpeg',
            $this->fetcher($client)->fetch('https://example.com/a.png')->contentType,
            'the bytes say JPEG; the header said PNG and was ignored',
        );
    }

    public function test_html_declared_as_an_image_is_refused(): void
    {
        $client = RecordingHttpClient::serving(
            RecordingHttpClient::htmlBytes(),
            200,
            ['Content-Type' => 'image/png'],
        );

        $this->expectException(AvatarException::class);
        $this->expectExceptionMessageMatches('/not one of the supported raster image formats/');

        $this->fetcher($client)->fetch('https://example.com/a.png');
    }

    public function test_a_remote_svg_is_refused_however_it_is_declared(): void
    {
        foreach (['image/svg+xml', 'image/png', 'text/plain'] as $declared) {
            $client = RecordingHttpClient::serving(
                RecordingHttpClient::svgBytes(),
                200,
                ['Content-Type' => $declared],
            );

            try {
                $this->fetcher($client)->fetch('https://example.com/a.svg');
                self::fail("a remote SVG declared as {$declared} must be refused");
            } catch (AvatarException $e) {
                self::assertStringContainsString('SVG is refused on purpose', $e->getMessage());
            }
        }
    }

    public function test_a_non_2xx_response_is_refused_with_its_status(): void
    {
        foreach ([301, 302, 400, 403, 404, 500, 503] as $status) {
            $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes(), $status);

            try {
                $this->fetcher($client)->fetch('https://example.com/a.png');
                self::fail("HTTP {$status} must be refused");
            } catch (AvatarException $e) {
                self::assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function test_a_redirect_is_a_refusal_and_not_a_hop(): void
    {
        // Stated on its own because it is the guard that matters most here:
        // following a 302 would let a remote host bounce the request to an
        // internal address AFTER the SSRF guard approved the public one it was
        // given. The client does not follow redirects, so a 3xx arrives as a
        // status — and this plugin treats it as a failure rather than reading
        // Location itself.
        $client = RecordingHttpClient::serving('', 302, ['Location' => 'http://169.254.169.254/']);

        $this->expectException(AvatarException::class);

        $this->fetcher($client)->fetch('https://example.com/a.png');

        self::assertCount(1, $client->calls, 'exactly one request, no hop');
    }

    public function test_an_empty_or_tiny_body_is_refused_as_empty(): void
    {
        // Its own reason, so "the host answered with nothing" is
        // distinguishable in a log from "the bytes are not an image".
        foreach (['', 'x', str_repeat('a', 11)] as $body) {
            $client = RecordingHttpClient::serving($body);

            try {
                $this->fetcher($client)->fetch('https://example.com/a.png');
                self::fail('a body too short to hold a signature must be refused');
            } catch (AvatarException $e) {
                self::assertStringContainsString('too short to be an image', $e->getMessage());
            }
        }
    }

    public function test_a_body_over_the_ceiling_is_refused_even_though_the_client_should_have_stopped_it(): void
    {
        // Checked again on this side because the client enforces its ceiling
        // with a progress callback that can only act BETWEEN chunks, so the
        // final chunk can overshoot. "The guard is somewhere else" is how a
        // limit stops being one.
        $client = RecordingHttpClient::serving(RecordingHttpClient::pngBytes() . str_repeat("\x00", 2048));

        $this->expectException(AvatarException::class);
        $this->expectExceptionMessageMatches('/over the 1024-byte limit/');

        $this->fetcher($client)->fetch('https://example.com/a.png');
    }

    public function test_a_transport_failure_becomes_one_reason(): void
    {
        // A refused URL and a timeout arrive on the same channel from the
        // adapter and mean the same thing to a caller: no image. Matching on
        // the message text to tell them apart would couple this class to
        // another plugin's wording.
        $client = RecordingHttpClient::serving('')
            ->failWith(new \RuntimeException('connection refused'));

        try {
            $this->fetcher($client)->fetch('https://example.com/a.png');
            self::fail('a transport failure must surface');
        } catch (AvatarException $e) {
            self::assertStringContainsString('the request did not complete', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    // ── What is returned ────────────────────────────────────────────────────

    public function test_a_successful_fetch_returns_the_bytes_and_the_determined_type(): void
    {
        $bytes  = RecordingHttpClient::webpBytes();
        $client = RecordingHttpClient::serving($bytes);

        $image = $this->fetcher($client)->fetch('https://example.com/a.webp');

        self::assertSame($bytes, $image->bytes);
        self::assertSame('image/webp', $image->contentType);
        self::assertSame('https://example.com/a.webp', $image->url);
        self::assertSame(\strlen($bytes), $image->byteLength());
    }

    public function test_the_response_headers_use_the_determined_type_and_forbid_sniffing(): void
    {
        $client  = RecordingHttpClient::serving(RecordingHttpClient::gifBytes(), 200, ['Content-Type' => 'image/png']);
        $headers = $this->fetcher($client)->fetch('https://example.com/a.gif')->headers();

        self::assertSame('image/gif', $headers['Content-Type'], 'not the declared image/png');
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertStringContainsString("default-src 'none'", $headers['Content-Security-Policy']);
        // NOT immutable: a remote image can change, and a year-long immutable
        // cache on somebody else's content would outlive the reason for it.
        self::assertStringNotContainsString('immutable', $headers['Cache-Control']);
    }

    public function test_describe_carries_the_limits_and_no_credential(): void
    {
        $described = $this->fetcher(RecordingHttpClient::serving(''))->describe();

        self::assertSame(1024, $described['max_bytes']);
        self::assertTrue($described['block_private']);
        self::assertStringContainsString('image/png', $described['accepts']);
        self::assertStringNotContainsString('svg', $described['accepts']);
    }

    public function test_describe_never_includes_a_url(): void
    {
        // A status line is printed and logged; a URL in it would be
        // attacker-supplied text in both.
        $described = $this->fetcher(RecordingHttpClient::serving(''))->describe();

        foreach ($described as $value) {
            self::assertStringNotContainsString('http', (string) $value);
        }
    }
}
