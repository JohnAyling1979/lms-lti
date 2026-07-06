-- Tool system-of-record (durable). This is the TOOL's database, separate from
-- Moodle's — the Platform and the Tool are different systems. Runs once, when the
-- tool-db volume is first initialised (drop the volume to re-run).

USE tool;

-- Platforms (LMSs) this tool trusts — one row per registration. Was a flat
-- registration.json; now the runtime source of truth (Dynamic Registration will
-- INSERT here). The tool's PRIVATE KEY stays out of the DB (in keys/, a secret
-- store) — the row only references it by tool_kid.
CREATE TABLE IF NOT EXISTS registrations (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  issuer         VARCHAR(255) NOT NULL,   -- the platform's iss
  client_id      VARCHAR(255) NOT NULL,   -- this tool's id on that platform
  deployment_id  VARCHAR(255) NOT NULL,   -- (lab: one deployment per registration)
  auth_login_url VARCHAR(512) NOT NULL,   -- browser-facing OIDC auth endpoint
  auth_token_url VARCHAR(512) NOT NULL,   -- server-side OAuth2 token endpoint
  key_set_url    VARCHAR(512) NOT NULL,   -- server-side: platform's JWKS
  tool_kid       VARCHAR(255) NOT NULL,   -- which of THIS tool's keys signs for this platform
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_iss_client (issuer, client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submissions (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  submission_key   CHAR(64)     NOT NULL,   -- sha256(issuer | resource_link_id | user_sub)
  issuer           VARCHAR(255) NOT NULL,
  resource_link_id VARCHAR(255) NOT NULL,   -- the LMS placement this work belongs to
  user_sub         VARCHAR(255) NOT NULL,   -- the LTI subject (learner)
  content          MEDIUMTEXT   NOT NULL,   -- the work itself; the LMS never sees this
  submitted_at     VARCHAR(40)  NOT NULL,   -- ISO-8601 (keeps the exact instant + offset)
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_submission_key (submission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
