<?php
// The runner: what actually happens between a task arriving and its result going back.
//
// Ordering here is not arbitrary. Reporting comes first on every pass, ahead of anything new, because
// an agent run is the expensive part and work that has been done but not handed back is the only
// state this thing holds that cannot be recreated. Polling comes next, and starting a new job last.
//
// One agent at a time, held by a lock file. That matches the platform's own invariant — a project has
// one active task — and it means the scheduled task can fire every minute against a run that has been
// going for half an hour without either of them having to know about the other.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/upstream.php';
require_once __DIR__ . '/agent.php';

// A failure that will probably not still be a failure in a minute. Anything else means asking again
// is pointless: the token is wrong, the task is gone, the instance refused the shape of the request.
function runner_transient(array $result) {
  return $result['status'] === 0 || $result['status'] >= 500 || $result['status'] === 429;
}

// Only one runner works at a time. Returns a handle to hold, or null when another has it.
function runner_lock() {
  $path = bbl_config()['job_root'] . '/runner.lock';
  @mkdir(dirname($path), 0775, true);
  $handle = @fopen($path, 'c');
  if (!$handle) {
    throw new RuntimeException("Could not open the lock file at {$path}. Is JOB_ROOT writable?");
  }
  if (!flock($handle, LOCK_EX | LOCK_NB)) {
    fclose($handle);
    return null;
  }
  ftruncate($handle, 0);
  fwrite($handle, (string)getmypid());
  fflush($handle);
  return $handle;
}

function runner_unlock($handle) {
  if ($handle) {
    flock($handle, LOCK_UN);
    fclose($handle);
  }
}

// =====================================================================================
// Hearing about work
// =====================================================================================

// Asks the instance what is open and takes what this machine is configured to work.
//
// This is the path that needs no inbound networking at all, which is why it is on by default: a
// laptop behind a router cannot receive a webhook without a tunnel, and asking costs one request.
function runner_poll(array &$log) {
  if (!setting_bool('poll_enabled')) {
    return 0;
  }
  $roles = preg_split('/[\s,]+/', (string)setting('poll_roles'), -1, PREG_SPLIT_NO_EMPTY);
  $dispatch = (string)setting('poll_dispatch');
  $accepted = 0;

  // One request per configured role, because the API filters by a single role. No roles configured
  // means one request for everything, which is the "I am the only worker" case.
  foreach ($roles ?: [null] as $role) {
    $result = upstream_open_tasks($dispatch, $role);
    if (!$result['ok']) {
      $log[] = 'poll: ' . $result['error'];
      continue;
    }
    foreach ($result['json']['tasks'] ?? [] as $task) {
      [$job_id, $how] = job_accept([
        'source'            => 'poll',
        'upstream_task_id'  => (int)$task['id'],
        'upstream_chain_id' => isset($task['chain_id']) ? (int)$task['chain_id'] : null,
        'project_id'        => $task['project_id'] ?? null,
        'role_slug'         => $task['role_slug'] ?? null,
        'depth'             => (int)($task['depth'] ?? 0),
        'attempt'           => (int)($task['attempt'] ?? 1),
        'is_test'           => !empty($task['is_test']),
        'envelope'          => json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      ]);
      if ($how === 'created') {
        $accepted++;
        $log[] = "poll: took task {$task['id']} ({$task['role_slug']}) as job {$job_id}";
      }
    }
  }
  return $accepted;
}

// =====================================================================================
// Doing it
// =====================================================================================

// Claims the task, does the work, and leaves the job ready to report. Returns true when an agent
// actually ran, so the caller can count how much it did.
function runner_execute(array $job, array &$log) {
  $job_id = (int)$job['id'];
  $task_id = (int)$job['upstream_task_id'];

  // --- claim -------------------------------------------------------------------------------------
  if ($job['status'] === 'queued') {
    $claim = upstream_claim($task_id);
    if ($claim['status'] === 409) {
      job_set($job_id, ['status' => 'skipped', 'finished_at' => date('Y-m-d H:i:s'),
                        'error' => 'Already claimed on the instance.']);
      job_log($job_id, 'Already claimed on the instance — somebody else is doing it.');
      $log[] = "job {$job_id}: already claimed, skipped";
      return false;
    }
    if (!$claim['ok']) {
      if (runner_transient($claim)) {
        job_log($job_id, 'Could not claim yet: ' . $claim['error'], 'warn');
        $log[] = "job {$job_id}: claim deferred — " . $claim['error'];
        return false;
      }
      job_attention($job_id, 'The instance refused the claim: ' . $claim['error']);
      $log[] = "job {$job_id}: claim refused — " . $claim['error'];
      return false;
    }
    job_set($job_id, ['status' => 'claimed', 'claimed_at' => date('Y-m-d H:i:s')]);
    job_log($job_id, 'Claimed on the instance.');
    $job['status'] = 'claimed';
  }

  // --- read the ticket ---------------------------------------------------------------------------
  $context_result = upstream_task_context($task_id);
  if (!$context_result['ok']) {
    if (runner_transient($context_result)) {
      job_log($job_id, 'Could not read the task yet: ' . $context_result['error'], 'warn');
      return false;
    }
    job_attention($job_id, 'Could not read the task from the instance: ' . $context_result['error']);
    return false;
  }
  $context = $context_result['json'];

  $chain_id = (int)($context['task']['chain_id'] ?? $job['upstream_chain_id']);
  $handoff_result = upstream_handoff_markdown($chain_id);
  if (!$handoff_result['ok']) {
    if (runner_transient($handoff_result)) {
      job_log($job_id, 'Could not read the ticket yet: ' . $handoff_result['error'], 'warn');
      return false;
    }
    job_attention($job_id, 'Could not read the ticket from the instance: ' . $handoff_result['error']);
    return false;
  }

  $role = $context['role'] ?? [];
  $upstream_project_id = $context['project']['id'] ?? null;

  // --- where the work happens --------------------------------------------------------------------
  $job_dir = runner_job_dir($job_id, $task_id);
  $workspace = $job_dir;
  $project = project_for_upstream($upstream_project_id);

  if ($upstream_project_id !== null) {
    if (!$project) {
      job_attention($job_id, runner_unmapped_message($context));
      $log[] = "job {$job_id}: project {$upstream_project_id} is not mapped to a directory here";
      return false;
    }
    $workspace = $project['workspace_path'];
    if (!is_dir($workspace)) {
      job_attention($job_id, "The workspace for {$project['name']} is set to {$workspace}, and there " .
        'is no directory there. Fix the path on the projects page, or create it.');
      return false;
    }
  }

  job_set($job_id, [
    'status'            => 'running',
    'started_at'        => date('Y-m-d H:i:s'),
    'job_dir'           => $job_dir,
    'workspace_path'    => $workspace,
    'role_slug'         => $role['slug'] ?? $job['role_slug'],
    'nickname'          => $role['display_name'] ?? null,
    'upstream_chain_id' => $chain_id,
    'is_test'           => !empty($context['task']['is_test']) ? 1 : 0,
  ]);

  // --- put the workspace in a known state --------------------------------------------------------
  if ($project && trim((string)$project['prepare_command']) !== '') {
    $prepare = agent_execute(command_split($project['prepare_command']), $workspace, '', 600);
    file_put_contents($job_dir . '/prepare.txt', $prepare['stdout'] . $prepare['stderr']);
    if (!$prepare['ok']) {
      job_attention($job_id, 'The prepare command failed, so the agent was not started: ' .
        ($prepare['error'] ?: 'no detail') . "\n\n" .
        mb_strimwidth(trim($prepare['stderr'] . $prepare['stdout']), 0, 2000, '…'));
      return false;
    }
    job_log($job_id, 'Prepared the workspace.');
  }

  // --- run ---------------------------------------------------------------------------------------
  $result_file = $job_dir . '/result.json';
  $prompt = agent_prompt($context, $handoff_result['body'], $result_file);
  file_put_contents($job_dir . '/prompt.md', $prompt);

  $model = $project['model'] ?? null;
  if ($model === null || $model === '') {
    $model = $role['model'] ?? null;
  }
  if ($model === null || $model === '') {
    $model = setting('default_model');
  }

  $argv = command_fill(command_split((string)setting('agent_command')), [
    'model'       => $model,
    'workspace'   => $workspace,
    'job_dir'     => $job_dir,
    'result_file' => $result_file,
    'task_id'     => $task_id,
    'role'        => $role['slug'] ?? '',
  ]);
  if (!$argv) {
    job_attention($job_id, 'The agent command on the settings page is empty.');
    return false;
  }

  job_log($job_id, 'Starting ' . implode(' ', $argv) . "\nin {$workspace}");
  $run = agent_execute($argv, $workspace, $prompt, setting_int('agent_timeout_seconds', 3600));
  file_put_contents($job_dir . '/stdout.txt', $run['stdout']);
  if ($run['stderr'] !== '') {
    file_put_contents($job_dir . '/stderr.txt', $run['stderr']);
  }

  $unwrapped = agent_unwrap($run['stdout']);
  $usage = $unwrapped['usage'] ?? [];
  job_set($job_id, [
    'exit_code'         => $run['exit_code'],
    'duration_ms'       => $run['duration_ms'],
    'model'             => $usage['model'] ?? $model,
    'input_tokens'      => $usage['input_tokens'] ?? null,
    'output_tokens'     => $usage['output_tokens'] ?? null,
    'cost_microdollars' => $usage['cost_microdollars'] ?? null,
  ]);

  // --- what it said ------------------------------------------------------------------------------
  $verdict = agent_verdict($result_file, $unwrapped['text']);
  if ($verdict['result'] === null) {
    // The work may well have been done. It is the report that is missing, and inventing a status to
    // send would be worse than stopping: the platform would route a ticket on a guess.
    $detail = $run['error'] !== '' ? $run['error'] : 'It exited cleanly but wrote no result file.';
    job_set($job_id, ['output' => $unwrapped['text']]);
    job_attention($job_id, "The agent did not report a status. {$detail}\n\n" .
      'Its answer is on this page. Report the task by hand on the instance, or fix the run and retry.');
    $log[] = "job {$job_id}: no verdict — {$detail}";
    return true;
  }

  $result = $verdict['result'];
  $status = trim((string)($result['status'] ?? ''));
  $output = trim((string)($result['output'] ?? ''));
  if ($output === '') {
    $output = trim($unwrapped['text']);
  }
  // The instance refuses a completion with no output, and rightly — the next role would have nothing
  // to read. Saying so plainly beats a 422 that looks like this machine is broken.
  if ($output === '') {
    $output = "The agent reported \"{$status}\" and wrote nothing to go with it. There is nothing " .
      'here for whoever picks this up next.';
  }
  job_log($job_id, 'Reported status "' . $status . '" (read from the ' . $verdict['source'] . ').');

  // A status the flow does not accept is refused here rather than upstream, because the message the
  // platform would return names neither what was said nor what was on offer.
  $allowed = $context['allowed_next']['statuses'] ?? [];
  if ($allowed && !in_array($status, $allowed, true)) {
    job_set($job_id, ['output' => $output, 'reported_status' => $status]);
    job_attention($job_id, "The agent reported \"{$status}\", which this step does not route. " .
      'It had to be one of: ' . implode(', ', $allowed) . ".\n\n" .
      'Its answer is on this page — nothing was sent, so the task is still claimed on the instance.');
    $log[] = "job {$job_id}: status \"{$status}\" is not routable";
    return true;
  }

  job_set($job_id, [
    'status'          => 'reporting',
    'output'          => $output,
    'communication'   => isset($result['communication']) && trim((string)$result['communication']) !== ''
      ? trim((string)$result['communication']) : null,
    'reported_status' => $status,
    'result_json'     => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'finished_at'     => date('Y-m-d H:i:s'),
    'report_next_at'  => date('Y-m-d H:i:s'),
  ]);
  $log[] = "job {$job_id}: agent finished in " . round($run['duration_ms'] / 1000) . "s, status \"{$status}\"";
  return true;
}

// A job directory per task, named so that finding one by task id needs no lookup.
function runner_job_dir($job_id, $task_id) {
  $dir = rtrim(bbl_config()['job_root'], '/\\') . '/task-' . (int)$task_id;
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException("Could not create the job directory at {$dir}.");
  }
  return str_replace('\\', '/', $dir);
}

// Said in full because it is the message somebody reads at the moment they are least able to guess
// what is meant: everything needed to make the mapping is in it.
function runner_unmapped_message(array $context) {
  $project = $context['project'];
  $lines = ["This task belongs to project {$project['id']}, \"{$project['name']}\", and this machine " .
            'has no directory mapped to it. Nothing was run.'];
  if (!empty($project['git_repo_url'])) {
    $lines[] = 'Its repository is ' . $project['git_repo_url'] .
      (empty($project['work_branch']) ? '' : ' on branch ' . $project['work_branch']) . '.';
  }
  $lines[] = 'Add it on the projects page, then retry this job.';
  return implode("\n\n", $lines);
}

// =====================================================================================
// Handing it back
// =====================================================================================

// Reports everything that has finished and not yet been accepted. Runs before anything else on every
// pass, and is the only part that keeps trying for hours.
function runner_flush_reports(array &$log) {
  $reported = 0;
  foreach (jobs_awaiting_report() as $job) {
    $reported += runner_report($job, $log) ? 1 : 0;
  }
  return $reported;
}

function runner_report(array $job, array &$log) {
  $job_id = (int)$job['id'];
  $task_id = (int)$job['upstream_task_id'];
  $attempt = (int)$job['report_attempts'] + 1;

  $next = [];
  if ((string)$job['reported_status'] !== '') {
    $step = ['status' => $job['reported_status']];
    // Only the entry role gets to say which project a ticket belongs in, and it says it here.
    $result = json_decode((string)$job['result_json'], true);
    if (is_array($result) && isset($result['project_id']) && $result['project_id'] !== '') {
      $step['project_id'] = (int)$result['project_id'];
    }
    $next[] = $step;
  }

  $payload = array_filter([
    'output'            => (string)$job['output'],
    'communication'     => $job['communication'],
    'next'              => $next,
    'model'             => $job['model'],
    'input_tokens'      => $job['input_tokens'] === null ? null : (int)$job['input_tokens'],
    'output_tokens'     => $job['output_tokens'] === null ? null : (int)$job['output_tokens'],
    'cost_microdollars' => $job['cost_microdollars'] === null ? null : (int)$job['cost_microdollars'],
  ], function ($value) {
    return $value !== null && $value !== [];
  });

  $result = upstream_complete($task_id, $payload);
  if ($result['ok']) {
    job_set($job_id, ['status' => 'done', 'report_attempts' => $attempt, 'report_next_at' => null,
                      'error' => null]);
    $advanced = $result['json']['next_task_id'] ?? null;
    job_log($job_id, 'Reported. The instance ' .
      ($advanced ? "started task {$advanced} next." : 'had nothing to start next.'));
    $log[] = "job {$job_id}: reported task {$task_id}";
    return true;
  }

  $max = setting_int('report_max_attempts', 8);
  if ($attempt >= $max || !runner_transient($result)) {
    // Out of road. The output stays on this page and the task stays claimed on the instance, where it
    // will show as stale — which is the outcome that gets a person to look, and the reason nothing is
    // quietly marked done here.
    job_set($job_id, ['status' => 'attention', 'report_attempts' => $attempt, 'report_next_at' => null,
                      'error' => 'The instance would not accept the result: ' . $result['error']]);
    job_log($job_id, 'Gave up reporting after ' . $attempt . ' attempt(s): ' . $result['error'], 'error');
    $log[] = "job {$job_id}: gave up reporting — " . $result['error'];
    return false;
  }

  job_set($job_id, [
    'report_attempts' => $attempt,
    'report_next_at'  => date('Y-m-d H:i:s', time() + job_report_backoff($attempt)),
    'error'           => $result['error'],
  ]);
  job_log($job_id, 'Could not report yet (attempt ' . $attempt . '): ' . $result['error'], 'warn');
  $log[] = "job {$job_id}: report deferred — " . $result['error'];
  return false;
}

// One full pass: report what is waiting, take what is offered, run what is ready. Returns a summary
// and the log lines, so the CLI and the dashboard's button say the same things.
//
// $execute is false when a web request is asking. Reporting and polling are a handful of HTTP calls
// and finish while somebody is still looking at the page; an agent run is measured in minutes and has
// no business inside a request that a browser, PHP and Apache will each independently give up on.
function runner_pass($execute = true) {
  $log = [];
  $summary = ['reported' => 0, 'accepted' => 0, 'ran' => 0];

  // First of all, before anything is counted: a job left mid-run by a reboot or a killed terminal.
  // Nothing is going to finish it, and a dashboard reporting work in flight that stopped yesterday is
  // worse than one reporting a failure.
  foreach (jobs_stuck() as $stuck) {
    job_attention((int)$stuck['id'],
      'The agent was still running when the runner stopped — a reboot, a closed terminal, or a ' .
      'crash. Whatever it had done is in its workspace; nothing was reported, so the task is still ' .
      'claimed on the instance. Look at the workspace before running it again.');
    $log[] = "job {$stuck['id']}: was left running and has been handed to a person";
  }

  $summary['reported'] = runner_flush_reports($log);
  $summary['accepted'] = runner_poll($log);

  if (!$execute) {
    return ['summary' => $summary, 'log' => $log];
  }

  $budget = max(1, setting_int('max_jobs_per_run', 1));
  foreach (jobs_ready() as $job) {
    if ($budget-- <= 0) {
      break;
    }
    if (runner_execute($job, $log)) {
      $summary['ran']++;
      // Hand it back straight away rather than waiting for the next pass. A minute is nothing to a
      // machine and a lot to somebody watching a ticket move.
      $fresh = job_by_id((int)$job['id']);
      if ($fresh && $fresh['status'] === 'reporting') {
        runner_report($fresh, $log);
      }
    }
  }

  // Last, and outside any condition. "Nothing arrived" and "nothing was looking" produce the same
  // empty dashboard, and this is what tells them apart.
  setting_set('last_pass_at', date('Y-m-d H:i:s'));

  return ['summary' => $summary, 'log' => $log];
}
