<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tallyst\XfRedirect\License\LicenseClient;

/**
 * RedirectMiddleware — intercepts old XF/vB URLs and returns a permanent 301
 * (or 410 for known deleted content). Everything else is passed through to Flarum.
 *
 * Position: insertBefore(ResolveRoute) — see extend.php.
 */
class RedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UrlMatcher $matcher,
        private TargetResolver $resolver,
        private MapRepository $map,
        private LicenseClient $license
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Domain license gate (cached; no network on the hot path). If this domain
        // is not licensed, behave as if the extension does nothing — pass through.
        if (!$this->license->isActive()) {
            return $handler->handle($request);
        }

        $uri = $request->getUri();
        $host = $uri->getHost();

        // Act only on our own (old/new) domains, if they are configured.
        if ($this->map->hasAnyDomains() && !$this->map->isOwnHost($host)) {
            return $handler->handle($request);
        }

        $match = $this->matcher->match($uri->getPath(), $uri->getQuery());

        if ($match !== null) {
            $target = $this->resolver->resolve($match);
            $status = (int) ($target['status'] ?? 0);
            if (($status === 301 || $status === 302) && !empty($target['location'])) {
                return new RedirectResponse($target['location'], $status);
            }
            if ($status === 410) {
                return new EmptyResponse(410);
            }
            return $handler->handle($request);
        }

        // Canonical-host: on the OLD domain, redirect everything (that is not an XF
        // pattern) to the new domain (same path) — consolidation on a domain change.
        if ($this->map->canonicalHost()) {
            $newHost = $this->map->newHost();
            if ($newHost !== '' && strtolower($host) !== strtolower($newHost) && $this->map->isOwnHost($host)) {
                $q = $uri->getQuery();
                $loc = rtrim($this->map->baseUrl(), '/') . $uri->getPath() . ($q !== '' ? '?' . $q : '');
                return new RedirectResponse($loc, 301);
            }
        }

        // pass-through: let Flarum respond (404)
        return $handler->handle($request);
    }
}
