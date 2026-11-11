<?php

declare(strict_types=1);

namespace Plugins\Avatars\Application\Ports;

use Plugins\Avatars\Domain\FetchedImage;

/**
 * Fetches an image from a URL somebody supplied.
 *
 * An interface so the service's policy — what it does with a refusal, a
 * timeout, a non-image — is testable without a network, and so the ONE
 * implementation that talks to the outside world is a single auditable file.
 *
 * @see \Plugins\Avatars\Infrastructure\Gateways\HttpImageFetcher
 */
interface ImageFetcher
{
    /**
     * Fetch and verify, or throw.
     *
     * An implementation MUST:
     *   - refuse anything but http/https;
     *   - refuse private, loopback, link-local and reserved destinations;
     *   - not follow redirects;
     *   - bound the response size;
     *   - determine the content type FROM THE BYTES and refuse anything that
     *     is not a supported raster image.
     *
     * @throws \Plugins\Avatars\Infrastructure\Exceptions\AvatarException
     */
    public function fetch(string $url): FetchedImage;

    /** @return array<string, scalar> for a status line; never a credential */
    public function describe(): array;
}
