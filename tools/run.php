<?php
// The runner. Nothing happens on this machine until this runs.
//
//   php tools/run.php              one pass, then exit — this is what the schedule calls
//   php tools/run.php --watch      keep going, one pass every poll interval
//   php tools/run.php --once       alias for the default, for the sake of being able to say it
//
// One pass is: report anything that finished and has not been handed back, ask the instance for work,
// then run what is ready. Safe to call every minute — a second copy finds the lock held and exits
// without doing anything, so a pass that takes half an hour is not a problem for the schedule.
//
// Every failure mode ends with the job on the dashboard and the reason written next to it. Nothing
// here is allowed to end with a task that quietly stopped existing.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/runner.php';

tools_require_table('jobs', '001-initial.sql');

$watch = in_array('--watch', $argv, true);
$interval = 60;
foreach ($argv as $arg) {
  if (preg_match('/^--interval=(\d+)$/', $arg, $m)) {
    $interval = max(10, (int)$m[1]);
  }
}

$gaps = settings_gaps();
if ($gaps) {
  fwrite(STDERR, "Not set up yet:\n");
  foreach ($gaps as $gap) {
    fwrite(STDERR, '  - ' . $gap . "\n");
  }
  fwrite(STDERR, "\nFix these on " . rtrim(bbl_config()['site_url'], '/') . "/settings.php\n");
  exit(1);
}

$lock = runner_lock();
if ($lock === null) {
  // Not an error. A pass that is still going is the normal reason, and saying so on stdout keeps it
  // out of the way of a schedule that only mails on failure.
  echo "Another pass is already running. Nothing to do.\n";
  exit(0);
}

// Released however this ends, including on a fatal error, so a crash does not leave the lock held and
// every later pass silently doing nothing.
register_shutdown_function(function () use (&$lock) {
  runner_unlock($lock);
});

do {
  $stamp = date('H:i:s');
  try {
    $pass = runner_pass();
    foreach ($pass['log'] as $line) {
      echo "[{$stamp}] {$line}\n";
    }
    $summary = $pass['summary'];
    if (!$pass['log']) {
      echo "[{$stamp}] nothing to do\n";
    } else {
      printf("[%s] %d reported, %d taken, %d run\n",
        $stamp, $summary['reported'], $summary['accepted'], $summary['ran']);
    }
  } catch (Throwable $e) {
    // In watch mode one bad pass must not end the loop — the instance being down for a minute is
    // exactly the case this is here for.
    fwrite(STDERR, "[{$stamp}] pass failed: " . $e->getMessage() . "\n");
    if (!$watch) {
      exit(1);
    }
  }
  if ($watch) {
    sleep($interval);
  }
} while ($watch);
