<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect\Console;

use Illuminate\Console\Command;
use Tallyst\XfRedirect\License\LicenseClient;

/**
 * redirect:license-check — force a fresh license phone-home now.
 *
 * Wired into the Flarum scheduler (daily) in extend.php so the cache refreshes
 * on its own when `php flarum schedule:run` runs from system cron. Also runnable
 * by hand for diagnostics.
 */
class LicenseCheckCommand extends Command
{
    protected $signature = 'redirect:license-check';
    protected $description = 'Re-validate the XF→Flarum Redirect license for this domain.';

    public function handle(LicenseClient $license): int
    {
        $status = $license->refresh();
        $this->info("License status for this domain: {$status}");

        return $status === 'inactive' ? 1 : 0;
    }
}
