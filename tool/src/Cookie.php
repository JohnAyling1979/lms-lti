<?php

namespace App;

use Packback\Lti1p3\Interfaces\ICookie;

/**
 * The launch is a CROSS-SITE POST from the platform (https://localhost) to this
 * tool (https://tool.localhost). For the state cookie set during OIDC login to
 * be sent back on that POST, it MUST be SameSite=None; Secure. This is the single
 * most common reason LTI launches fail ("state not found").
 */
class Cookie implements ICookie
{
    public function getCookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    public function setCookie(string $name, string $value, int $exp = 3600, array $options = []): void
    {
        $options = array_merge([
            'expires' => time() + $exp,
            'path' => '/',
            'secure' => true,       // required with SameSite=None
            'httponly' => true,
            'samesite' => 'None',   // allow the cross-site launch POST to carry it
        ], $options);

        setcookie($name, $value, $options);
        $_COOKIE[$name] = $value;
    }
}
