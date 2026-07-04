<?php

namespace App;

use Packback\Lti1p3\Interfaces\ICache;

/**
 * Stores short-lived launch state: nonces (replay protection), validated launch
 * data, and service access tokens. File-based here; use Redis/DB in production.
 */
class Cache implements ICache
{
    private string $dir;

    public function __construct(string $dir = '/tmp/lti-cache')
    {
        $this->dir = $dir;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    private function read(string $key): ?array
    {
        $p = $this->path($key);
        if (!is_file($p)) {
            return null;
        }
        $data = json_decode(file_get_contents($p), true);
        if (isset($data['exp']) && $data['exp'] !== null && $data['exp'] < time()) {
            @unlink($p);
            return null;
        }
        return $data;
    }

    private function write(string $key, $val, ?int $ttl): void
    {
        file_put_contents($this->path($key), json_encode([
            'val' => $val,
            'exp' => $ttl ? time() + $ttl : null,
        ]));
    }

    public function getLaunchData(string $key): ?array
    {
        return $this->read('launch:' . $key)['val'] ?? null;
    }

    public function cacheLaunchData(string $key, array $jwtBody): void
    {
        $this->write('launch:' . $key, $jwtBody, 3600);
    }

    public function cacheNonce(string $nonce, string $state): void
    {
        $this->write('nonce:' . $nonce, $state, 300);
    }

    public function checkNonceIsValid(string $nonce, string $state): bool
    {
        $data = $this->read('nonce:' . $nonce);
        // one-time use: consume the nonce whether or not it matches
        @unlink($this->path('nonce:' . $nonce));

        return $data !== null && $data['val'] === $state;
    }

    public function cacheAccessToken(string $key, string $accessToken): void
    {
        $this->write('token:' . $key, $accessToken, 3000);
    }

    public function getAccessToken(string $key): ?string
    {
        return $this->read('token:' . $key)['val'] ?? null;
    }

    public function clearAccessToken(string $key): void
    {
        @unlink($this->path('token:' . $key));
    }
}
