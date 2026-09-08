<?php
// Installs the newest published build over this one, from a terminal.
//
//   timeout 300 php tools/upgrade.php            what it would do
//   timeout 300 php tools/upgrade.php --yes       do it
//   timeout 300 php tools/upgrade.php --restore   put the previous build back
//
// The same work the upgrade page does, and the answer where that page cannot help: a copy whose files
// the web server may not write is a perfectly sound way to run this, and it is upgraded from here, as
// whoever owns the files.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/updates.php';
require_once __DIR__ . '/../lib/upgrade.php';

$build = bbl_build();
echo 'installed: ' . ($build['number'] === null ? 'a checkout' : 'build ' . $build['number']) . "\n";

if (in_array('--restore', $argv, true)) {
  $back = upgrade_restore();
  echo "restored build {$back['build']} from {$back['files']} kept files.\n";
  exit(0);
}

// The same probe the page uses, so a person who cannot see the button here learns why. Run as the
// owner of the files this usually passes where the web server's own attempt did not, which is the
// whole reason this exists.
$blockers = upgrade_blockers();
if ($blockers) {
  fwrite(STDERR, "This copy cannot upgrade itself:\n\n");
  foreach ($blockers as $blocker) {
    fwrite(STDERR, '  - ' . $blocker . "\n\n");
  }
  exit(1);
}

$newest = updates_latest();
if ($newest === null) {
  fwrite(STDERR, "Could not ask GitHub which build is newest.\n");
  exit(1);
}
echo "newest:    build {$newest}\n";
if ($newest <= (int)$build['number']) {
  echo "Nothing to do.\n";
  exit(0);
}

if (!in_array('--yes', $argv, true)) {
  echo "\nWould fetch build {$newest}, check it against the hash published with it, keep every file " .
    "it replaces\nunder " . upgrade_relative_path(upgrade_backup_root()) . ", copy it over this " .
    "directory and apply any migrations.\nconfig.local.php and data/ are not in the download and are " .
    "not touched.\n\nRun again with --yes to do it.\n";
  exit(0);
}

echo "\nfetching build {$newest} ... ";
$staged = upgrade_stage($newest);
echo "checked and unpacked\n";

$result = upgrade_apply($staged, $newest);
echo "installed:  {$result['files']} files, {$result['backed_up']} of them replacing a kept copy\n";
echo "backup:     {$result['backup']}\n";
if ($result['removed']) {
  echo 'removed:    ' . implode(', ', $result['removed']) . "\n";
}
if ($result['migrations']['applied']) {
  echo 'migrations: ' . implode(', ', $result['migrations']['applied']) . "\n";
}
if ($result['migrations']['failed'] !== null) {
  fwrite(STDERR, "\nThe files are in place but a migration failed: {$result['migrations']['failed']}\n" .
    $result['migrations']['error'] . "\n");
  exit(1);
}
echo "\nBuild {$result['to']} is installed.\n";
