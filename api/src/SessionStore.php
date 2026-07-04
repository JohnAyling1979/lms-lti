<?php

namespace App;

use Redis;

/**
 * Reads the session the auth service wrote to Redis.
 *
 * A deliberately separate, minimal implementation (no packback, no Composer) —
 * the api only needs to read/delete sessions. It agrees with auth on the storage
 * contract: the Redis key `sess:<sid>` holding a JSON identity. (Uses the phpredis
 * extension baked into the image, so the api still needs no Composer dependency.)
 */
class SessionStore
{
    private Redis $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect(getenv('REDIS_HOST') ?: 'redis', 6379);
    }

    public function get(string $sid): ?array
    {
        if ($sid === '') {
            return null;
        }
        $v = $this->redis->get('sess:' . $sid);

        return $v === false ? null : json_decode($v, true);
    }

    public function delete(string $sid): void
    {
        if ($sid !== '') {
            $this->redis->del('sess:' . $sid);
        }
    }
}
