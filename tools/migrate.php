<?php
// Applies db/migrations/*.sql once each, in filename order, recording each in schema_migrations.
//
//   timeout 120 php tools/migrate.php
//   timeout 120 php tools/migrate.php --dry-run
//
// A fresh database needs none of this: the schema creates itself from db/schema.sql the first time
// anything opens it, and every migration is recorded as already applied at the same time. This is
// only for a database that already existed when a new file arrived.
//
// The work itself is in lib/migrate.php, because the upgrade page applies migrations too and one of
// the two had to stop being a script. This is the half that talks to a person at a prompt.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/migrate.php';

$dry_run = in_array('--dry-run', $argv, true);
$cfg = bbl_config();
echo "database: {$cfg['db_file']}\n";

// Opening it is what creates it, so by here the schema exists either way.
db();

$pending = migrations_pending();
printf("%d migration file(s), %d already applied, %d pending\n\n",
  count(migration_files()), count(migrations_applied()), count($pending));

if (!$pending) {
  echo "Nothing to do.\n";
  exit(0);
}

if ($dry_run) {
  foreach ($pending as $file) {
    echo '  would apply  ' . basename($file) . "\n";
  }
  echo "\nDry run — nothing was applied.\n";
  exit(0);
}

$result = migrations_apply();
foreach ($result['applied'] as $name) {
  echo "  applied      {$name}\n";
}
if ($result['failed'] !== null) {
  echo "  FAILED       {$result['failed']}\n\n";
  fwrite(STDERR, $result['error'] . "\n\n");
  fwrite(STDERR,
    "Nothing after this file was applied, and this one is not recorded — so re-running retries it\n" .
    "from the top. If the change is in fact already present, record it and carry on:\n\n" .
    "  INSERT INTO schema_migrations (filename) VALUES ('{$result['failed']}');\n\n");
  exit(1);
}

echo "\nUp to date.\n";
