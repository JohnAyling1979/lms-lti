-- Tool system-of-record (durable). This is the TOOL's database, separate from
-- Moodle's — the Platform and the Tool are different systems. Runs once, when the
-- tool-db volume is first initialised (drop the volume to re-run).

USE tool;

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
