<?php

declare(strict_types=1);

namespace Plugins\Avatars\Infrastructure\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

/**
 * Lives in Infrastructure/ because it extends a kernel exception and the
 * Domain layer imports nothing outside Domain/ — the Domain classes here throw
 * `\InvalidArgumentException` or nothing at all.
 *
 * `ServiceException` (HTTP 422) rather than `GatewayException` (502), even for
 * the fetch failures. The reason is which side the fault is on: every one of
 * these follows from a URL somebody SUBMITTED, so the message is meant to be
 * read by whoever submitted it. A 502 would say the fault was ours and send
 * them to wait rather than to fix the URL.
 *
 * Generating an initials avatar throws nothing at all. It is a pure function
 * of a name, and a name with no letters in it produces a blank tile rather
 * than an error.
 */
final class AvatarException extends ServiceException
{
    public static function invalidUrl(string $url, string $why): self
    {
        return new self(
            // The URL is truncated before it reaches the message: it is
            // attacker-supplied text that will be read in a terminal and a log.
            "Cannot fetch an avatar from [" . self::clip($url) . "]: {$why}",
            layer: 'service.avatars.fetch',
            context: ['url' => self::clip($url)],
        );
    }

    public static function fetchFailed(string $url, \Throwable $previous): self
    {
        return new self(
            'The avatar could not be fetched: the request did not complete. The host may be '
            . 'unreachable, too slow, have an invalid certificate, or resolve to an address this '
            . 'application refuses to connect to.',
            layer: 'service.avatars.fetch',
            context: ['url' => self::clip($url)],
            previous: $previous,
        );
    }

    public static function fetchRefused(string $url, int $status): self
    {
        return new self(
            "The avatar could not be fetched: the host answered HTTP {$status}.",
            layer: 'service.avatars.fetch',
            context: ['url' => self::clip($url), 'status' => $status],
        );
    }

    public static function emptyImage(string $url, int $bytes): self
    {
        return new self(
            "The avatar could not be fetched: the host answered with {$bytes} byte(s), which is "
            . 'too short to be an image of any format.',
            layer: 'service.avatars.fetch',
            context: ['url' => self::clip($url), 'bytes' => $bytes],
        );
    }

    public static function imageTooLarge(string $url, int $bytes, int $max): self
    {
        return new self(
            "The avatar is {$bytes} bytes, over the {$max}-byte limit.",
            layer: 'service.avatars.fetch',
            context: ['url' => self::clip($url), 'bytes' => $bytes, 'max' => $max],
        );
    }

    /**
     * @param list<string> $accepted
     */
    public static function notAnImage(string $url, array $accepted): self
    {
        return new self(
            'The avatar could not be used: the bytes are not one of the supported raster image '
            . 'formats (' . implode(', ', $accepted) . '). The type is determined from the bytes '
            . 'and not from the Content-Type header, so a host declaring an image type while '
            . 'sending something else is refused here. SVG is refused on purpose — see the '
            . 'README.',
            layer: 'service.avatars.fetch',
            context: ['url' => self::clip($url)],
        );
    }

    /**
     * `remoteImage()` was called with no fetcher wired.
     *
     * Its own case because the fix is a line of JSON and nothing in a stack
     * trace would suggest it. Generating initials needs nothing; fetching needs
     * `http.client`, and a module's bindings only exist when it is in the
     * request's dependency graph.
     */
    public static function fetchingUnavailable(): self
    {
        return new self(
            'Fetching a remote avatar needs the `http.client` module, which is not in this '
            . 'request\'s dependency graph. Add "requires": ["avatar.generation", "http.client"] '
            . 'to the route (or to the module that declares it). Generating an initials avatar '
            . 'needs nothing and keeps working without it — which is why this plugin does not '
            . 'require http.client outright.',
            layer: 'service.avatars.fetch',
        );
    }

    /**
     * Keep an attacker-supplied URL short enough to read.
     *
     * Control characters are refused rather than stripped before this is ever
     * reached, so the only hazard left is length — a 2 KB URL in an exception
     * message makes the message unreadable in a terminal and in a log.
     */
    private static function clip(string $url): string
    {
        return mb_strlen($url) > 200 ? mb_substr($url, 0, 200) . '…' : $url;
    }
}
