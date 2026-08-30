<?php
// Every question the diagnostics page asks, asked from a terminal.
//
//   timeout 120 php tools/selftest.php
//
// Same code as the page, so the two cannot disagree. Worth running from the account the scheduled
// task uses rather than from your own shell: half the answers here — whether the agent is on PATH,
// whether the workspaces are readable — are different for a service account, and that difference is
// the usual reason a run works by hand and does nothing on a schedule.
//
// Exit code 1 on any failure, 0 otherwise, so it can be the thing a schedule checks.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/checks.php';

$checks = checks_run(true);
$marks = ['pass' => 'ok  ', 'warn' => 'warn', 'fail' => 'FAIL'];

echo "beeblebrox-local — " . bbl_env_label() . "\n\n";

foreach ($checks as $check) {
  printf("  [%s] %s\n", $marks[$check['state']], $check['what']);
  if ($check['detail'] !== '') {
    // Wrapped and indented under its own line, because most of these are a sentence of advice rather
    // than a value, and a wrapped sentence in a column is unreadable.
    foreach (explode("\n", wordwrap($check['detail'], 88)) as $line) {
      echo '         ' . $line . "\n";
    }
  }
  if (!empty($check['url'])) {
    // On its own line and never wrapped, so a terminal that turns URLs into links gets a whole one.
    echo '         ' . $check['url'] . "\n";
  }
}

$counts = array_count_values(array_column($checks, 'state'));
printf("\n%d ok, %d warning(s), %d failure(s)\n",
  $counts['pass'] ?? 0, $counts['warn'] ?? 0, $counts['fail'] ?? 0);

exit(($counts['fail'] ?? 0) > 0 ? 1 : 0);
