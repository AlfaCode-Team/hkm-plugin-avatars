<?php

declare(strict_types=1);

namespace Plugins\Avatars\Infrastructure\Config;

use Plugins\Avatars\Domain\InitialsAvatar;

/**
 * Every knob, normalised in the private constructor so neither named
 * constructor can bypass it.
 *
 * `env()` and not `getenv()`: `$_ENV` is the source of truth in this framework
 * and `putenv()` is never called, so `getenv()` returns null for a value that
 * is present — a default silently winning over configuration.
 */
final readonly class AvatarConfig
{
    /**
     * Below this an initial is a smudge; above it an SVG is being used as a
     * poster. Both ends are clamps rather than errors, because a size usually
     * arrives from a query string and a 500 on `?size=99999` would be a
     * denial of service anybody could trigger.
     */
    public const MIN_SIZE = 16;

    public const MAX_SIZE = 1024;

    public int $defaultSize;

    public string $defaultShape;

    public int $maxFetchBytes;

    public int $fetchTimeout;

    /** Re-opens the SSRF surface. Off, and it should stay off. */
    public bool $allowPrivateFetch;

    private function __construct(
        int $defaultSize,
        string $defaultShape,
        int $maxFetchBytes,
        int $fetchTimeout,
        bool $allowPrivateFetch,
    ) {
        $this->defaultSize       = self::size($defaultSize);
        $this->defaultShape      = self::shape($defaultShape);
        // 4 KB is below any real image, and 16 MB is past any sane avatar. A
        // value outside that is a typo, and honouring it would either refuse
        // every image or let one exhaust memory.
        $this->maxFetchBytes     = max(4096, min(16 * 1024 * 1024, $maxFetchBytes));
        $this->fetchTimeout      = max(1, min(60, $fetchTimeout));
        $this->allowPrivateFetch = $allowPrivateFetch;
    }

    public static function fromEnv(): self
    {
        return new self(
            defaultSize:       (int) env('AVATARS_DEFAULT_SIZE', 128),
            defaultShape:      (string) env('AVATARS_DEFAULT_SHAPE', InitialsAvatar::SHAPE_CIRCLE),
            maxFetchBytes:     (int) env('AVATARS_MAX_FETCH_BYTES', 2 * 1024 * 1024),
            fetchTimeout:      (int) env('AVATARS_FETCH_TIMEOUT', 8),
            allowPrivateFetch: (bool) env('AVATARS_ALLOW_PRIVATE_FETCH', false),
        );
    }

    public static function of(
        int $defaultSize = 128,
        string $defaultShape = InitialsAvatar::SHAPE_CIRCLE,
        int $maxFetchBytes = 2097152,
        int $fetchTimeout = 8,
        bool $allowPrivateFetch = false,
    ): self {
        return new self($defaultSize, $defaultShape, $maxFetchBytes, $fetchTimeout, $allowPrivateFetch);
    }

    /** Clamp a requested size into the renderable range. */
    public static function size(int $given): int
    {
        return max(self::MIN_SIZE, min(self::MAX_SIZE, $given));
    }

    /** An unknown shape falls back to a circle rather than rendering nothing. */
    public static function shape(string $given): string
    {
        $given = strtolower(trim($given));

        return \in_array($given, [
            InitialsAvatar::SHAPE_SQUARE,
            InitialsAvatar::SHAPE_CIRCLE,
            InitialsAvatar::SHAPE_ROUNDED,
        ], true) ? $given : InitialsAvatar::SHAPE_CIRCLE;
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return [
            'default_size'  => $this->defaultSize,
            'default_shape' => $this->defaultShape,
            'max_fetch'     => $this->maxFetchBytes,
            'fetch_timeout' => $this->fetchTimeout,
            // Printed so a deployment that re-opened the SSRF surface has it
            // visible in a status line rather than only in an .env file.
            'block_private' => !$this->allowPrivateFetch,
        ];
    }
}
