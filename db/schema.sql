-- beeblebrox-local — the whole schema, with every migration folded in.
--
-- A fresh install loads this file alone and is then current; tools/migrate.php records the
-- migrations as already applied so it never tries to run them over the top.
--
-- Written for MySQL 8 and MariaDB 10.4 alike, which rules out a few conveniences: no functional
-- defaults beyond CURRENT_TIMESTAMP, no JSON type (LONGTEXT, because MariaDB's JSON is an alias for
-- it anyway and the difference would only bite on a customer's other server).

SET NAMES utf8mb4;

-- Which migration files have run. Loading schema.sql seeds this with all of them.
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything a person configures. Kept here rather than in config.local.php because a customer
-- setting this up should be able to do it on a page, and because the API key and the webhook secret
-- have to be changeable without an editor and a file permission argument.
--
-- is_secret marks a value stored encrypted under SECRET_KEY. The settings page shows those as
-- "set" or "not set" and never reads one back, which is why an empty submission means "leave it
-- alone" rather than "clear it".
CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(64) NOT NULL PRIMARY KEY,
  value      LONGTEXT NULL,
  is_secret  TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where an upstream project's work happens on this machine. The envelope names a project id; only
-- this table knows that project 7 is a checkout in C:\work\invoicing.
--
-- A project with no row here is not guessed at. Running an agent in the wrong directory is the one
-- mistake that is expensive to undo, so an unmapped project stops and asks.
CREATE TABLE IF NOT EXISTS projects (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  upstream_project_id INT UNSIGNED NOT NULL,
  name                VARCHAR(190) NOT NULL,
  workspace_path      VARCHAR(500) NOT NULL,
  -- Run in the workspace before the agent starts, so a run begins from a known state. Empty means
  -- the workspace is taken as it is found, which is what you want while you are debugging one.
  prepare_command     LONGTEXT NULL,
  -- Overrides the instance-wide default. Null means use the role's model, then the default.
  model               VARCHAR(100) NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_projects_upstream (upstream_project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per task this machine has been asked to do, however it heard about it.
--
-- The unique key on upstream_task_id is what makes a replayed envelope harmless: the second delivery
-- of the same task updates a row that already exists instead of queueing a second run. Claiming
-- upstream is the other half of that, and the one that holds across machines.
CREATE TABLE IF NOT EXISTS jobs (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  -- 'webhook' when the platform pushed it here, 'poll' when this machine went looking, 'manual' when
  -- somebody typed a task id into the dashboard.
  source             VARCHAR(16) NOT NULL,
  upstream_task_id   INT UNSIGNED NOT NULL,
  upstream_chain_id  INT UNSIGNED NULL,
  delivery_id        INT UNSIGNED NULL,
  project_id         INT UNSIGNED NULL,
  role_slug          VARCHAR(64) NULL,
  nickname           VARCHAR(100) NULL,
  depth              INT NOT NULL DEFAULT 0,
  attempt            INT NOT NULL DEFAULT 1,
  is_test            TINYINT(1) NOT NULL DEFAULT 0,

  -- queued      accepted, nothing done yet
  -- claimed     claimed upstream, ours to run
  -- running     the agent is in the workspace right now
  -- reporting   the agent finished and the result is on its way back
  -- done        reported and acknowledged
  -- failed      reported upstream as a failure
  -- skipped     somebody else claimed it, or it was no longer open
  -- attention   stopped before running, and a person has to look: no workspace, no result, a status
  --             the flow does not accept
  status             VARCHAR(16) NOT NULL DEFAULT 'queued',

  envelope           LONGTEXT NULL,
  workspace_path     VARCHAR(500) NULL,
  job_dir            VARCHAR(500) NULL,

  -- What the agent said, and what we told the platform.
  output             LONGTEXT NULL,
  communication      LONGTEXT NULL,
  reported_status    VARCHAR(64) NULL,
  result_json        LONGTEXT NULL,

  exit_code          INT NULL,
  model              VARCHAR(100) NULL,
  input_tokens       INT UNSIGNED NULL,
  output_tokens      INT UNSIGNED NULL,
  cost_microdollars  BIGINT UNSIGNED NULL,
  duration_ms        INT UNSIGNED NULL,

  error              LONGTEXT NULL,

  -- Reporting is retried on its own schedule. The agent's work is the expensive part; losing it
  -- because the instance was restarting when we tried to hand it back would be the worst failure
  -- this thing has, so it is the one that retries hardest.
  report_attempts    INT NOT NULL DEFAULT 0,
  report_next_at     DATETIME NULL,

  received_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at         DATETIME NULL,
  started_at         DATETIME NULL,
  finished_at        DATETIME NULL,

  UNIQUE KEY uk_jobs_task (upstream_task_id),
  KEY idx_jobs_status (status, id),
  KEY idx_jobs_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The story of one job, in order. This is what the job page shows and what you read when a run did
-- something you did not expect.
CREATE TABLE IF NOT EXISTS job_events (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id     INT UNSIGNED NOT NULL,
  level      VARCHAR(8) NOT NULL DEFAULT 'info',
  message    LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_job_events_job (job_id, id),
  CONSTRAINT fk_job_events_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every envelope that arrived, accepted or refused, with the reason. Kept separately from jobs
-- because the interesting ones are the refusals, and those never become a job.
CREATE TABLE IF NOT EXISTS webhook_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  remote_addr VARCHAR(64) NULL,
  accepted    TINYINT(1) NOT NULL DEFAULT 0,
  reason      VARCHAR(190) NULL,
  task_id     INT UNSIGNED NULL,
  body        LONGTEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_webhook_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions for the local UI. In the database rather than in files so that clearing PHP's temp
-- directory does not sign you out, and so a second checkout on the same machine keeps its own.
CREATE TABLE IF NOT EXISTS sessions (
  id          VARCHAR(128) NOT NULL,
  payload     MEDIUMTEXT NOT NULL,
  last_active INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_last_active (last_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

