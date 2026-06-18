<?php

declare(strict_types=1);

namespace Tallyst\XfRedirect;

/**
 * TargetResolver — Match → ['status'=>301,'location'=>…] | ['status'=>410] | ['status'=>0].
 *
 * status 0 = pass-through (let Flarum respond with 404). 410 = known deleted.
 */
final class TargetResolver
{
    public function __construct(private MapRepository $map)
    {
    }

    /**
     * @param array{type:string,id?:int,name?:string,page?:int} $match
     * @return array{status:int,location?:string}
     */
    public function resolve(array $match): array
    {
        $base = $this->map->baseUrl();

        switch ($match['type']) {
            case 'thread':
                if (!$this->map->discussionExists($match['id'])) {
                    return $this->gone($base);
                }
                $path = '/d/' . $match['id'];
                if (!empty($match['page']) && $match['page'] > 1) {
                    $path .= '/' . max(1, ($match['page'] - 1) * $this->map->xfPerPage() + 1);
                }
                return $this->to($base, $path);

            case 'post':
                $loc = $this->map->postLocation($match['id']);
                if ($loc === null) {
                    return $this->gone($base);
                }
                return $this->to($base, '/d/' . $loc['discussion_id'] . '/' . $loc['number']);

            case 'node':
                $slug = $this->map->tagSlug($match['id']);
                if ($slug === null) {
                    return $this->gone($base);
                }
                return $this->to($base, '/t/' . $slug);

            case 'member':
                $username = $this->map->username($this->map->userFlarumId($match['id']));
                if ($username === null) {
                    return $this->gone($base);
                }
                return $this->to($base, '/u/' . rawurlencode($username));

            case 'page':
                $slug = isset($match['id'])
                    ? $this->map->pageSlug($match['id'])
                    : $this->map->pageSlugByName($match['name'] ?? '');
                if ($slug === null) {
                    return $this->gone($base);
                }
                return $this->to($base, '/p/' . $slug);

            case 'attachment':
                // fof/upload has no XF attach_id in the table; without an attach→url map -> gone.
                return $this->gone($base);
        }

        return ['status' => 0];
    }

    /** @return array{status:int,location:string} */
    private function to(string $base, string $path): array
    {
        return ['status' => 301, 'location' => $base . $path];
    }

    /**
     * Unresolvable / deleted: according to the admin fallback.
     *  gone -> 410; home -> 302 to the home page; search -> 302 to search.
     * (302 because there is no permanent equivalent of this exact page.)
     * @return array{status:int,location?:string}
     */
    private function gone(string $base): array
    {
        switch ($this->map->fallback()) {
            case 'home':
                return ['status' => 302, 'location' => $base . '/'];
            case 'search':
                return ['status' => 302, 'location' => $base . '/?q='];
            default:
                return ['status' => 410];
        }
    }
}
