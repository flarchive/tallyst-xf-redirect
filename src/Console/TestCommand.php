<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect\Console;

use Illuminate\Console\Command;
use Tallyst\XfRedirect\TargetResolver;
use Tallyst\XfRedirect\UrlMatcher;

/**
 * redirect:test <url> — dry-run: resolve an old XF/vB URL to a Flarum target.
 * Makes no changes; it only prints what the middleware would return.
 */
class TestCommand extends Command
{
    protected $signature = 'redirect:test {url : old URL or path}';
    protected $description = 'Dry-run resolution of an old XF/vB URL to a Flarum target.';

    public function handle(UrlMatcher $matcher, TargetResolver $resolver): int
    {
        $url = (string) $this->argument('url');
        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        $query = $parts['query'] ?? '';

        $match = $matcher->match($path, $query);
        if ($match === null) {
            $this->line("• match: <comment>none</comment> (not an XF/vB URL) → pass-through");
            return 0;
        }
        $this->line('• match: ' . json_encode($match, JSON_UNESCAPED_SLASHES));

        $target = $resolver->resolve($match);
        $status = (int) ($target['status'] ?? 0);
        if ($status === 301) {
            $this->info('• 301 → ' . $target['location']);
        } elseif ($status === 410) {
            $this->warn('• 410 Gone (known deleted)');
        } else {
            $this->line('• pass-through (Flarum 404)');
        }
        return 0;
    }
}
