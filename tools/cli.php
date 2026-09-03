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
  if (!db_table_exists($table)) {
    fwrite(STDERR, "The '{$table}' table does not exist yet. It should have been created on first " .
      "use — try opening a page, or apply db/migrations/{$migration}, then run this again.\n");
    exit(1);
  }
}
