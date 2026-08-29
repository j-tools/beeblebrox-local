<?php
// Copy to config.local.php and fill in. config.local.php is gitignored and must never be committed.
//
// Keys map to environment variables one-for-one — db_host is DB_HOST, secret_key is SECRET_KEY — and
// an environment variable always wins over the value here. That is only useful if you run this in a
// container; on XAMPP this file is the whole configuration.
//
// One checkout per upstream instance. The beta checkout sits on the beta branch and points at the
// beta database and the beta instance; the production checkout sits on main. Nothing in the code
// knows which it is, and that is the point.

return [
  'db_host'     => '127.0.0.1',
  'db_port'     => 3306,

  // beeblebrox_local_zaphod for the production checkout, beeblebrox_local_beta for the beta one.
  'db_name'     => 'beeblebrox_local_zaphod',
  'db_user'     => 'id_beeblebrox_local_zaphod',
  'db_password' => '',

  // The vhost this checkout answers on. http is fine for a receiver on the same machine; it is what
  // keeps the session cookie working locally without an if-development branch anywhere in the code.
  'site_url'    => 'http://local.beeblebrox.cloud',

  // Generate with:  php -r "echo bin2hex(random_bytes(32));"
  // Must be set before an API key or a webhook secret can be stored. Changing it makes both
  // unreadable, which is a re-entry job, not a disaster.
  'secret_key'  => '',

  // Where per-job working files go. Anywhere writable; keep it off a synced drive if you can, because
  // an agent run writes to it continuously.
  'job_root'    => 'C:/beeblebrox-jobs',
];
