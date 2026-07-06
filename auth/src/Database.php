<?php

namespace App;

use Packback\Lti1p3\Interfaces\IDatabase;
use Packback\Lti1p3\Interfaces\ILtiRegistration;
use Packback\Lti1p3\Interfaces\ILtiDeployment;
use Packback\Lti1p3\LtiRegistration;
use Packback\Lti1p3\LtiDeployment;

/**
 * Tells the LTI library which platforms this tool trusts — one row per LMS in
 * the tool's DB (`registrations`). Seeded from registration.json via bin/seed.php;
 * later, Dynamic Registration writes rows here directly.
 *
 * The tool's private key is NOT stored in the row — it's a signing secret kept
 * in keys/ and attached here by kid.
 */
class Database implements IDatabase
{
    public function findRegistrationByIssuer(string $iss, ?string $clientId = null): ?ILtiRegistration
    {
        $sql = 'SELECT * FROM registrations WHERE issuer = :iss';
        $params = [':iss' => $iss];
        // client_id is null during OIDC login on some platforms; only filter on a real value.
        if ($clientId !== null) {
            $sql .= ' AND client_id = :cid';
            $params[':cid'] = $clientId;
        }
        $sql .= ' LIMIT 1';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return LtiRegistration::new()
            ->setIssuer($r['issuer'])
            ->setClientId($r['client_id'])
            ->setAuthLoginUrl($r['auth_login_url'])   // browser redirect target
            ->setAuthTokenUrl($r['auth_token_url'])   // tool -> platform (server-side)
            ->setKeySetUrl($r['key_set_url'])         // tool fetches platform JWKS (server-side)
            ->setKid($r['tool_kid'])                  // THIS tool's key id
            ->setToolPrivateKey(file_get_contents(__DIR__ . '/../keys/private.key'));
    }

    public function findDeployment(string $iss, string $deploymentId, ?string $clientId = null): ?ILtiDeployment
    {
        $stmt = Db::pdo()->prepare(
            'SELECT deployment_id FROM registrations WHERE issuer = :iss AND deployment_id = :dep LIMIT 1'
        );
        $stmt->execute([':iss' => $iss, ':dep' => $deploymentId]);

        return $stmt->fetch() === false ? null : LtiDeployment::new($deploymentId);
    }
}
