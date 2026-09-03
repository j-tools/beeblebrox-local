-- beeblebrox-local — the whole schema, with every migration folded in.
--
-- Loaded automatically the first time anything opens the database, so a fresh install has nothing to
-- run and nothing to import. tools/migrate.php records the migrations as already applied at the same
-- time, so it never tries to run one over the top of a schema that already has it.
--
-- SQLite. Every table here belongs to one machine and one person, so the interesting question is not
-- how it scales but how little there is to install: the answer is a file.
--
-- Timestamps are local time, either written by PHP or defaulted with datetime('now','localtime').
-- SQLite's bare CURRENT_TIMESTAMP is UTC, and mixing the two would put a job log two hours out for
-- anybody east of London.

-- Which migration files have run. Loading this file seeds it with all of them.
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   TEXT NOT NULL PRIMARY KEY,
  applied_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Everything a person configures. Kept here rather than in config.local.php because a customer
-- setting this up should be able to do it on a page, and because the API key and the webhook secret
-- have to be changeable without an editor and a file permission argument.
--
-- is_secret marks a value stored encrypted under SECRET_KEY. The settings page shows those as "set"
-- or "not set" and never reads one back, which is why an empty submission means "leave it alone"
-- rather than "clear it".
CREATE TABLE IF NOT EXISTS settings (
  name       TEXT NOT NULL PRIMARY KEY,
  value      TEXT,
  is_secret  INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Where an upstream project's work happens on this machine. The instance names a project id; only
-- this table knows that project 7 is a checkout in C:\work\invoicing.
--
-- A project with no row here is not guessed at. Running an agent in the wrong directory is the one
-- mistake that is expensive to undo, so an unmapped project stops and asks.
CREATE TABLE IF NOT EXISTS projects (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  upstream_project_id INTEGER NOT NULL UNIQUE,
  name                TEXT NOT NULL,
  workspace_path      TEXT NOT NULL,
  -- Run in the workspace before the agent starts, so a run begins from a known state. Empty means
  -- the workspace is taken as it is found, which is what you want while you are debugging one.
  prepare_command     TEXT,
  -- Overrides the instance-wide default. Null means use the role's model, then the default.
  model               TEXT,
  is_active           INTEGER NOT NULL DEFAULT 1,
  created_at          TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- One row per task this machine was asked to do, however it heard about it.
--
-- The unique key on upstream_task_id is what makes a replayed envelope harmless: the second delivery
-- of the same task lands on the row that already exists instead of queueing a second run. Claiming
-- upstream is the other half of that, and the one that holds across machines.
CREATE TABLE IF NOT EXISTS jobs (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,

  -- 'webhook' when the instance pushed it here, 'poll' when this machine went looking, 'manual' when
  -- somebody typed a task id into the dashboard.
  source             TEXT NOT NULL,
  upstream_task_id   INTEGER NOT NULL UNIQUE,
  upstream_chain_id  INTEGER,
  delivery_id        INTEGER,
  project_id         INTEGER,
  role_slug          TEXT,
  nickname           TEXT,
  depth              INTEGER NOT NULL DEFAULT 0,
  attempt            INTEGER NOT NULL DEFAULT 1,
  is_test            INTEGER NOT NULL DEFAULT 0,

  -- queued      accepted, nothing done yet
  -- claimed     claimed upstream, ours to run
  -- running     the agent is in the workspace right now
  -- reporting   the agent finished and the result is on its way back
  -- done        reported and acknowledged
  -- failed      reported upstream as a failure
  -- skipped     somebody else claimed it, or it was no longer open
  -- attention   stopped before running, and a person has to look: no workspace, no result, a status
  --             the flow does not accept
  status             TEXT NOT NULL DEFAULT 'queued',

  envelope           TEXT,
  workspace_path     TEXT,
  job_dir            TEXT,

  -- What the agent said, and what we told the instance.
  output             TEXT,
  communication      TEXT,
  reported_status    TEXT,
  result_json        TEXT,

  exit_code          INTEGER,
  model              TEXT,
  input_tokens       INTEGER,
  output_tokens      INTEGER,
  cost_microdollars  INTEGER,
  duration_ms        INTEGER,

  error              TEXT,

  -- Reporting is retried on its own schedule. The agent's work is the expensive part; losing it
  -- because the instance was restarting when we tried to hand it back would be the worst failure
  -- this thing has, so it is the one that retries hardest.
  report_attempts    INTEGER NOT NULL DEFAULT 0,
  report_next_at     TEXT,

  received_at        TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  claimed_at         TEXT,
  started_at         TEXT,
  finished_at        TEXT
);

CREATE INDEX IF NOT EXISTS idx_jobs_status   ON jobs (status, id);
CREATE INDEX IF NOT EXISTS idx_jobs_received ON jobs (received_at);

-- The story of one job, in order. This is what the job page shows and what you read when a run did
-- something you did not expect.
CREATE TABLE IF NOT EXISTS job_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id     INTEGER NOT NULL REFERENCES jobs (id) ON DELETE CASCADE,
  level      TEXT NOT NULL DEFAULT 'info',
  message    TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS idx_job_events_job ON job_events (job_id, id);

-- Every envelope that arrived, accepted or refused, with the reason. Kept separately from jobs
-- because the interesting ones are the refusals, and those never become a job.
CREATE TABLE IF NOT EXISTS webhook_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  remote_addr TEXT,
  accepted    INTEGER NOT NULL DEFAULT 0,
  reason      TEXT,
  task_id     INTEGER,
  body        TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS idx_webhook_log_created ON webhook_log (created_at);

-- Sessions for the local pages. In the database rather than in PHP's temp directory so that clearing
-- that directory does not sign you out, and so two checkouts on one machine keep their own.
CREATE TABLE IF NOT EXISTS sessions (
  id          TEXT NOT NULL PRIMARY KEY,
  payload     TEXT NOT NULL,
  last_active INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_last_active ON sessions (last_active);
