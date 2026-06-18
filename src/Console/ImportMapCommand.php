<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect\Console;

use Flarum\Discussion\Discussion;
use Illuminate\Console\Command;

/**
 * redirect:import-map <file> — loads redirects.jsonl (from the migrator) into
 * xf_migration_map (truncate + insert). Run after `php flarum migrate`.
 */
class ImportMapCommand extends Command
{
    protected $signature = 'redirect:import-map {file : path to redirects.jsonl}';
    protected $description = 'Load redirects.jsonl (migrator output) into xf_migration_map.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("File does not exist: $file");
            return 1;
        }

        $db = Discussion::query()->getConnection();
        $db->table('xf_migration_map')->truncate();

        $fp = fopen($file, 'rb');
        if ($fp === false) {
            $this->error("Cannot open: $file");
            return 1;
        }

        $rows = [];
        $n = 0;
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            if (!is_array($rec)) {
                continue;
            }
            $row = $this->toRow($rec);
            if ($row === null) {
                continue;
            }
            $rows[] = $row;
            $n++;
            if (count($rows) >= 500) {
                $db->table('xf_migration_map')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $db->table('xf_migration_map')->insert($rows);
        }
        fclose($fp);

        $this->info("Imported $n records into xf_migration_map.");
        return 0;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>|null
     */
    private function toRow(array $r): ?array
    {
        switch ($r['type'] ?? '') {
            case 'meta':
                return [
                    'source_type' => 'meta',
                    'source_id' => null,
                    'target' => (string) ($r['new_base_url'] ?? ''),
                    'extra' => json_encode([
                        'xf_per_page' => (int) ($r['xf_per_page'] ?? 20),
                        'flarum_per_page' => (int) ($r['flarum_per_page'] ?? 20),
                    ]),
                ];
            case 'domain':
                return ['source_type' => 'domain', 'source_id' => null, 'target' => strtolower((string) ($r['host'] ?? '')), 'extra' => null];
            case 'node':
                return [
                    'source_type' => 'node',
                    'source_id' => (int) ($r['node_id'] ?? 0),
                    'target' => isset($r['tag_slug']) ? (string) $r['tag_slug'] : null,
                    'extra' => isset($r['tag_id']) ? json_encode(['tag_id' => (int) $r['tag_id']]) : null,
                ];
            case 'user_x':
                return ['source_type' => 'user_x', 'source_id' => (int) ($r['xf_id'] ?? 0), 'target' => (string) ($r['flarum_id'] ?? ''), 'extra' => null];
        }
        return null;
    }
}
