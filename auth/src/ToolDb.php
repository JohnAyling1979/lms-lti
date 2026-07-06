<?php

namespace App;

use PDO;

/**
 * The tool's own database (system of record). Durable domain data lives here —
 * currently learner submissions, later registrations and (iss,sub)->user mappings.
 *
 * Deliberately NOT Redis (that's for ephemeral launch state) and NOT Moodle's DB
 * (that's the Platform's — a different system). In prod this is PowerNotes' DB.
 */
class ToolDb
{
    private PDO $pdo;

    public function __construct()
    {
        $host = getenv('TOOL_DB_HOST') ?: 'tool-db';
        $name = getenv('TOOL_DB_NAME') ?: 'tool';
        $user = getenv('TOOL_DB_USER') ?: 'tool';
        $pass = getenv('TOOL_DB_PASS') ?: 'tool';

        $this->pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /** Upsert a learner's work for one placement (idempotent on submission_key). */
    public function putSubmission(string $key, array $identity, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO submissions
                (submission_key, issuer, resource_link_id, user_sub, content, submitted_at)
             VALUES (:k, :iss, :rl, :sub, :content, :at)
             ON DUPLICATE KEY UPDATE
                content = VALUES(content), submitted_at = VALUES(submitted_at)'
        );
        $stmt->execute([
            ':k' => $key,
            ':iss' => $identity['iss'] ?? '',
            ':rl' => $identity['resourceLink']['id'] ?? '',
            ':sub' => $identity['sub'] ?? '',
            ':content' => $data['content'] ?? '',
            ':at' => $data['submittedAt'] ?? '',
        ]);
    }

    /** The learner's saved work for one placement, or null if they've not submitted. */
    public function getSubmission(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT content, submitted_at AS submittedAt FROM submissions WHERE submission_key = :k'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every learner submission for one placement — for the instructor view.
     * Joined to the roster (by userId = LTI sub) in the UI. The instructor gets
     * the resource_link_id from the line item's resourceLinkId (AGS getLineItems).
     */
    public function getSubmissionsByResourceLink(string $resourceLinkId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_sub AS userId, content, submitted_at AS submittedAt
             FROM submissions WHERE resource_link_id = :rl'
        );
        $stmt->execute([':rl' => $resourceLinkId]);

        return $stmt->fetchAll();
    }
}
