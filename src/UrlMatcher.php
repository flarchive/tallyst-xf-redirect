<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect;

/**
 * UrlMatcher — old URL (path + raw query) → Match{type,id,post?,page?} | null.
 *
 * Covers XenForo forms (/threads /posts /forums /members /attachments /goto,
 * and index.php?<route>) and the vBulletin legacy (showthread.php?t/p,
 * forumdisplay.php?f, member.php?u). It does not touch non-XF paths (returns null).
 */
final class UrlMatcher
{
    /** @return array{type:string,id?:int,name?:string,page?:int}|null */
    public function match(string $path, string $rawQuery = ''): ?array
    {
        $path = '/' . ltrim($path, '/');
        parse_str($rawQuery, $q);

        // XF "index.php?<route>" — the whole route is in the query string (no '=').
        $firstParam = explode('&', $rawQuery)[0];
        if (($path === '/index.php' || $path === '/') && $firstParam !== '' && !str_contains($firstParam, '=')) {
            $path = '/' . ltrim($firstParam, '/');
        }

        // --- vBulletin legacy (id in the query parameters) ---
        if ($path === '/showthread.php') {
            if (!empty($q['p'])) {
                return ['type' => 'post', 'id' => (int) $q['p']];
            }
            if (!empty($q['t'])) {
                return ['type' => 'thread', 'id' => (int) $q['t']];
            }
            return null;
        }
        if ($path === '/forumdisplay.php' && !empty($q['f'])) {
            return ['type' => 'node', 'id' => (int) $q['f']];
        }
        if ($path === '/member.php' && !empty($q['u'])) {
            return ['type' => 'member', 'id' => (int) $q['u']];
        }

        // --- XF goto ---
        if ($path === '/goto/post' && !empty($q['id'])) {
            return ['type' => 'post', 'id' => (int) $q['id']];
        }

        // --- XF standard path forms ---
        if (preg_match('#^/threads/(?:[^/]*\.)?(\d+)(/.*)?$#', $path, $m)) {
            $rest = $m[2] ?? '';
            if (preg_match('#/post-(\d+)#', $rest, $x)) {       // a specific post is more precise than the thread
                return ['type' => 'post', 'id' => (int) $x[1]];
            }
            if (preg_match('#/page-(\d+)#', $rest, $x)) {
                return ['type' => 'thread', 'id' => (int) $m[1], 'page' => (int) $x[1]];
            }
            return ['type' => 'thread', 'id' => (int) $m[1]];
        }
        if (preg_match('#^/posts/(\d+)#', $path, $m)) {
            return ['type' => 'post', 'id' => (int) $m[1]];
        }
        if (preg_match('#^/forums/(?:[^/]*\.)?(\d+)#', $path, $m)) {
            return ['type' => 'node', 'id' => (int) $m[1]];
        }
        if (preg_match('#^/members/(?:[^/]*\.)?(\d+)#', $path, $m)) {
            return ['type' => 'member', 'id' => (int) $m[1]];
        }
        if (preg_match('#^/attachments/(?:[^/]*\.)?(\d+)#', $path, $m)) {
            return ['type' => 'attachment', 'id' => (int) $m[1]];
        }

        // XF Page nodes: /pages/{name}.{id}/ or /pages/{id}/ (by id),
        // otherwise /pages/{name}/ (by name == fof/pages slug).
        if (preg_match('#^/pages/(?:[^/]*\.)?(\d+)/?$#', $path, $m)) {
            return ['type' => 'page', 'id' => (int) $m[1]];
        }
        if (preg_match('#^/pages/([^/]+?)/?$#', $path, $m)) {
            return ['type' => 'page', 'name' => $m[1]];
        }

        return null;
    }
}
