<?php
// Shared guard for everything in tools/. These write to the database and start processes; served over
// HTTP they would be an unauthenticated way to do both.

function tools_require_cli() {
  if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
  }
}

// Says which migration is missing rather than letting the first query that needs the table throw a
// stack trace at somebody who then has to work out which table it meant.
function tools_require_table($table, $migration) {
  $exists = db_count(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
    [bbl_config()['db_name'], $table]
  );
  if ($exists === 0) {
    fwrite(STDERR, "The '{$table}' table does not exist yet. Load db/schema.sql, or apply " .
      "db/migrations/{$migration}, then run this again.\n");
    exit(1);
  }
}
