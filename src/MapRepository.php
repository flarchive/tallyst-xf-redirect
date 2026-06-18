<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * MapRepository — access to the map (xf_migration_map) + admin settings + live
 * Flarum lookups.
 *
 * The small part (meta/domains/user exceptions) is loaded once per request (memo).
 * Identity (thread/post/user/tag) is resolved directly from the live tables
 * because the importer preserves IDs (tag.id = node_id, post id = post id,
 * user id ≈ same). Admin settings (extra domains, fallback, canonical-host)
 * come from the Flarum settings.
 */
final class MapRepository
{
    public const PREFIX = 'tallyst-xf-redirect.';

    /** @var array{base_url:string,xf_per_page:int,domains:array<string,bool>,users:array<int,int>}|null */
    private ?array $memo = null;

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    private function db(): ConnectionInterface
    {
        return Discussion::query()->getConnection();
    }

    /** @return array{base_url:string,xf_per_page:int,domains:array<string,bool>,users:array<int,int>} */
    private function load(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $base = '';
        $xfPerPage = 20;
        $domains = [];
        $users = [];

        if ($this->db()->getSchemaBuilder()->hasTable('xf_migration_map')) {
            foreach ($this->db()->table('xf_migration_map')->get() as $r) {
                $extra = $r->extra ? (array) json_decode((string) $r->extra, true) : [];
                switch ($r->source_type) {
                    case 'meta':
                        $base = rtrim((string) $r->target, '/');
                        $xfPerPage = (int) ($extra['xf_per_page'] ?? 20) ?: 20;
                        break;
                    case 'domain':
                        $domains[strtolower((string) $r->target)] = true;
                        break;
                    case 'user_x':
                        $users[(int) $r->source_id] = (int) $r->target;
                        break;
                }
            }
        }

        return $this->memo = [
            'base_url' => $base,
            'xf_per_page' => $xfPerPage,
            'domains' => $domains,
            'users' => $users,
        ];
    }

    public function baseUrl(): string
    {
        return $this->load()['base_url'];
    }

    public function xfPerPage(): int
    {
        return $this->load()['xf_per_page'];
    }

    /** All own domains = from the map (import seed) + admin extras (settings). */
    private function allDomains(): array
    {
        $d = $this->load()['domains'];
        foreach ($this->extraDomains() as $h) {
            $d[$h] = true;
            $d[str_starts_with($h, 'www.') ? substr($h, 4) : 'www.' . $h] = true;
        }
        return $d;
    }

    /** @return array<int,string> extra domains from the admin settings (per line/comma) */
    public function extraDomains(): array
    {
        $raw = (string) $this->settings->get(self::PREFIX . 'domains', '');
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $h) {
            $h = strtolower(trim($h));
            if ($h !== '') {
                $out[] = $h;
            }
        }
        return $out;
    }

    public function hasAnyDomains(): bool
    {
        return $this->allDomains() !== [];
    }

    public function isOwnHost(string $host): bool
    {
        return isset($this->allDomains()[strtolower($host)]);
    }

    public function newHost(): string
    {
        return (string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?: '');
    }

    /** Fallback for the unresolvable: 'gone' (410) | 'home' | 'search'. */
    public function fallback(): string
    {
        $v = (string) $this->settings->get(self::PREFIX . 'fallback', 'gone');
        return in_array($v, ['gone', 'home', 'search'], true) ? $v : 'gone';
    }

    public function canonicalHost(): bool
    {
        return (bool) $this->settings->get(self::PREFIX . 'canonicalHost', false);
    }

    /** Reconciliation: xf_user_id → flarum_user_id (identity if there is no exception). */
    public function userFlarumId(int $xfId): int
    {
        return $this->load()['users'][$xfId] ?? $xfId;
    }

    // --- Live lookups (identity) ------------------------------------------

    public function discussionExists(int $id): bool
    {
        return $this->db()->table('discussions')->where('id', $id)->exists();
    }

    /** @return array{discussion_id:int,number:int}|null */
    public function postLocation(int $postId): ?array
    {
        $p = $this->db()->table('posts')->where('id', $postId)->first(['discussion_id', 'number']);
        return $p ? ['discussion_id' => (int) $p->discussion_id, 'number' => (int) $p->number] : null;
    }

    public function username(int $userId): ?string
    {
        $u = $this->db()->table('users')->where('id', $userId)->value('username');
        return $u !== null ? (string) $u : null;
    }

    /** node_id == tag_id (ID preservation); slug from the live tags table. */
    public function tagSlug(int $tagId): ?string
    {
        if (!$this->db()->getSchemaBuilder()->hasTable('tags')) {
            return null;
        }
        $s = $this->db()->table('tags')->where('id', $tagId)->value('slug');
        return $s !== null ? (string) $s : null;
    }

    /** fof/pages: page.id == node_id (ID preservation) -> slug. */
    public function pageSlug(int $id): ?string
    {
        if (!$this->db()->getSchemaBuilder()->hasTable('pages')) {
            return null;
        }
        $s = $this->db()->table('pages')->where('id', $id)->value('slug');
        return $s !== null ? (string) $s : null;
    }

    /** XF node_name == fof/pages slug -> confirm existence, return slug. */
    public function pageSlugByName(string $name): ?string
    {
        if ($name === '' || !$this->db()->getSchemaBuilder()->hasTable('pages')) {
            return null;
        }
        $s = $this->db()->table('pages')->where('slug', $name)->value('slug');
        return $s !== null ? (string) $s : null;
    }
}
