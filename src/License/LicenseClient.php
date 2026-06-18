<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect\License;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;

/**
 * LicenseClient — domain-bound license check ("phone-home").
 *
 * Model: the buyer registers the domain their Flarum runs on at purchase time.
 * On its own host this extension asks the license server "is <host> licensed?",
 * caches the answer in settings, and re-checks periodically. NO license code to
 * paste — the domain IS the key.
 *
 * Cadence: cached (TTL_OK, 24h) so normal traffic NEVER hits the network — it
 * just reads the setting. A stale cache (or a changed host) triggers one fresh
 * check. See LicenseCheckCommand for the scheduled (cron) refresh.
 *
 * FAIL-OPEN: only an explicit licensed:false from the server ('inactive')
 * disables the extension. Unknown / unreachable stays active (+ admin notice),
 * so OUR downtime never breaks the customer's forum. (Trade-off: a non-buyer who
 * firewalls our server stays on 'unverified' = active — acceptable, this is an
 * honest-customer gate, and the extension is only useful on the real domain.)
 */
final class LicenseClient
{
    public const PREFIX = 'tallyst-xf-redirect.';

    private const PRODUCT      = 'xf-redirect';
    private const ENDPOINT     = 'https://license.tallyst.dev/v1/check';
    private const TTL_OK       = 86400; // 24h — re-check a definitive answer daily
    private const TTL_RETRY    = 3600;  // 1h  — retry sooner while still unverified
    private const HTTP_TIMEOUT = 4.0;   // hard cap so a check never hangs a request

    private ?bool $memo = null;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Config $config
    ) {
    }

    /**
     * May the extension act on this request? Read by RedirectMiddleware.
     * True for 'active' AND 'unverified' (fail-open); false only for 'inactive'.
     */
    public function isActive(): bool
    {
        return $this->memo ??= ($this->status() !== 'inactive');
    }

    /**
     * Cached status for the admin UI and the gate.
     * 'active' | 'inactive' | 'unverified'. Refreshes lazily when stale.
     */
    public function status(): string
    {
        $host    = $this->siteHost();
        $status  = $this->get('license.status') ?: 'unverified';
        $domain  = $this->get('license.domain') ?: '';
        $checked = (int) $this->get('license.checked_at');

        $ttl   = in_array($status, ['active', 'inactive'], true) ? self::TTL_OK : self::TTL_RETRY;
        $fresh = $checked > 0 && ($this->now() - $checked) < $ttl && $domain === $host;

        return $fresh ? $status : $this->refresh($host);
    }

    /** Force a phone-home now; store + return the resulting status. */
    public function refresh(?string $host = null): string
    {
        $host     = $host ?? $this->siteHost();
        $previous = (string) ($this->get('license.status') ?: '');

        try {
            $res = (new Client())->get($this->endpoint(), [
                'query'       => ['domain' => $host, 'product' => self::PRODUCT],
                'timeout'     => self::HTTP_TIMEOUT,
                'http_errors' => false,
            ]);
            $body = json_decode((string) $res->getBody(), true);

            if ($res->getStatusCode() === 200 && is_array($body) && array_key_exists('licensed', $body)) {
                $status = $body['licensed'] ? 'active' : 'inactive';
                $this->store($status, $host);

                return $status;
            }
            throw new \RuntimeException('unexpected license response'); // reachable but bad payload -> treat as transient
        } catch (\Throwable) {
            // FAIL-OPEN: ride the last known good; never hard-fail on our outage.
            $status = in_array($previous, ['active', 'inactive'], true) ? $previous : 'unverified';
            $this->store($status, $host);

            return $status;
        }
    }

    /** License server endpoint; overridable via setting (staging/self-host). */
    private function endpoint(): string
    {
        return $this->get('license.endpoint') ?: self::ENDPOINT;
    }

    /** The domain the license is bound to = this Flarum's own (canonical) host. */
    private function siteHost(): string
    {
        try {
            return strtolower((string) $this->config->url()->getHost());
        } catch (\Throwable) {
            return '';
        }
    }

    private function store(string $status, string $host): void
    {
        $this->settings->set(self::PREFIX . 'license.status', $status);
        $this->settings->set(self::PREFIX . 'license.domain', $host);
        $this->settings->set(self::PREFIX . 'license.checked_at', (string) $this->now());
        $this->memo = null;
    }

    private function get(string $key): ?string
    {
        $v = $this->settings->get(self::PREFIX . $key);

        return $v === null ? null : (string) $v;
    }

    private function now(): int
    {
        return time();
    }
}
