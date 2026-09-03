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

// A check may carry somewhere to go. Half of these are fixed on a page somewhere, and a URL beats a
// sentence describing where that page is — especially the ones on the instance, which is a different
// host and not one somebody has memorised.
function check($state, $what, $detail = '', $url = null) {
  return ['state' => $state, 'what' => $what, 'detail' => $detail, 'url' => $url];
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
        'Fine if SITE_URL and SECRET_KEY are set as environment variables; otherwise copy ' .
        'config.local.example.php and fill it in.');

  try {
    db();
    $missing = [];
    foreach (['settings', 'jobs', 'job_events', 'projects', 'webhook_log', 'sessions'] as $table) {
      if (!db_table_exists($table)) {
        $missing[] = $table;
      }
    }
    $out[] = $missing === []
      ? check('pass', 'Database is there', $cfg['db_file'])
      : check('fail', 'The database is incomplete',
          'Missing: ' . implode(', ', $missing) . '. It creates itself on first use, so this ' .
          'usually means a half-written file — delete it and reload any page.');
  } catch (Throwable $e) {
    $out[] = check('fail', 'Cannot open the database', $e->getMessage());
    // Nothing below this reads without a database, so there is no point asking.
    return $out;
  }

  // The file holds session ids and the password hash for these pages, so serving it hands whoever
  // downloads it a way in. Asked over the web from outside rather than reasoned about, because the
  // shipped .htaccess only works on Apache and only where AllowOverride permits — and "I assumed it
  // was denied" is exactly how this goes wrong.
  if ($deep) {
    $out[] = check_db_is_not_downloadable($cfg);
  }

  $out[] = secrets_available()
    ? check('pass', 'SECRET_KEY is set')
    : check('fail', 'SECRET_KEY is not set',
        'Neither the API key nor the webhook secret can be stored without it. Generate one with ' .
        'php -r "echo bin2hex(random_bytes(32));" and put it in config.local.php.');

  $out[] = setting('admin_password_hash') !== ''
    ? check('pass', 'These pages have a password')
    : check('fail', 'No password on these pages',
        'Anyone who can reach this address can read the API key and start an agent.',
        'settings.php');

  // --- who this machine works for ----------------------------------------------------------------
  if (instance_base() === '') {
    $out[] = check('fail', 'No instance configured',
      'Nothing knows which Beeblebrox this machine works for, so nothing else below can be asked.',
      'settings.php');
    return $out;
  }
  $out[] = check('pass', 'Instance configured', instance_base());

  // Reachability first, and independently of the key. /api/health wants no token, so "the address is
  // wrong" and "the key is wrong" stay two different answers instead of one confusing one.
  $reachable = true;
  if ($deep) {
    $health = upstream_health();
    $reachable = $health['ok'];
    $out[] = $health['ok']
      ? check('pass', 'Instance answers', 'GET /api/health')
      : check('fail', 'Instance does not answer', $health['error'], 'settings.php');
  }

  if (!setting_secret_is_set('api_key')) {
    $out[] = check('fail', 'No API key',
      'Issue one on the instance — sign in there as a company admin, API keys in the menu, New key, ' .
      'permission "task creator" — then paste it into the settings page here. It is shown once.',
      instance_base() . '/keys.php');
  } elseif ($deep && $reachable) {
    $who = upstream_whoami();
    if ($who['status'] === 404) {
      // The endpoint arrived with worker keys. A 404 means the instance is running a release older
      // than this worker, which is a deploy away from fixed — and nothing like "your key is wrong",
      // which is what the message below would otherwise say.
      $out[] = check('warn', 'This instance is older than this worker',
        'It has no /api/whoami, so it cannot say which worker this key belongs to and cannot filter ' .
        'a queue by worker either. Deploy the instance; nothing here needs changing.');
      $tasks = upstream_open_tasks();
      $out[] = $tasks['ok']
        ? check('pass', 'API key works',
            count($tasks['json']['tasks'] ?? []) . ' open task(s) it can see')
        : check('fail', 'API key refused', $tasks['error'], instance_base() . '/keys.php');
    } elseif (!$who['ok']) {
      $out[] = check('fail', 'API key refused', $who['error'] .
        ' — the key needs the task creator permission or better. Revoke it and issue another.',
        instance_base() . '/keys.php');
    } else {
      $worker = $who['json']['worker'] ?? null;
      $out[] = check('pass', 'API key works',
        'it is "' . ($who['json']['key']['name'] ?? '?') . '" on ' .
        ($who['json']['company'] ?? instance_base()));

      // The check that matters most on a machine that pulls. A key belonging to no worker is shown
      // every worker's queue, which on an instance with one worker looks identical to working
      // correctly and stops doing so the moment there are two.
      if ($worker === null) {
        $out[] = check('warn', 'This key belongs to no worker',
          'It is shown every task meant for a machine, including other workers\'. Issue a key that ' .
          'belongs to this one and the instance will hand it only its own work.',
          instance_base() . '/keys.php');
      } elseif (empty($worker['is_active'])) {
        $out[] = check('warn', 'This worker is paused on the instance',
          $worker['name'] . ' is deactivated there, so nothing will be dispatched to it and polling ' .
          'will find nothing. That is a switch, not a fault — turn it back on when you want work.',
          instance_base() . '/dispatchers.php');
      } else {
        $tasks = upstream_open_tasks();
        $out[] = check('pass', 'This machine is ' . $worker['name'],
          ($tasks['ok'] ? count($tasks['json']['tasks'] ?? []) : '?') .
          ' task(s) waiting for it right now. Which work that is, is decided on the instance.');
      }
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
      $ways[] = 'asking the instance for its work';
    }
    if ($hook) {
      $ways[] = 'webhooks at ' . rtrim($cfg['site_url'], '/') . '/hook.php';
    }
    $out[] = check('pass', 'Work can arrive', implode(', and ', $ways));
  }
  if ($hook && !setting_secret_is_set('webhook_secret')) {
    $out[] = check('fail', 'Webhooks are on with no signing secret',
      'Every envelope is refused until the secret here matches the one on the dispatcher. Set the ' .
      'same string in both places, or switch webhooks off and let polling do the work.',
      'settings.php');
  }

  // --- the runner --------------------------------------------------------------------------------
  $last = setting('last_pass_at');
  if ($last === '' || $last === null) {
    $out[] = check('warn', 'The runner has never run',
      'Nothing happens on its own until tools/run.php is on a schedule. See INSTALL.md.');
  } else {
    $minutes = intdiv(max(0, time() - strtotime($last)), 60);
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
      'Work that belongs to a project will stop and ask for a directory.', 'projects.php');
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
  $mins = intdiv(max(0, time() - strtotime($datetime)), 60);
  if ($mins < 1)    { return 'just now'; }
  if ($mins < 60)   { return $mins . 'm ago'; }
  if ($mins < 1440) { return intdiv($mins, 60) . 'h ago'; }
  return intdiv($mins, 1440) . 'd ago';
}

// Fetches the database file the way a stranger would. A 200 with SQLite's header at the front is the
// only answer that proves it is exposed; anything else — a 403, a 404, a redirect, no answer at all
// — means it is not reachable at that address, which is what was being asked.
//
// Only meaningful when the file is inside the served directory. Moved out of it, there is no URL to
// try and nothing to check.
function check_db_is_not_downloadable(array $cfg) {
  $root = realpath(__DIR__ . '/..');
  $file = realpath($cfg['db_file']);
  if ($file === false || $root === false || strncmp($file, $root, strlen($root)) !== 0) {
    return check('pass', 'The database is outside the served directory',
      'There is no URL that could reach it, which is the answer no later config change can undo.');
  }

  $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
  $url = rtrim($cfg['site_url'], '/') . '/' . $relative;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false,
    // 512 bytes is plenty: SQLite writes its magic string into the first 16.
    CURLOPT_RANGE          => '0-511',
  ]);
  $body = (string)curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($error !== '') {
    return check('warn', 'Could not check whether the database is downloadable',
      "Asking {$url} from here failed: {$error}. Worth confirming by hand — if that URL returns the " .
      'file, anybody who finds it has a signed-in session on this machine.');
  }
  if ($status >= 200 && $status < 300 && strncmp($body, 'SQLite format 3', 15) === 0) {
    return check('fail', 'The database can be downloaded',
      "{$url} returns the file. It holds session ids and the password hash for these pages. Deny " .
      'that path in your web server, or move the file out of the served directory with db_file.');
  }
  return check('pass', 'The database cannot be downloaded', "{$url} answers {$status}.");
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
