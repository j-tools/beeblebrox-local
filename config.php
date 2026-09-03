<?php
// Central config loader, same shape as the platform's: environment variables first, falling back to
// config.local.php. Only these values differ between one person's laptop and another's — the code is
// identical, which is what makes this shareable with a customer at all.
//
// What lives here is only what has to exist before the database does: how to reach the database, the
// key that unwraps stored secrets, and where this site answers. Everything operational — which
// instance to talk to, the API key, the webhook secret, which roles this machine works — lives in the
// database and is edited on the settings page, because a customer configuring this should not have to
// open a PHP file.

function bbl_config() {
  static $cfg = null;
  if ($cfg !== null) {
    return $cfg;
  }

  $local_file = __DIR__ . '/config.local.php';
  $local = file_exists($local_file) ? require $local_file : [];
  $env = function ($key, $default = null) {
    return getenv($key) !== false ? getenv($key) : $default;
  };

  $cfg = [
    // One file, created on first run. Inside the directory being served by default, because that is
    // the answer that needs no decisions — and it is why data/ ships with an .htaccess and why the
    // diagnostics page checks, over the web, that the file cannot actually be downloaded.
    'db_file'     => $env('DB_FILE',     $local['db_file']     ?? __DIR__ . '/data/local.sqlite'),

    // Where this receiver answers. Also decides the session cookie's Secure flag, so it is never
    // derived from the request — a forged Host header cannot turn it off.
    'site_url'    => $env('SITE_URL',    $local['site_url']    ?? 'http://local.beeblebrox.cloud'),

    // Wraps the API key and the webhook signing secret in the database. It lives only here, so a
    // database dump on its own is not a credential breach. Losing it means re-entering both.
    'secret_key'  => $env('SECRET_KEY',  $local['secret_key']  ?? ''),

    // Where job directories are written: the prompt handed to the agent, its raw output, its result.
    // Outside the repo by default, because they are working files and some of them are large.
    'job_root'    => $env('JOB_ROOT',    $local['job_root']    ?? __DIR__ . '/jobs'),

    'session_lifetime_days' => (int)$env('SESSION_LIFETIME_DAYS', $local['session_lifetime_days'] ?? 30),
  ];
  return $cfg;
}

// The public Beeblebrox site. Anything explaining Beeblebrox itself lives there, as opposed to an
// instance, which belongs to one company. Not configurable — it is the same site for every customer,
// and a setting nobody would ever change is a setting somebody can get wrong.
function bbl_public_site() {
  return 'https://beeblebrox.cloud';
}

// Installing Claude Code so this can drive it. A page on the public site rather than a section of
// INSTALL.md, because the person who needs it is looking at a browser, not at a repository — and an
// anchor rather than a page of its own, since what to install is one paragraph of what this is.
function bbl_agent_install_url() {
  return bbl_public_site() . '/local/#claude-code';
}

// A human label for which instance this checkout serves, used in the page title and in log lines so a
// beta window and a production window are never mistaken for each other.
function bbl_env_label() {
  $host = parse_url(bbl_config()['site_url'], PHP_URL_HOST) ?: 'local';
  return $host;
}

function bbl_is_configured() {
  return bbl_config()['db_file'] !== '';
}
