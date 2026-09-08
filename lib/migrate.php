<?php
// Applying db/migrations/*.sql, as functions rather than as a script.
//
// This was the body of tools/migrate.php, and moved here when a second caller appeared: an upgrade
// installs new migration files and then has to apply them, from a web request, with no terminal to
// print to and no exit code to take. So the work returns what happened and the two callers say it
// their own way — the tool to a person at a prompt, the upgrade page on a screen.
//
// Every migration file must be re-runnable. Applying one by hand leaves the schema right and
// schema_migrations empty, which is a normal thing to have happened, and this has to survive it.

require_once __DIR__ . '/db.php';

function migration_files() {
  $files = glob(__DIR__ . '/../db/migrations/*.sql') ?: [];
  sort($files);
  return $files;
}

function migrations_applied() {
  return array_column(db_all('SELECT filename FROM schema_migrations'), 'filename');
}

// The files not yet recorded, in the order they must be applied.
function migrations_pending() {
  $applied = migrations_applied();
  return array_values(array_filter(migration_files(), function ($file) use ($applied) {
    return !in_array(basename($file), $applied, true);
  }));
}

// Applies what is pending and stops at the first failure.
//
// Returns ['applied' => [names], 'failed' => name|null, 'error' => message]. A caller that ignores
// 'failed' is a caller that will report success over a half-migrated database, so both of the two
// look at it.
function migrations_apply() {
  $done = [];
  foreach (migrations_pending() as $file) {
    $name = basename($file);
    try {
      // No transaction: SQLite has no guard worth relying on for schema changes mixed with data ones,
      // and PDO::exec runs the whole file, so a failure part way leaves the earlier statements in
      // place. That is why migrations are written to be re-runnable rather than rolled back.
      db()->exec(file_get_contents($file));
      db_exec('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)', [$name, db_now()]);
      $done[] = $name;
    } catch (Throwable $e) {
      return ['applied' => $done, 'failed' => $name, 'error' => $e->getMessage()];
    }
  }
  return ['applied' => $done, 'failed' => null, 'error' => ''];
}
