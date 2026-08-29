<?php
// Applies db/migrations/*.sql once each, in filename order, recording each in schema_migrations.
//
//   timeout 120 php tools/migrate.php
//   timeout 120 php tools/migrate.php --dry-run
//
// A fresh database is installed from db/schema.sql instead — it has every migration folded in — and
// this then records them all as applied so it never runs one over the top of a schema that has it.
//
// Every migration file must be re-runnable. Applying one by hand leaves the schema right and
// schema_migrations empty, which is a normal thing to have happened, and the runner has to survive
// it. MySQL has no ADD COLUMN IF NOT EXISTS, so a step asks information_schema and prepares either
// the ALTER or SELECT 1.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$dry_run = in_array('--dry-run', $argv, true);
$cfg = bbl_config();
echo "database: {$cfg['db_name']} on {$cfg['db_host']}:{$cfg['db_port']}\n";

db_exec('CREATE TABLE IF NOT EXISTS schema_migrations (
           filename   VARCHAR(190) NOT NULL PRIMARY KEY,
           applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$installed = db_count(
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'settings'",
  [$cfg['db_name']]
);
if ($installed === 0) {
  fwrite(STDERR, "This database is empty. Load db/schema.sql into it first:\n\n" .
    "  mysql -h {$cfg['db_host']} -P {$cfg['db_port']} -u {$cfg['db_user']} -p " .
    "{$cfg['db_name']} < db/schema.sql\n\n");
  exit(1);
}

$applied = array_column(db_all('SELECT filename FROM schema_migrations'), 'filename');
$files = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
sort($files);

$pending = array_values(array_filter($files, function ($file) use ($applied) {
  return !in_array(basename($file), $applied, true);
}));

if (!$pending) {
  echo count($files) . ' migration(s), all applied. Nothing to do.' . "\n";
  exit(0);
}

printf("%d pending\n\n", count($pending));

foreach ($pending as $file) {
  $name = basename($file);
  if ($dry_run) {
    echo "  would apply  {$name}\n";
    continue;
  }
  echo "  applying     {$name} ... ";
  try {
    // mysqli throws on PHP 8.1+ rather than returning false, so the old `if (!multi_query())` guard is
    // dead code — catching Throwable is what actually reaches the advice below.
    $conn = db();
    $sql = file_get_contents($file);
    if (mysqli_multi_query($conn, $sql)) {
      do {
        if ($result = mysqli_store_result($conn)) {
          mysqli_free_result($result);
        }
      } while (mysqli_more_results($conn) && mysqli_next_result($conn));
    }
    // A statement that failed part way leaves more_results true and an error set; asking now is what
    // turns a half-applied file into a refusal instead of a recorded success.
    if (mysqli_errno($conn) !== 0) {
      throw new RuntimeException(mysqli_error($conn));
    }
    db_exec('INSERT INTO schema_migrations (filename) VALUES (?)', [$name]);
    echo "done\n";
  } catch (Throwable $e) {
    echo "FAILED\n\n";
    fwrite(STDERR, $e->getMessage() . "\n\n");
    fwrite(STDERR,
      "Nothing after this file was applied. If the change is in fact already present — because it " .
      "was applied by hand — record it and carry on:\n\n" .
      "  INSERT INTO schema_migrations (filename) VALUES ('{$name}');\n\n");
    exit(1);
  }
}

echo "\n" . ($dry_run ? "Dry run — nothing was applied.\n" : "Up to date.\n");
