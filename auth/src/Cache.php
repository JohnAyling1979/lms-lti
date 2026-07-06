<?php

namespace App;

use Packback\Lti1p3\Interfaces\ICache;
use Redis;

/**
 * Redis-backed store (like prod). Holds short-lived launch state — nonces (replay
 * protection), validated launch data, service access tokens — and the app session.
 *
 * Sessions are the shared bit: auth writes `sess:<sid>`, the api service reads the
 * same key from the same Redis. That shared key scheme is the contract between the
 * two services (locally it was a shared file volume; here it's real Redis).
 */
class Cache implements ICache
{
    private Redis $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect(getenv('REDIS_HOST') ?: 'redis', 6379);
    }

    // --- ICache: validated launch data --------------------------------------

    public function getLaunchData(string $key): ?array
    {
        $v = $this->redis->get('launch:' . $key);

        return $v === false ? null : json_decode($v, true);
    }

    public function cacheLaunchData(string $key, array $jwtBody): void
    {
        $this->redis->setex('launch:' . $key, 3600, json_encode($jwtBody));
    }

    // --- ICache: nonce (one-time, replay protection) ------------------------

    public function cacheNonce(string $nonce, string $state): void
    {
        $this->redis->setex('nonce:' . $nonce, 300, $state);
    }

    public function checkNonceIsValid(string $nonce, string $state): bool
    {
        $stored = $this->redis->get('nonce:' . $nonce);
        $this->redis->del('nonce:' . $nonce); // one-time use

        return $stored !== false && $stored === $state;
    }

    // --- ICache: service access tokens --------------------------------------

    public function cacheAccessToken(string $key, string $accessToken): void
    {
        $this->redis->setex('token:' . $key, 3000, $accessToken);
    }

    public function getAccessToken(string $key): ?string
    {
        $v = $this->redis->get('token:' . $key);

        return $v === false ? null : $v;
    }

    public function clearAccessToken(string $key): void
    {
        $this->redis->del('token:' . $key);
    }

    // --- deep-linking handoff -----------------------------------------------
    // Bridges the two hops of Deep Linking: the DL launch (id_token, single-use
    // nonce) stashes the return_url + deployment here, keyed by an opaque token
    // carried in the content-selection form; the form submit takes it back to
    // sign the response. Short-lived + one-time, like the nonce it replaces.

    public function stashDeepLink(string $token, array $data): void
    {
        $this->redis->setex('dl:' . $token, 600, json_encode($data));
    }

    public function takeDeepLink(string $token): ?array
    {
        $v = $this->redis->get('dl:' . $token);
        $this->redis->del('dl:' . $token); // one-time use

        return $v === false ? null : json_decode($v, true);
    }

    // --- app session (auth writes; api reads the same sess:<sid> key) --------

    public function putSession(string $sid, array $identity): void
    {
        $this->redis->setex('sess:' . $sid, 3600, json_encode($identity));
    }

    public function getSession(string $sid): ?array
    {
        $v = $this->redis->get('sess:' . $sid);

        return $v === false ? null : json_decode($v, true);
    }

    public function deleteSession(string $sid): void
    {
        $this->redis->del('sess:' . $sid);
    }
}
