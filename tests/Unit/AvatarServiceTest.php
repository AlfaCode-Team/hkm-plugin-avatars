<?php

declare(strict_types=1);

namespace Tests\Plugins\Avatars\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Avatars\Application\Services\AvatarService;
use Plugins\Avatars\Domain\AvatarPalette;
use Plugins\Avatars\Domain\InitialsAvatar;
use Plugins\Avatars\Infrastructure\Config\AvatarConfig;
use Plugins\Avatars\Infrastructure\Exceptions\AvatarException;
use Plugins\Avatars\Infrastructure\Gateways\HttpImageFetcher;
use Tests\Plugins\Avatars\Support\RecordingHttpClient;

#[CoversClass(AvatarService::class)]
#[CoversClass(AvatarConfig::class)]
final class AvatarServiceTest extends TestCase
{
    private function service(?AvatarConfig $config = null, bool $withFetcher = false): AvatarService
    {
        return new AvatarService(
            config:  $config ?? AvatarConfig::of(),
            fetcher: $withFetcher
                ? new HttpImageFetcher(RecordingHttpClient::serving(RecordingHttpClient::pngBytes()))
                : null,
        );
    }

    // ── Initials need nothing ────────────────────────────────────────────────

    public function test_an_initials_avatar_needs_no_fetcher(): void
    {
        // The reason `http.client` is not in requires[]: generating initials
        // is a pure function, so a route that only wants one must not have to
        // load an HTTP client it never calls.
        $avatar = $this->service()->initials('John Smith');

        self::assertSame('JS', $avatar->initials);
        self::assertFalse($this->service()->canFetch());
    }

    public function test_the_colour_is_deterministic_through_the_service(): void
    {
        $first = $this->service()->initials('José María García');

        self::assertSame($first->background, $this->service()->initials('josé maría garcía')->background);
        self::assertContains($first->background, AvatarPalette::BACKGROUNDS);
    }

    public function test_the_ink_is_the_one_computed_for_the_background(): void
    {
        $avatar = $this->service()->initials('Anybody');

        self::assertSame(AvatarPalette::inkFor($avatar->background), $avatar->ink);
        self::assertGreaterThanOrEqual(
            AvatarPalette::MIN_CONTRAST,
            AvatarPalette::contrast($avatar->background, $avatar->ink),
        );
    }

    // ── Size and shape are clamped, never rejected ──────────────────────────

    /** @return list<array{int|null, int, string}> */
    public static function sizes(): array
    {
        return [
            [null, 128, 'the configured default'],
            [64,    64, 'an ordinary request'],
            [16,    16, 'the floor'],
            [1024, 1024, 'the ceiling'],

            // CLAMPED rather than rejected. The size usually arrives from a
            // query string, so a 500 on `?size=99999` would be a denial of
            // service anybody could trigger with a URL.
            [0,     16, 'zero'],
            [-100,  16, 'negative'],
            [8,     16, 'below the floor is a smudge'],
            [99999, 1024, 'above the ceiling is a poster, not an avatar'],
            [\PHP_INT_MAX, 1024, 'and an overflow attempt'],
        ];
    }

    #[DataProvider('sizes')]
    public function test_a_requested_size_is_clamped(?int $given, int $expected, string $why): void
    {
        self::assertSame($expected, $this->service()->initials('A B', $given)->size, $why);
    }

    /** @return list<array{string|null, string}> */
    public static function shapes(): array
    {
        return [
            [null, InitialsAvatar::SHAPE_CIRCLE],
            ['circle', InitialsAvatar::SHAPE_CIRCLE],
            ['square', InitialsAvatar::SHAPE_SQUARE],
            ['rounded', InitialsAvatar::SHAPE_ROUNDED],
            ['SQUARE', InitialsAvatar::SHAPE_SQUARE],
            ['  square  ', InitialsAvatar::SHAPE_SQUARE],
            // An unknown shape falls back rather than rendering nothing.
            ['hexagon', InitialsAvatar::SHAPE_CIRCLE],
            ['', InitialsAvatar::SHAPE_CIRCLE],
            ['<script>', InitialsAvatar::SHAPE_CIRCLE],
        ];
    }

    #[DataProvider('shapes')]
    public function test_a_requested_shape_is_normalised(?string $given, string $expected): void
    {
        self::assertSame($expected, $this->service()->initials('A B', null, $given)->shape);
    }

    public function test_a_hostile_shape_cannot_reach_the_document(): void
    {
        // The shape selects a branch rather than being interpolated, and the
        // config normalises it first — but worth pinning, because a shape
        // string reaching an element name would be injection.
        $svg = $this->service()->initials('A B', 64, '"><script>alert(1)</script>')->toSvg();

        self::assertStringNotContainsString('script', $svg);
        self::assertStringContainsString('<circle', $svg);
    }

    // ── Config normalisation cannot be bypassed ─────────────────────────────

    public function test_config_normalisation_happens_in_the_constructor(): void
    {
        // Both named constructors go through the private one, so neither can
        // skip the clamps — the mistake found and fixed in the resource-tokens
        // plugin, where the validation lived in `fromEnv()` only.
        $config = AvatarConfig::of(
            defaultSize: 99999,
            defaultShape: 'hexagon',
            maxFetchBytes: 1,
            fetchTimeout: 0,
        );

        self::assertSame(1024, $config->defaultSize);
        self::assertSame(InitialsAvatar::SHAPE_CIRCLE, $config->defaultShape);
        self::assertSame(4096, $config->maxFetchBytes, 'below any real image');
        self::assertSame(1, $config->fetchTimeout);
    }

    public function test_an_absurd_fetch_ceiling_is_clamped_at_both_ends(): void
    {
        // A value outside the sane band is a typo, and honouring it would
        // either refuse every image or let one exhaust memory.
        self::assertSame(4096, AvatarConfig::of(maxFetchBytes: -1)->maxFetchBytes);
        self::assertSame(16 * 1024 * 1024, AvatarConfig::of(maxFetchBytes: \PHP_INT_MAX)->maxFetchBytes);
        self::assertSame(60, AvatarConfig::of(fetchTimeout: 9999)->fetchTimeout);
    }

    // ── Fetching is optional, and says so clearly ───────────────────────────

    public function test_fetching_without_the_http_client_names_the_fix(): void
    {
        // The fix is a line of JSON, and nothing in a stack trace would
        // suggest it: a module's bindings only exist when it is in the
        // request's dependency graph.
        try {
            $this->service()->remoteImage('https://example.com/a.png');
            self::fail('must refuse without a fetcher');
        } catch (AvatarException $e) {
            self::assertStringContainsString('"requires": ["avatar.generation", "http.client"]', $e->getMessage());
            self::assertStringContainsString('dependency graph', $e->getMessage());
        }
    }

    public function test_fetching_works_when_the_client_is_available(): void
    {
        $service = $this->service(withFetcher: true);

        self::assertTrue($service->canFetch());
        self::assertSame(
            'image/png',
            $service->remoteImage('https://example.com/a.png')->contentType,
        );
    }

    public function test_describe_reports_whether_fetching_is_possible(): void
    {
        self::assertStringContainsString('fetch=unavailable', $this->service()->describe());
        self::assertStringContainsString('fetch=ready', $this->service(withFetcher: true)->describe());
    }

    public function test_describe_carries_the_palette_size_and_the_ssrf_guard(): void
    {
        $described = $this->service(withFetcher: true)->describe();

        self::assertStringContainsString('palette=' . \count(AvatarPalette::BACKGROUNDS), $described);
        // Printed so a deployment that re-opened the SSRF surface has it
        // visible in a status line rather than only in an .env file.
        self::assertStringContainsString('block_private=yes', $described);
    }

    public function test_describe_carries_no_credential(): void
    {
        foreach (['secret', 'password', 'dsn', 'token'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $this->service(withFetcher: true)->describe(),
                $forbidden,
            );
        }
    }

    // ── The whole pipeline, end to end ──────────────────────────────────────

    public function test_a_hostile_name_produces_a_safe_document_through_the_service(): void
    {
        // The composition that is actually deployed: name → Initials →
        // palette → document. Either half changing could break it, so it is
        // asserted at the seam a caller uses.
        $svg = $this->service()->initials('</text><script>alert(document.domain)</script>')->toSvg();

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg));
        self::assertSame(0, $document->getElementsByTagName('script')->length);
    }

    public function test_an_empty_name_still_produces_a_usable_avatar(): void
    {
        // A nameless user gets a tile, not a crash and not a transparent gap.
        $avatar = $this->service()->initials('');

        self::assertSame('', $avatar->initials);
        self::assertContains($avatar->background, AvatarPalette::BACKGROUNDS);
        self::assertStringContainsString('<circle', $avatar->toSvg());
    }
}
