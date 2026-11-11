<?php

declare(strict_types=1);

namespace Plugins\Avatars;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Avatars\API\Contracts\AvatarContract;
use Plugins\Avatars\Application\Ports\ImageFetcher;
use Plugins\Avatars\Application\Services\AvatarService;
use Plugins\Avatars\Infrastructure\Config\AvatarConfig;
use Plugins\Avatars\Infrastructure\Gateways\HttpImageFetcher;

/**
 * Avatars — wires the 'avatar.generation' domain.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `http.client` IS NOT IN requires[], ON PURPOSE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Generating an initials avatar needs nothing — no network, no database, no
 * image library, no font. Declaring `http.client` as a requirement would make
 * every route that wants initials load an HTTP client it never calls.
 *
 * So the fetcher is bound only when `HttpClientPort` is already resolvable,
 * and a route that wants {@see AvatarContract::remoteImage()} declares
 * `"requires": ["avatar.generation", "http.client"]`. Calling it without that
 * throws with a message naming exactly that line — the same optional-port
 * pattern the webhook plugin uses for `EncryptionPort`.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NO ROUTES
 * ────────────────────────────────────────────────────────────────────────────
 *
 * An initials avatar is a pure function the application can serve from its own
 * route, with its own cache headers, under its own URL. A remote image fetch
 * MUST NOT be a route: Appwrite's `GET /avatars/image?url=…` is an open proxy,
 * and only the application can decide who may ask it to fetch something.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'avatar.generation';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [AvatarContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            AvatarConfig::class,
            static fn (): AvatarConfig => AvatarConfig::fromEnv(),
        );

        $container->bindInternal(
            ImageFetcher::class,
            static fn (ModuleContainer $c): HttpImageFetcher => new HttpImageFetcher(
                http:           $c->make(HttpClientPort::class),
                maxBytes:       $c->make(AvatarConfig::class)->maxFetchBytes,
                timeoutSeconds: $c->make(AvatarConfig::class)->fetchTimeout,
                allowPrivate:   $c->make(AvatarConfig::class)->allowPrivateFetch,
            ),
        );

        $container->bind(
            AvatarContract::class,
            static function (ModuleContainer $c): AvatarService {
                $config = $c->make(AvatarConfig::class);

                return new AvatarService(
                    config: $config,
                    // Only when the HTTP client is actually available in this
                    // request. `has()` and not a try/catch: a missing binding
                    // is the expected state for a route that only wants
                    // initials, not an exceptional one.
                    fetcher: $c->has(HttpClientPort::class)
                        ? $c->make(ImageFetcher::class)
                        : null,
                );
            },
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Nothing to wire. No routes (see the class docblock), no commands —
        // there is no state to inspect, prune or repair, so a command would
        // have nothing to do — no events, and no pipeline hooks.
    }
}
