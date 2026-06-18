<?php

/*
 * XF → Flarum Redirect — permanent 301 from old XenForo/vBulletin URLs to the
 * migrated Flarum content. The logic lives in the Flarum application (above the
 * web server), so it behaves identically on Apache and Nginx and across
 * subdomains / domain changes.
 */

declare(strict_types=1);

namespace Tallyst\XfRedirect;

use Flarum\Extend;
use Flarum\Http\Middleware\ResolveRoute;
use Illuminate\Console\Scheduling\Event;

return [
    // The redirect runs BEFORE the Flarum router. XF paths (/threads /posts
    // /forums /members /attachments) are disjoint from Flarum routes (/d /u /t),
    // so matching first does not shadow any real route.
    (new Extend\Middleware('forum'))
        ->insertBefore(ResolveRoute::class, RedirectMiddleware::class),

    (new Extend\Console())
        ->command(Console\ImportMapCommand::class)
        ->command(Console\TestCommand::class)
        ->command(Console\LicenseCheckCommand::class)
        // Refresh the domain license once a day (requires system cron running
        // `php flarum schedule:run`; otherwise the lazy 24h check still applies).
        ->schedule('redirect:license-check', function (Event $event) {
            $event->daily();
        }),

    // Admin settings (domains / fallback / canonical-host).
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),
];
