<?php
// A job is one task this machine was asked to do, from hearing about it to reporting it back.
//
// One row per upstream task, enforced by a unique key. A second delivery of the same task — a
// webhook retried after a timeout, or a poll that ran while a webhook was in flight — lands on the
// row that already exists rather than queueing the work twice. Claiming upstream is the half of that
// which holds across machines; this is the half that holds on this one.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function job_statuses() {
  return [
    'queued'    => 'waiting for the runner',
    'claimed'   => 'claimed, about to start',
    'running'   => 'the agent is working',
    'reporting' => 'finished, reporting back',
    'done'      => 'reported and accepted',
    'failed'    => 'reported as failed',
    'skipped'   => 'somebody else took it',
    'attention' => 'stopped — needs a person',
  ];
}

// Which of those a person should be looking at. Used to decide what the dashboard leads with.
function job_status_is_open($status) {
  return in_array($status, ['queued', 'claimed', 'running', 'reporting'], true);
}

function job_by_id($id) {
  return db_one('SELECT * FROM jobs WHERE id = ?', [(int)$id]);
}

function job_by_task($task_id) {
  return db_one('SELECT * FROM jobs WHERE upstream_task_id = ?', [(int)$task_id]);
}

// Accepts a task for this machine. Returns [job_id, 'created'|'existing'].
//
// A redelivery of something already known about is left exactly as it is — that is what stops a
// webhook retried after a timeout from starting the same work twice.
//
// A retry is the exception, and it has to be, because the instance retries by reusing the task id and
// bumping the attempt rather than making a new task. Refusing that because the id looks familiar
// would leave every retried task sitting open on the instance forever, with nothing saying why.
function job_accept(array $fields) {
  $task_id = (int)$fields['upstream_task_id'];
  $attempt = (int)($fields['attempt'] ?? 1);
  $existing = job_by_task($task_id);

  if ($existing) {
    if ($attempt <= (int)$existing['attempt'] || job_status_is_open($existing['status'])) {
      return [(int)$existing['id'], 'existing'];
    }
    // A new attempt of work that has already finished here. Everything the last attempt produced is
    // cleared, because leaving an old output on a job that is about to run again is how somebody
    // reads the wrong result.
    db_exec(
      "UPDATE jobs
          SET status = 'queued', attempt = ?, source = ?, envelope = ?, is_test = ?,
              output = NULL, communication = NULL, reported_status = NULL, result_json = NULL,
              error = NULL, exit_code = NULL, duration_ms = NULL,
              input_tokens = NULL, output_tokens = NULL, cost_microdollars = NULL,
              report_attempts = 0, report_next_at = NULL,
              received_at = NOW(), claimed_at = NULL, started_at = NULL, finished_at = NULL
        WHERE id = ?",
      [$attempt, $fields['source'],
       $fields['envelope'] ?? null, empty($fields['is_test']) ? 0 : 1, (int)$existing['id']]
    );
    job_log((int)$existing['id'], "Attempt {$attempt} of the same task. Queued again from " .
      $fields['source'] . '; what the last attempt produced is on the instance, not here.');
    return [(int)$existing['id'], 'created'];
  }

  $id = db_exec(
    'INSERT INTO jobs (source, upstream_task_id, upstream_chain_id, delivery_id, project_id,
                       role_slug, nickname, depth, attempt, is_test, envelope, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [
      $fields['source'],
      $task_id,
      isset($fields['upstream_chain_id']) ? (int)$fields['upstream_chain_id'] : null,
      isset($fields['delivery_id']) ? (int)$fields['delivery_id'] : null,
      isset($fields['project_id']) && $fields['project_id'] !== null ? (int)$fields['project_id'] : null,
      $fields['role_slug'] ?? null,
      $fields['nickname'] ?? null,
      (int)($fields['depth'] ?? 0),
      (int)($fields['attempt'] ?? 1),
      empty($fields['is_test']) ? 0 : 1,
      $fields['envelope'] ?? null,
      'queued',
    ]
  );
  job_log($id, 'Accepted from ' . $fields['source'] . '.');
  return [(int)$id, 'created'];
}

// Updates whatever it is given, and nothing else. Column names come from this file's own call sites,
// never from a request, which is what makes building the SET clause here safe.
function job_set($job_id, array $fields) {
  if (!$fields) {
    return;
  }
  $sets = [];
  $params = [];
  foreach ($fields as $column => $value) {
    $sets[] = "{$column} = ?";
    $params[] = $value;
  }
  $params[] = (int)$job_id;
  db_exec('UPDATE jobs SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
}

function job_log($job_id, $message, $level = 'info') {
  db_exec('INSERT INTO job_events (job_id, level, message) VALUES (?, ?, ?)',
    [(int)$job_id, $level, (string)$message]);
}

function job_events($job_id) {
  return db_all('SELECT * FROM job_events WHERE job_id = ? ORDER BY id', [(int)$job_id]);
}

// Stops a job and says why in words a person can act on. Nothing is reported upstream: the task stays
// claimed, which is exactly right — it will show as stale on the instance and whoever looks will find
// this sentence waiting.
function job_attention($job_id, $reason) {
  job_set($job_id, ['status' => 'attention', 'error' => $reason,
                    'finished_at' => date('Y-m-d H:i:s')]);
  job_log($job_id, $reason, 'warn');
}

function jobs_recent($limit = 25) {
  return db_all('SELECT * FROM jobs ORDER BY id DESC LIMIT ' . (int)$limit);
}

function jobs_by_status($status, $limit = 100) {
  return db_all('SELECT * FROM jobs WHERE status = ? ORDER BY id LIMIT ' . (int)$limit, [$status]);
}

function job_counts() {
  $counts = array_fill_keys(array_keys(job_statuses()), 0);
  foreach (db_all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $row) {
    $counts[$row['status']] = (int)$row['n'];
  }
  return $counts;
}

// Work waiting to be started, oldest first.
//
// 'claimed' is in here as well as 'queued'. A pass that claimed a task and then could not read the
// ticket — the instance restarting mid-request is the usual way — leaves the job claimed, and a
// queue that only looks at 'queued' would never pick it up again. The claim step knows to skip
// itself when the job already holds one.
function jobs_ready() {
  return db_all("SELECT * FROM jobs WHERE status IN ('queued','claimed') ORDER BY id");
}

// Jobs whose agent was running when something took the runner down — a reboot, a killed terminal, a
// crash. Nothing is going to finish them, and without this they sit as 'running' forever while the
// dashboard cheerfully reports work in flight.
//
// Generous slack on the timeout, because the alternative failure is worse: declaring a run dead while
// it is still going would report a task that then finishes and reports again.
function jobs_stuck() {
  $limit = max(60, setting_int('agent_timeout_seconds', 3600)) + 300;
  return db_all(
    "SELECT * FROM jobs
      WHERE status = 'running' AND started_at IS NOT NULL
        AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
      ORDER BY id",
    [$limit]
  );
}

// Work that ran and has not been handed back yet. Retried on its own schedule and ahead of anything
// new, because the agent's output is the expensive thing here and losing it to a restart on the
// instance would be the worst failure this thing has.
function jobs_awaiting_report() {
  return db_all(
    "SELECT * FROM jobs
      WHERE status = 'reporting'
        AND (report_next_at IS NULL OR report_next_at <= NOW())
      ORDER BY id"
  );
}

// A minute, five, twenty-five, then hourly. Fixed in code because the useful knob is how many
// attempts, not the shape of the curve.
function job_report_backoff($attempt) {
  $schedule = [1 => 60, 2 => 300, 3 => 1500];
  return $schedule[$attempt] ?? 3600;
}

// The local project mapping for an upstream project, or null when there is none.
function project_for_upstream($upstream_project_id) {
  if ($upstream_project_id === null) {
    return null;
  }
  return db_one('SELECT * FROM projects WHERE upstream_project_id = ? AND is_active = 1',
    [(int)$upstream_project_id]);
}

function projects_all() {
  return db_all('SELECT * FROM projects ORDER BY is_active DESC, name');
}
