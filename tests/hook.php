<?php
// The receiver, end to end, against a running server.
//
//   timeout 120 php tests/hook.php http://127.0.0.1:8774
//
// Posts real signed envelopes — good ones and every kind of bad one — and checks what comes back and
// what ends up in the database. The refusals are the point: an envelope that should be refused and is
// not eventually starts an agent on this machine.
//
// Borrows the configured signing secret for the duration and puts back exactly what it found, and
// uses task ids above 900000000 so nothing it creates can collide with real work. It cleans up after
// itself either way.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/jobs.php';

$base = rtrim($argv[1] ?? bbl_config()['site_url'], '/');
$secret = 'test-secret-' . bin2hex(random_bytes(8));
$fake_task = 900000001;
$failures = 0;

echo "receiver: {$base}\n\n";

function expect($expected, $actual, $what) {
  global $failures;
  if ($expected === $actual) {
    echo "  ok    {$what}\n";
    return;
  }
  $failures++;
  echo "  FAIL  {$what}\n";
  echo '        expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}

// Posts an envelope, signing it unless told otherwise. Returns [status, decoded body].
function post($base, $body, array $headers) {
  $ch = curl_init($base . '/hook.php');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $response = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($error !== '') {
    fwrite(STDERR, "Could not reach {$base}/hook.php: {$error}\n" .
      "Start it first, e.g. php -S 127.0.0.1:8774 -t .\n");
    exit(1);
  }
  return [$status, json_decode((string)$response, true)];
}

function envelope($instance, $task_id, array $overrides = []) {
  return json_encode(array_replace_recursive([
    'event' => 'task.dispatched',
    'instance' => $instance,
    'delivery_id' => 12345,
    'attempt' => 1,
    'task' => ['id' => $task_id, 'role' => 'developer', 'nickname' => 'Slartibartfast',
               'project_id' => null, 'chain_id' => 777, 'depth' => 1, 'is_test' => true],
    'read' => ['handoff_markdown' => $instance . '/api/chains/777/markdown',
               'task_context' => $instance . '/api/tasks/' . $task_id],
    'report' => ['claim' => $instance . '/api/tasks/' . $task_id . '/claim',
                 'complete' => $instance . '/api/tasks/' . $task_id . '/complete'],
  ], $overrides), JSON_UNESCAPED_SLASHES);
}

function signed_headers($body, $secret, $timestamp = null) {
  $timestamp = $timestamp ?? (string)time();
  return ['X-Beeblebrox-Timestamp: ' . $timestamp,
          'X-Beeblebrox-Signature: ' . signature_expected($body, $secret, $timestamp)];
}

// --- borrow the configuration ------------------------------------------------------------------
$saved_secret = db_one('SELECT value FROM settings WHERE name = ?', ['webhook_secret']);
$saved_accept = setting('accept_webhooks');
$saved_ips = setting('allowed_ips');
$instance = instance_base();

if ($instance === '') {
  fwrite(STDERR, "No instance URL is configured, and the receiver checks every envelope against it.\n" .
    "Set one on the settings page first.\n");
  exit(1);
}

// Put everything back however this ends, including on a fatal error — leaving a test secret in place
// would silently break every real delivery.
register_shutdown_function(function () use ($saved_secret, $saved_accept, $saved_ips, $fake_task) {
  db_exec('DELETE FROM jobs WHERE upstream_task_id >= 900000000');
  db_exec("DELETE FROM webhook_log WHERE body LIKE '%900000%'");
  db_exec('INSERT INTO settings (name, value, is_secret) VALUES (?, ?, 1)
             ON CONFLICT(name) DO UPDATE SET value = excluded.value',
    ['webhook_secret', $saved_secret === null ? '' : $saved_secret['value']]);
  setting_set('accept_webhooks', $saved_accept);
  setting_set('allowed_ips', $saved_ips);
  echo "\nConfiguration restored.\n";
});

setting_set('webhook_secret', $secret);
setting_set('accept_webhooks', '1');
setting_set('allowed_ips', '');
db_exec('DELETE FROM jobs WHERE upstream_task_id >= 900000000');

// --- refusals ----------------------------------------------------------------------------------
echo "What must be refused\n";

$body = envelope($instance, $fake_task);
[$status] = post($base, $body, []);
expect(401, $status, 'an unsigned envelope');

[$status] = post($base, $body, ['X-Beeblebrox-Timestamp: ' . time(),
                                'X-Beeblebrox-Signature: sha256=' . str_repeat('0', 64)]);
expect(401, $status, 'a wrong signature');

[$status] = post($base, $body, signed_headers($body, 'not-the-secret'));
expect(401, $status, 'a signature made with a different secret');

[$status] = post($base, $body, signed_headers($body, $secret, (string)(time() - 4000)));
expect(401, $status, 'a replay from an hour ago, signed correctly for then');

$tampered = str_replace('"depth":1', '"depth":9', $body);
[$status] = post($base, $tampered, signed_headers($body, $secret));
expect(401, $status, 'a body changed after it was signed');

$other = envelope('https://someone-else.beeblebrox.cloud', $fake_task);
[$status] = post($base, $other, signed_headers($other, $secret));
expect(409, $status, 'a correctly signed envelope from a different instance');

// The one that matters most: a signature proves who sent it, not where it is pointing. If the secret
// ever leaks, this is what stops the API key being handed to somebody else's host.
$redirected = envelope($instance, $fake_task,
  ['read' => ['handoff_markdown' => 'https://evil.example/api/chains/777/markdown']]);
[$status] = post($base, $redirected, signed_headers($redirected, $secret));
expect(409, $status, 'a signed envelope pointing somewhere off the instance');

[$status] = post($base, 'not json at all', signed_headers('not json at all', $secret));
expect(400, $status, 'a body that is not JSON');

expect(0, db_count('SELECT COUNT(*) FROM jobs WHERE upstream_task_id >= 900000000'),
  'and none of those queued anything');

// --- what must be accepted -----------------------------------------------------------------------
echo "\nWhat must be accepted\n";

$test_envelope = envelope($instance, 0, ['event' => 'test']);
[$status, $json] = post($base, $test_envelope, signed_headers($test_envelope, $secret));
expect(200, $status, "the dispatcher's own connection test");
expect(true, $json['ok'] ?? null, 'and it answers ok');
expect(0, db_count('SELECT COUNT(*) FROM jobs WHERE upstream_task_id >= 900000000'),
  'without queueing a job for task 0');

[$status, $json] = post($base, $body, signed_headers($body, $secret));
expect(202, $status, 'a genuine envelope');
expect(true, $json['queued'] ?? null, 'and it says it queued it');

$job = job_by_task($fake_task);
expect(true, $job !== null, 'a job row exists');
expect('queued', $job['status'] ?? null, 'in the queued state');
expect('webhook', $job['source'] ?? null, 'recorded as having arrived by webhook');
expect('developer', $job['role_slug'] ?? null, 'with the role from the envelope');
expect(777, (int)($job['upstream_chain_id'] ?? 0), 'and the ticket it belongs to');
expect(1, (int)($job['is_test'] ?? 0), 'the test flag survives, which is the one that must not be lost');

// --- redelivery ----------------------------------------------------------------------------------
echo "\nRedelivery\n";

[$status, $json] = post($base, $body, signed_headers($body, $secret));
expect(202, $status, 'the same envelope again is still accepted');
expect(false, $json['queued'] ?? null, 'but says it did not queue it twice');
expect(1, db_count('SELECT COUNT(*) FROM jobs WHERE upstream_task_id = ?', [$fake_task]),
  'and there is still exactly one job');

// --- the switches ----------------------------------------------------------------------------------
echo "\nThe switches actually switch\n";

setting_set('accept_webhooks', '0');
$second = envelope($instance, $fake_task + 1);
[$status] = post($base, $second, signed_headers($second, $secret));
expect(503, $status, 'with webhooks off, nothing is accepted');
setting_set('accept_webhooks', '1');

setting_set('allowed_ips', '203.0.113.9');
[$status] = post($base, $second, signed_headers($second, $secret));
expect(403, $status, 'an address outside the allow list is refused');
setting_set('allowed_ips', '');

[$status] = post($base, $second, signed_headers($second, $secret));
expect(202, $status, 'and accepted again once the list is cleared');

// --- retries ---------------------------------------------------------------------------------------
// The instance retries by reusing the task id and bumping the attempt, so a job keyed on the id alone
// would silently refuse every retry. Exercised through job_accept rather than the receiver, because
// the attempt only travels on the polling path.
echo "\nA retry of the same task id\n";

$retry_task = $fake_task + 5;
[$retry_id] = job_accept(['source' => 'poll', 'upstream_task_id' => $retry_task,
                          'role_slug' => 'developer', 'attempt' => 1]);
job_set($retry_id, ['status' => 'done', 'output' => 'the first attempt']);

[$again, $how] = job_accept(['source' => 'poll', 'upstream_task_id' => $retry_task,
                             'role_slug' => 'developer', 'attempt' => 1]);
expect('existing', $how, 'the same attempt again changes nothing');
expect('done', job_by_id($again)['status'], 'and the finished job stays finished');

[$again, $how] = job_accept(['source' => 'poll', 'upstream_task_id' => $retry_task,
                             'role_slug' => 'developer', 'attempt' => 2]);
expect('created', $how, 'a higher attempt is taken as new work');
expect($retry_id, $again, 'on the same row rather than a second one');
$requeued = job_by_id($again);
expect('queued', $requeued['status'], 'queued again');
expect(2, (int)$requeued['attempt'], 'with the new attempt number');
expect(null, $requeued['output'], "and the previous attempt's output cleared out");

job_set($retry_id, ['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);
[, $how] = job_accept(['source' => 'poll', 'upstream_task_id' => $retry_task,
                       'role_slug' => 'developer', 'attempt' => 3]);
expect('existing', $how, 'but a job still in flight is never restarted under itself');

// --- the log ---------------------------------------------------------------------------------------
echo "\nEvery arrival is written down\n";
expect(true, db_count("SELECT COUNT(*) FROM webhook_log WHERE accepted = 0 AND body LIKE '%900000%'") >= 8,
  'the refusals are in webhook_log with their reasons');
expect(true, db_count("SELECT COUNT(*) FROM webhook_log WHERE accepted = 1 AND body LIKE '%900000%'") >= 3,
  'so are the acceptances');

echo "\n" . ($failures === 0 ? "All passed.\n" : "{$failures} failure(s).\n");
exit($failures === 0 ? 0 : 1);
