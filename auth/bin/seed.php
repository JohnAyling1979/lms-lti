<?php

/**
 * Seed the registrations table from registration.json (idempotent upsert).
 * Run after editing registration.json:
 *
 *   docker compose exec auth php bin/seed.php
 *
 * registration.json is the human-editable setup input; the DB is the runtime
 * source of truth. (Dynamic Registration will later write rows here directly.)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Db;

$reg = json_decode(file_get_contents(__DIR__ . '/../registration.json'), true);
if (!is_array($reg)) {
    fwrite(STDERR, "cannot read registration.json\n");
    exit(1);
}

$stmt = Db::pdo()->prepare(
    'INSERT INTO registrations
        (issuer, client_id, deployment_id, auth_login_url, auth_token_url, key_set_url, tool_kid)
     VALUES (:iss, :cid, :dep, :login, :token, :jwks, :kid)
     ON DUPLICATE KEY UPDATE
        deployment_id  = VALUES(deployment_id),
        auth_login_url = VALUES(auth_login_url),
        auth_token_url = VALUES(auth_token_url),
        key_set_url    = VALUES(key_set_url),
        tool_kid       = VALUES(tool_kid)'
);
$stmt->execute([
    ':iss' => $reg['issuer'],
    ':cid' => $reg['client_id'],
    ':dep' => $reg['deployment_id'],
    ':login' => $reg['auth_login_url'],
    ':token' => $reg['auth_token_url'],
    ':jwks' => $reg['key_set_url'],
    ':kid' => $reg['tool_kid'],
]);

echo "seeded registration: issuer={$reg['issuer']} client_id={$reg['client_id']}\n";
