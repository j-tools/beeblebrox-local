<?php
// Library-level checks. No server, no instance, no agent — just the pieces where being wrong is
// silent, which is why these are the ones with tests.
//
//   timeout 60 php tests/smoke.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/agent.php';
require_once __DIR__ . '/../lib/settings.php';

$failures = 0;

function is_same($expected, $actual, $what) {
  global $failures;
  if ($expected === $actual) {
    echo "  ok    {$what}\n";
    return;
  }
  $failures++;
  echo "  FAIL  {$what}\n";
  echo '        expected: ' . var_export($expected, true) . "\n";
  echo '        actual:   ' . var_export($actual, true) . "\n";
}

function is_true($actual, $what) {
  is_same(true, (bool)$actual, $what);
}

echo "command_split — the reason nothing from a ticket reaches a command line\n";
is_same(['claude', '-p', '--model', '{model}'],
  command_split('claude -p --model {model}'), 'splits on spaces');
is_same(['C:/Program Files/x/claude.exe', '-p'],
  command_split('"C:/Program Files/x/claude.exe" -p'), 'keeps a quoted path in one argument');
is_same(['a', 'b'], command_split("  a \t b \n"), 'ignores runs of whitespace');
is_same([], command_split('   '), 'an empty template is no arguments at all');
is_same(['', 'x'], command_split('"" x'), 'an explicitly empty argument survives');

echo "\ncommand_fill — substitution happens after splitting, never before\n";
is_same(['claude', '--model', 'opus'],
  command_fill(['claude', '--model', '{model}'], ['model' => 'opus']), 'fills a placeholder');
is_same(['run', 'C:/two words/here'],
  command_fill(['run', '{workspace}'], ['workspace' => 'C:/two words/here']),
  'a value with a space stays one argument');
is_same(['run', 'x; rm -rf /'],
  command_fill(['run', '{workspace}'], ['workspace' => 'x; rm -rf /']),
  'a value that looks like shell is just a value');

echo "\nsignature_check — what makes an envelope genuine\n";
$secret = 'a-shared-secret';
$body = '{"event":"task.dispatched"}';
$now = (string)time();
$good = signature_expected($body, $secret, $now);

is_same(null, signature_check($body, $good, $now, $secret, 300), 'a correct signature passes');
is_true(signature_check($body, $good, $now, 'wrong-secret', 300),
  'a different secret is refused');
is_true(signature_check($body . ' ', $good, $now, $secret, 300),
  'a changed body is refused');
is_true(signature_check($body, $good, (string)(time() - 3600), $secret, 300),
  'an old timestamp is refused even with the signature it was sent with');
is_true(signature_check($body, $good, (string)(time() + 3600), $secret, 300),
  'a timestamp from the future is refused too');
is_true(signature_check($body, '', $now, $secret, 300), 'no signature is refused');
is_true(signature_check($body, $good, $now, '', 300),
  'no configured secret refuses everything rather than accepting it');
is_true(signature_check($body, $good, 'not-a-number', $secret, 300),
  'a timestamp that is not a number is refused');

// The one that would be invisible: a signature computed over the body alone still matches the body,
// so only binding the timestamp in makes a replay fail.
is_true(signature_check($body, 'sha256=' . hash_hmac('sha256', $body, $secret), $now, $secret, 300),
  'a signature over the body without the timestamp does not match');

echo "\nip_allowed\n";
is_true(ip_allowed('1.2.3.4', ''), 'an empty list means any address');
is_true(ip_allowed('1.2.3.4', '1.2.3.4, 5.6.7.8'), 'an address in the list passes');
is_same(false, ip_allowed('9.9.9.9', '1.2.3.4'), 'one that is not is refused');

echo "\nagent_unwrap — reading what Claude Code's --output-format json says\n";
$wrapped = json_encode([
  'type' => 'result', 'is_error' => false, 'result' => 'the answer',
  'total_cost_usd' => 0.0325, 'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
]);
$unwrapped = agent_unwrap($wrapped);
is_same('the answer', $unwrapped['text'], 'pulls out the final text');
is_same(32500, $unwrapped['usage']['cost_microdollars'], 'converts dollars to microdollars');
is_same(120, $unwrapped['usage']['input_tokens'], 'carries the token counts through');

$plain = agent_unwrap("just some text\n");
is_same("just some text\n", $plain['text'], 'plain output is passed through unchanged');
is_same(null, $plain['usage'], 'and reports no usage rather than zeroes');

echo "\nagent_verdict — the file first, the prose only as a fallback\n";
$dir = sys_get_temp_dir() . '/bbl-smoke-' . getmypid();
@mkdir($dir, 0775, true);
$file = $dir . '/result.json';

file_put_contents($file, json_encode(['status' => 'passed', 'output' => 'from the file']));
$verdict = agent_verdict($file, 'the prose said something else');
is_same('file', $verdict['source'], 'prefers the result file');
is_same('passed', $verdict['result']['status'], 'and reads its status');

unlink($file);
$verdict = agent_verdict($file, "I will write:\n```json\n{\"status\":\"shape\"}\n```\n" .
  "and here it is:\n```json\n{\"status\":\"failed\",\"output\":\"real\"}\n```");
is_same('output', $verdict['source'], 'falls back to a fenced block');
is_same('failed', $verdict['result']['status'],
  'and takes the last one, not the example that came first');

$verdict = agent_verdict($file, 'no json anywhere');
is_same(null, $verdict['result'], 'no verdict at all is null rather than a guess');

file_put_contents($file, 'not json');
is_same(null, agent_verdict($file, 'no json anywhere')['result'],
  'an unparseable result file is the same as none');
unlink($file);
@rmdir($dir);

echo "\nurl_is_upstream — the check that still holds if the signing secret leaks\n";
// Set on the live row rather than mocked, then put back, because the function reads settings and a
// test that mocks its way around that would not be testing the thing that runs.
$stored = db_one('SELECT value FROM settings WHERE name = ?', ['instance_url']);
setting_set('instance_url', 'https://zaphod.beeblebrox.cloud');
is_true(url_is_upstream('https://zaphod.beeblebrox.cloud/api/tasks/1'), 'a URL on the instance');
is_same(false, url_is_upstream('https://elsewhere.example/api/tasks/1'), 'another host');
is_same(false, url_is_upstream('https://zaphod.beeblebrox.cloud.evil.example/api/tasks/1'),
  'a host that merely starts with the instance');
is_same(false, url_is_upstream(''), 'an empty URL');
setting_set('instance_url', $stored === null ? '' : $stored['value']);

echo "\n" . ($failures === 0 ? "All passed.\n" : "{$failures} failure(s).\n");
exit($failures === 0 ? 0 : 1);
