<?php
// Copy to config.local.php and fill in. config.local.php is gitignored and must never be committed.
//
// This is the only file you edit. Everything else is set on a page, because somebody standing this
// up should not have to open PHP to do it.
//
// Keys map to environment variables one-for-one — db_file is DB_FILE, secret_key is SECRET_KEY — and
// an environment variable always wins over the value here. That is only useful if you run this in a
// container; served from a directory, this file is the whole configuration.

return [
  // The address you open this on. Not cosmetic: the sign-in cookie takes its Secure flag from here
  // rather than from the request, so a forged Host header cannot turn it off.
  'site_url'    => 'http://local.beeblebrox.cloud',

  // Generate with:  php -r "echo bin2hex(random_bytes(32));"
  //
  // Wraps the API key and the webhook signing secret inside the database, so a copy of the database
  // file on its own is not a credential breach. Changing it makes both unreadable — which is a
  // re-entry job, not a disaster, but do not regenerate it casually.
  'secret_key'  => '',

  // Optional. One SQLite file, created on first run; defaults to data/local.sqlite next to this
  // file. It holds session ids and your password hash, so it must not be downloadable — data/ ships
  // an .htaccess that denies it on Apache, and the diagnostics page checks over the web that the
  // denial actually works. Moving it out of the served directory is the answer no later config
  // change can quietly undo:
  //
  // 'db_file'  => 'C:/beeblebrox/local.sqlite',

  // Optional. Where per-job working files go: the prompt handed to the agent, its raw output, its
  // result. Defaults to jobs/ next to this file. Worth moving off a synced drive — an agent run
  // writes to it continuously.
  //
  // 'job_root' => 'C:/beeblebrox-jobs',
];
