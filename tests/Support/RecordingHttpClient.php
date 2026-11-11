<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * An HTTP client that answers what the test queued and records what it was
 * asked.
 *
 * The RECORDING is the point. The three outbound options this plugin sets —
 * `block_private`, `max_response_bytes`, `retry` — are the whole of its SSRF
 * and memory defence, and they are invisible in any test that only checks the
 * returned bytes. Asserting on `$client->options` is the only way to show the
 * gateway still asks for them.
 */
final class RecordingHttpClient implements HttpClientPort
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> the LAST call's options, for convenience */
    public array $options = [];

    private ?\Throwable $throw = null;

    public function __construct(
        private int $status = 200,
        private string $body = '',
        /** @var array<string, string> */
        private array $headers = [],
    ) {}

    public static function serving(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }

    /** Make the next request throw, as a refused URL or a timeout would. */
    public function failWith(\Throwable $e): self
    {
        $this->throw = $e;

        return $this;
    }

    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        $this->calls[]  = ['method' => $method, 'url' => $url, 'options' => $options];
        $this->options  = $options;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new HttpClientResponse($this->status, $this->body, $this->headers);
    }

    public function get(string $url, array $query = []): HttpClientResponse
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    public function post(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('POST', $url, ['body' => $data]);
    }

    public function put(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PUT', $url, ['body' => $data]);
    }

    public function patch(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PATCH', $url, ['body' => $data]);
    }

    public function delete(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('DELETE', $url, ['body' => $data]);
    }

    public function pending(): PendingRequestContract
    {
        // Nothing in this plugin uses the fluent builder — it goes through
        // request() so the options are explicit and auditable in one place.
        throw new \LogicException('RecordingHttpClient does not implement pending().');
    }

    // ── Bytes that look like real images ────────────────────────────────────

    public static function pngBytes(): string
    {
        // The 8-byte signature plus enough filler to clear PROBE_BYTES. Not a
        // decodable PNG: nothing in this plugin decodes one, and a real file
        // would hide which bytes the signature check actually reads.
        return "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32);
    }

    public static function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32);
    }

    public static function gifBytes(): string
    {
        return 'GIF89a' . str_repeat("\x00", 32);
    }

    public static function webpBytes(): string
    {
        // RIFF, four length bytes, then the form type at offset 8.
        return 'RIFF' . "\x24\x00\x00\x00" . 'WEBP' . str_repeat("\x00", 24);
    }

    public static function wavBytes(): string
    {
        // Also RIFF — which is why a bare `RIFF` prefix is not enough to call
        // something a WebP.
        return 'RIFF' . "\x24\x00\x00\x00" . 'WAVE' . str_repeat("\x00", 24);
    }

    public static function svgBytes(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg">'
            . '<script>alert(document.domain)</script></svg>';
    }

    public static function htmlBytes(): string
    {
        return "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>";
    }
}
