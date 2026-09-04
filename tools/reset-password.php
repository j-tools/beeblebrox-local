<?php
// Forgets the password for these pages, so the next visit sets a new one.
//
//   timeout 60 php tools/reset-password.php
//
// There is one account here and no email to send a link to, so the only way back in is from the
// machine itself — which is the right answer anyway: whoever can run this already has the database
// file and everything in it.
//
// The sign-in page used to point at a line of SQL for this. That was written when the store was
// MySQL and a customer had a client to type it into; the store is one SQLite file now, and telling
// somebody to run a DELETE against a file they have no client for is telling them nothing.
//
// Sessions go too. A forgotten password with somebody still signed in on another machine is exactly
// when you want that other session to end, and this is the moment it can be done without asking.

require_once __DIR__ . '/cli.php';
tools_require_cli();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/settings.php';

$cfg = bbl_config();
echo "database: {$cfg['db_file']}\n";

if (setting('admin_password_hash') === '') {
  echo "\nThere is no password set — the next visit will ask for one already.\n";
  exit(0);
}

db_exec('DELETE FROM settings WHERE name = ?', ['admin_password_hash']);
$sessions = db_table_exists('sessions') ? db_exec('DELETE FROM sessions') : 0;

echo "\nThe password is forgotten";
echo $sessions > 0
  ? ", and {$sessions} signed-in session(s) ended.\n"
  : ".\n";

echo "\nOpen " . rtrim($cfg['site_url'], '/') . "/login.php and set a new one. Until you do, anybody\n";
echo "who can reach that address can set it instead — so do it now rather than later.\n";

echo "\nNothing else was touched. The instance, the API key, the signing secret and every job are\n";
echo "still here; only the way in was reset.\n";
