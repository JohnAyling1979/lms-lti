<?php

namespace App;

use Packback\Lti1p3\Interfaces\IDatabase;
use Packback\Lti1p3\Interfaces\ILtiRegistration;
use Packback\Lti1p3\Interfaces\ILtiDeployment;
use Packback\Lti1p3\LtiRegistration;
use Packback\Lti1p3\LtiDeployment;

/**
 * Tells the LTI library which platforms this tool trusts.
 *
 * In production this is a real DB table (one row per LMS registration).
 * Here it's a single registration loaded from registration.json.
 */
class Database implements IDatabase
{
    private array $reg;

    public function __construct()
    {
        $this->reg = json_decode(file_get_contents(__DIR__ . '/../registration.json'), true);
    }

    public function findRegistrationByIssuer(string $iss, ?string $clientId = null): ?ILtiRegistration
    {
        if ($iss !== $this->reg['issuer']) {
            return null;
        }
        // client_id is null during OIDC login on some platforms; only reject on a real mismatch
        if ($clientId !== null && $clientId !== $this->reg['client_id']) {
            return null;
        }

        return LtiRegistration::new()
            ->setIssuer($this->reg['issuer'])
            ->setClientId($this->reg['client_id'])
            ->setAuthLoginUrl($this->reg['auth_login_url'])   // browser redirect target
            ->setAuthTokenUrl($this->reg['auth_token_url'])   // tool -> platform (server-side)
            ->setKeySetUrl($this->reg['key_set_url'])         // tool fetches platform JWKS (server-side)
            ->setKid($this->reg['tool_kid'])                  // THIS tool's key id
            ->setToolPrivateKey(file_get_contents(__DIR__ . '/../keys/private.key'));
    }

    public function findDeployment(string $iss, string $deploymentId, ?string $clientId = null): ?ILtiDeployment
    {
        if ($iss !== $this->reg['issuer'] || $deploymentId !== $this->reg['deployment_id']) {
            return null;
        }

        return LtiDeployment::new($deploymentId);
    }
}
