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
// Every migration file must be re-runnable. Applying one by hand leaves the schema right and
// schema_migrations empty, which is a normal thing to have happened, and this has to survive it.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$dry_run = in_array('--dry-run', $argv, true);
$cfg = bbl_config();
echo "database: {$cfg['db_file']}\n";

// Opening it is what creates it, so by here the schema exists either way.
db();

$applied = array_column(db_all('SELECT filename FROM schema_migrations'), 'filename');
$files = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
sort($files);

$pending = array_values(array_filter($files, function ($file) use ($applied) {
  return !in_array(basename($file), $applied, true);
}));

printf("%d migration file(s), %d already applied, %d pending\n\n",
  count($files), count($applied), count($pending));

if (!$pending) {
  echo "Nothing to do.\n";
  exit(0);
}

foreach ($pending as $file) {
  $name = basename($file);
  if ($dry_run) {
    echo "  would apply  {$name}\n";
    continue;
  }
  echo "  applying     {$name} ... ";
  try {
    // SQLite has no transactional guard worth relying on for schema changes mixed with data ones,
    // and PDO::exec runs the whole file, so a failure part way leaves the earlier statements in
    // place. That is why migrations are written to be re-runnable rather than rolled back.
    db()->exec(file_get_contents($file));
    db_exec('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)', [$name, db_now()]);
    echo "done\n";
  } catch (Throwable $e) {
    echo "FAILED\n\n";
    fwrite(STDERR, $e->getMessage() . "\n\n");
    fwrite(STDERR,
      "Nothing after this file was applied, and this one is not recorded — so re-running retries it\n" .
      "from the top. If the change is in fact already present, record it and carry on:\n\n" .
      "  INSERT INTO schema_migrations (filename) VALUES ('{$name}');\n\n");
    exit(1);
  }
}

echo "\n" . ($dry_run ? "Dry run — nothing was applied.\n" : "Up to date.\n");
