<?php
// The receiver. This is the URL a Beeblebrox dispatcher posts to.
//
// It does one thing: decide whether an envelope is genuine, write down that the task exists, and say
// yes. It never fetches the ticket, never starts an agent and never talks back to the instance —
// accepting is all a receiver has to do, and the platform's own timeout for it is measured in
// seconds while the work is measured in minutes. tools/run.php does the rest, on its own schedule.
//
// Every arrival is written to webhook_log, refusals included and with the reason. When deliveries are
// failing, that table is the only place anybody will look, so nothing here fails silently.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/jobs.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';

// Written before the response, so a refusal is on record even if the caller hangs up on it. The body
// is kept for refusals and for accepted envelopes alike: on a refusal it is the only evidence of what
// was actually sent, which is exactly what a mismatched secret needs.
function receipt($accepted, $reason, $task_id, $body) {
  global $remote;
  try {
    db_exec('INSERT INTO webhook_log (remote_addr, accepted, reason, task_id, body) VALUES (?, ?, ?, ?, ?)',
      [$remote, $accepted ? 1 : 0, mb_strimwidth((string)$reason, 0, 190, '…'),
       $task_id === null ? null : (int)$task_id, mb_strimwidth((string)$body, 0, 20000, '…')]);
  } catch (Throwable $e) {
    // A receiver that cannot write its own log still has to answer. Losing the line is bad; failing
    // the delivery because of it would be worse, and the platform would retry into the same wall.
  }
}

// The reason is specific in the log and vague here on purpose. Telling a caller which half of the
// check it failed is telling it how to pass.
function refuse($status, $public, $reason, $body, $task_id = null) {
  receipt(false, $reason, $task_id, $body);
  http_response_code($status);
  echo json_encode(['error' => $public]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['error' => 'This endpoint takes a POST from a Beeblebrox dispatcher.']);
  exit;
}

$body = (string)file_get_contents('php://input');

if (!setting_bool('accept_webhooks')) {
  refuse(503, 'This receiver is not accepting webhooks.',
    'webhooks are switched off in settings', $body);
}
if (!ip_allowed($remote, setting('allowed_ips'))) {
  refuse(403, 'Refused.', "the address {$remote} is not in the allow list", $body);
}

$problem = signature_check(
  $body,
  $_SERVER['HTTP_X_BEEBLEBROX_SIGNATURE'] ?? '',
  $_SERVER['HTTP_X_BEEBLEBROX_TIMESTAMP'] ?? '',
  setting_secret_is_set('webhook_secret') ? setting_secret('webhook_secret') : '',
  setting_int('signature_tolerance', 300)
);
if ($problem !== null) {
  refuse(401, 'Refused.', $problem, $body);
}

$envelope = json_decode($body, true);
if (!is_array($envelope)) {
  refuse(400, 'The body is not JSON.', 'the body is not JSON', $body);
}

// A signature proves the sender holds the secret. This proves the sender is the instance this machine
// works for, which is a different question — and the one that matters the day a secret is shared
// between two instances by mistake.
$instance = rtrim((string)($envelope['instance'] ?? ''), '/');
if ($instance !== instance_base()) {
  refuse(409, 'Refused.',
    "the envelope names {$instance}, and this receiver works for " . (instance_base() ?: 'nobody'), $body);
}

// The dispatcher's own connection test: a real, signed envelope naming task 0, which no task ever is.
// Answered properly and recorded, but nothing is queued.
if (($envelope['event'] ?? '') === 'test' || (int)($envelope['task']['id'] ?? 0) === 0) {
  receipt(true, 'connection test', null, $body);
  echo json_encode(['ok' => true, 'receiver' => bbl_env_label(), 'note' => 'Test envelope accepted.']);
  exit;
}

$task = $envelope['task'] ?? [];
$task_id = (int)($task['id'] ?? 0);
if ($task_id <= 0) {
  refuse(400, 'The envelope names no task.', 'the envelope names no task', $body);
}

// The URLs are checked here rather than when they are used, so a delivery that would send this
// machine's API key somewhere else is refused at the door instead of becoming a job that fails later
// for a reason nobody can read.
foreach (['read', 'report'] as $group) {
  foreach ((array)($envelope[$group] ?? []) as $name => $url) {
    if (!url_is_upstream($url)) {
      refuse(409, 'Refused.', "{$group}.{$name} points at {$url}, which is not on this instance", $body,
        $task_id);
    }
  }
}

try {
  [$job_id, $how] = job_accept([
    'source'            => 'webhook',
    'upstream_task_id'  => $task_id,
    'upstream_chain_id' => $task['chain_id'] ?? null,
    'delivery_id'       => $envelope['delivery_id'] ?? null,
    'project_id'        => $task['project_id'] ?? null,
    'role_slug'         => $task['role'] ?? null,
    'nickname'          => $task['nickname'] ?? null,
    'depth'             => $task['depth'] ?? 0,
    'is_test'           => !empty($task['is_test']),
    'envelope'          => $body,
  ]);
} catch (Throwable $e) {
  // A 5xx rather than a 4xx: the envelope was fine and this machine was not, so the platform should
  // retry rather than give up on it.
  receipt(false, 'could not queue it: ' . $e->getMessage(), $task_id, $body);
  http_response_code(500);
  echo json_encode(['error' => 'Could not queue the task. It is worth retrying.']);
  exit;
}

receipt(true, $how === 'existing' ? 'already queued' : 'queued', $task_id, $body);
http_response_code(202);
echo json_encode([
  'ok'       => true,
  'job_id'   => $job_id,
  'task_id'  => $task_id,
  'queued'   => $how === 'created',
  'receiver' => bbl_env_label(),
  // Said plainly because the answer to "why has nothing happened" is almost always that the runner is
  // not on a schedule yet.
  'note'     => $how === 'existing'
    ? 'This task was already queued here; nothing was started twice.'
    : 'Queued. tools/run.php picks it up on its next pass.',
]);
