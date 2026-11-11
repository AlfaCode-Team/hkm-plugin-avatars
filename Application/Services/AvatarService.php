<?php

declare(strict_types=1);

namespace Plugins\Avatars\Application\Services;

use Plugins\Avatars\API\Contracts\AvatarContract;
use Plugins\Avatars\Application\Ports\ImageFetcher;
use Plugins\Avatars\Domain\AvatarPalette;
use Plugins\Avatars\Domain\FetchedImage;
use Plugins\Avatars\Domain\Initials;
use Plugins\Avatars\Domain\InitialsAvatar;
use Plugins\Avatars\Infrastructure\Config\AvatarConfig;
use Plugins\Avatars\Infrastructure\Exceptions\AvatarException;

/**
 * Generating an initials avatar, and fetching a remote one.
 *
 * The two halves have nothing in common except the word "avatar", and they are
 * wired differently on purpose: initials need nothing at all, so they keep
 * working in any request, while fetching needs `http.client` and is therefore
 * OPTIONAL. A plugin that required `http.client` outright would make every
 * route that only wants initials load an HTTP client it never calls.
 */
final class AvatarService implements AvatarContract
{
    public function __construct(
        private readonly AvatarConfig $config,
        /**
         * Null when `http.client` is not in this request's dependency graph.
         * {@see remoteImage()} then throws with a message naming the fix,
         * rather than failing on a null call somewhere deeper.
         */
        private readonly ?ImageFetcher $fetcher = null,
    ) {}

    public function initials(string $name, ?int $size = null, ?string $shape = null): InitialsAvatar
    {
        // Clamped rather than validated: the size usually arrives from a query
        // string, and a 500 on `?size=99999` would be a denial of service
        // anybody could trigger with a URL.
        $size  = AvatarConfig::size($size ?? $this->config->defaultSize);
        $shape = AvatarConfig::shape($shape ?? $this->config->defaultShape);

        $background = AvatarPalette::backgroundFor($name);

        return new InitialsAvatar(
            initials:   Initials::of($name),
            background: $background,
            // Computed from the background rather than fixed, so a palette
            // edit cannot leave unreadable text behind. There is a test
            // asserting every palette entry clears WCAG AA.
            //
            // THIS CALL IS CURRENTLY UNPINNED, and a revert check is how that
            // was established: replacing it with `AvatarPalette::LIGHT_INK`
            // passes the whole suite, because every shipped background is dark
            // enough that white IS the computed answer. `inkFor()` itself is
            // unit-tested against black, white and yellow, so the computation
            // is held — what no test can distinguish today is whether this
            // line calls it. It stays because the moment a pale colour is
            // added to the palette, the difference becomes the difference
            // between readable initials and white-on-pale.
            ink:        AvatarPalette::inkFor($background),
            size:       $size,
            shape:      $shape,
        );
    }

    public function remoteImage(string $url): FetchedImage
    {
        if ($this->fetcher === null) {
            throw AvatarException::fetchingUnavailable();
        }

        return $this->fetcher->fetch($url);
    }

    public function canFetch(): bool
    {
        return $this->fetcher !== null;
    }

    public function describe(): string
    {
        $parts = [];

        foreach ($this->config->describe() as $key => $value) {
            $parts[] = $key . '=' . (\is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        $parts[] = 'palette=' . \count(AvatarPalette::BACKGROUNDS);
        $parts[] = 'fetch=' . ($this->fetcher === null ? 'unavailable' : 'ready');

        if ($this->fetcher !== null) {
            foreach ($this->fetcher->describe() as $key => $value) {
                $parts[] = $key . '=' . (\is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            }
        }

        return implode(' ', $parts);
    }
}
