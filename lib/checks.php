<?php
// Every question worth asking about whether this machine is actually set up, asked in one place so
// that the diagnostics page and tools/selftest.php cannot give different answers.
//
// Three outcomes, and the middle one earns its keep: 'warn' is for something that is not wrong yet
// but will be the reason nothing works later — a runner that has not run for an hour, a workspace
// that has gone missing, a permission mode that lets the agent edit but not build.

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/upstream.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/agent.php';

function check($state, $what, $detail = '') {
  return ['state' => $state, 'what' => $what, 'detail' => $detail];
}

// $deep runs the checks that cost a network round trip or start a process. The dashboard skips them;
// the diagnostics page and the CLI do not.
function checks_run($deep = true) {
  $out = [];
  $cfg = bbl_config();

  // --- the things that have to exist before anything else can ------------------------------------
  $out[] = file_exists(__DIR__ . '/../config.local.php')
    ? check('pass', 'config.local.php is present')
    : check('warn', 'config.local.php is missing',
        'Fine if DB_HOST and the rest are set as environment variables; otherwise copy ' .
        'config.local.example.php and fill it in.');

  try {
    db();
    $out[] = check('pass', 'Database reachable', $cfg['db_name'] . ' on ' . $cfg['db_host']);
    $tables = db_count("SELECT COUNT(*) FROM information_schema.tables
                         WHERE table_schema = ? AND table_name IN
                         ('settings','jobs','job_events','projects','webhook_log','sessions')",
      [$cfg['db_name']]);
    $out[] = $tables === 6
      ? check('pass', 'Schema is loaded')
      : check('fail', 'Schema is incomplete',
          "{$tables} of 6 tables found. Load db/schema.sql, then run tools/migrate.php.");
  } catch (Throwable $e) {
    $out[] = check('fail', 'Database unreachable', $e->getMessage());
    // Nothing below this reads without a database, so there is no point asking.
    return $out;
  }

  $out[] = secrets_available()
    ? check('pass', 'SECRET_KEY is set')
    : check('fail', 'SECRET_KEY is not set',
        'Neither the API key nor the webhook secret can be stored without it. Generate one with ' .
        'php -r "echo bin2hex(random_bytes(32));" and put it in config.local.php.');

  $out[] = setting('admin_password_hash') !== ''
    ? check('pass', 'These pages have a password')
    : check('fail', 'No password on these pages',
        'Anyone who can reach this address can read the API key and start an agent. Set one on the ' .
        'settings page.');

  // --- who this machine works for ----------------------------------------------------------------
  if (instance_base() === '') {
    $out[] = check('fail', 'No instance configured',
      'Set the Beeblebrox instance URL on the settings page — nothing knows where to report to.');
    return $out;
  }
  $out[] = check('pass', 'Instance configured', instance_base());

  if (!setting_secret_is_set('api_key')) {
    $out[] = check('fail', 'No API key',
      'Mint one on the instance with: php tools/api-key.php create "this machine" task_creator');
  } elseif ($deep) {
    $health = upstream_health();
    $out[] = $health['ok']
      ? check('pass', 'Instance answers', 'GET /api/health')
      : check('fail', 'Instance does not answer', $health['error']);

    if ($health['ok']) {
      $tasks = upstream_open_tasks('any');
      $out[] = $tasks['ok']
        ? check('pass', 'API key works',
            count($tasks['json']['tasks'] ?? []) . ' task(s) open on the instance right now')
        : check('fail', 'API key refused', $tasks['error'] .
            ' — the key needs the task_creator permission or better.');
    }
  } else {
    $out[] = check('pass', 'API key is stored');
  }

  // --- how work arrives --------------------------------------------------------------------------
  $poll = setting_bool('poll_enabled');
  $hook = setting_bool('accept_webhooks');
  if (!$poll && !$hook) {
    $out[] = check('fail', 'No way in',
      'Polling is off and webhooks are off, so no work can ever reach this machine.');
  } else {
    $ways = [];
    if ($poll) {
      $roles = trim((string)setting('poll_roles'));
      $ways[] = 'polling for ' . ($roles === '' ? 'any role' : $roles);
    }
    if ($hook) {
      $ways[] = 'webhooks at ' . rtrim($cfg['site_url'], '/') . '/hook.php';
    }
    $out[] = check('pass', 'Work can arrive', implode(', and ', $ways));
  }
  if ($hook && !setting_secret_is_set('webhook_secret')) {
    $out[] = check('fail', 'Webhooks are on with no signing secret',
      'Every envelope is refused until the secret here matches the one on the dispatcher.');
  }

  // --- the runner --------------------------------------------------------------------------------
  $last = setting('last_pass_at');
  if ($last === '' || $last === null) {
    $out[] = check('warn', 'The runner has never run',
      'Nothing happens on its own until tools/run.php is on a schedule. See INSTALL.md.');
  } else {
    $minutes = (int)db_one('SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) AS m', [$last])['m'];
    $out[] = $minutes <= 10
      ? check('pass', 'The runner is running', 'last pass ' . view_ago_safe($last))
      : check('warn', 'The runner has not run recently',
          'Last pass was ' . view_ago_safe($last) . '. Check the scheduled task.');
  }

  // --- where the work happens --------------------------------------------------------------------
  $job_root = $cfg['job_root'];
  if (!is_dir($job_root)) {
    @mkdir($job_root, 0775, true);
  }
  $out[] = is_dir($job_root) && is_writable($job_root)
    ? check('pass', 'Job directory is writable', $job_root)
    : check('fail', 'Job directory is not writable', $job_root .
        ' — the prompt, the output and the result file all go here.');

  $projects = projects_all();
  if (!$projects) {
    $out[] = check('warn', 'No projects mapped',
      'Work that belongs to a project will stop and ask for a directory. Map them on the projects page.');
  } else {
    foreach ($projects as $project) {
      if (!(int)$project['is_active']) {
        continue;
      }
      $out[] = is_dir($project['workspace_path'])
        ? check('pass', 'Workspace for ' . $project['name'], $project['workspace_path'])
        : check('fail', 'Workspace for ' . $project['name'] . ' is missing',
            $project['workspace_path'] . ' is not a directory.');
    }
  }

  // --- the agent ---------------------------------------------------------------------------------
  $argv = command_split((string)setting('agent_command'));
  if (!$argv) {
    $out[] = check('fail', 'No agent command', 'Set it on the settings page.');
  } else {
    if ($deep) {
      $probe = agent_execute([$argv[0], '--version'], $job_root, '', 30);
      $out[] = $probe['exit_code'] === 0
        ? check('pass', 'Agent runs', $argv[0] . ' — ' . trim(mb_strimwidth($probe['stdout'], 0, 80, '…')))
        : check('warn', 'Could not get a version out of the agent',
            $argv[0] . ' did not answer --version. That is only a problem if it is also not on PATH ' .
            'for the account the scheduled task runs as.');
    }
    // Said as a warning rather than a fact because it is the single most common reason a run does
    // nothing useful, and it is invisible: a denied tool call does not stop the agent, it just
    // quietly does not happen.
    $mode = '';
    foreach ($argv as $i => $arg) {
      if ($arg === '--permission-mode' && isset($argv[$i + 1])) {
        $mode = $argv[$i + 1];
      }
    }
    if ($mode !== '' && $mode !== 'bypassPermissions') {
      $out[] = check('warn', "The agent runs with --permission-mode {$mode}",
        'Anything it is not allowed to do is silently skipped rather than refused, so a role that ' .
        'has to run builds or tests will report success having done neither. Use ' .
        'bypassPermissions once you trust the workspace it runs in.');
    }
  }

  return $out;
}

// view_ago() lives in the view layer, which the CLI does not load. Same answer, no dependency.
function view_ago_safe($datetime) {
  $mins = (int)db_one('SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) AS m', [$datetime])['m'];
  if ($mins < 1)    { return 'just now'; }
  if ($mins < 60)   { return $mins . 'm ago'; }
  if ($mins < 1440) { return intdiv($mins, 60) . 'h ago'; }
  return intdiv($mins, 1440) . 'd ago';
}

function checks_worst(array $checks) {
  foreach (['fail', 'warn'] as $state) {
    foreach ($checks as $check) {
      if ($check['state'] === $state) {
        return $state;
      }
    }
  }
  return 'pass';
}
