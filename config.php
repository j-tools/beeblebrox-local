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

function bbl_local_config_path() {
  return __DIR__ . '/config.local.php';
}

// A key nobody has to think of. Thirty-two random bytes as hex is not a decision, and asking a person
// to produce one by hand was asking them to open a PHP file to paste a string a computer is better at
// choosing.
function bbl_generate_secret_key() {
  return bin2hex(random_bytes(32));
}

// Writes config.local.php, merging over whatever is already in it.
//
// A PHP file rather than a plain one, and that is the whole reason it is shaped like this: a web
// server that has stopped running PHP serves a .key or a .env as text, while this outputs nothing at
// all. The file sits in the directory being served, so that difference is the one protecting the key
// that decrypts everything in the database.
//
// Returns true when it was written. False means the directory is not writable, which is a legitimate
// state — the caller then shows the content to paste, which is still better than asking somebody to
// invent the key themselves.
function bbl_write_local_config(array $values) {
  $written = @file_put_contents(bbl_local_config_path(), bbl_local_config_text($values), LOCK_EX);
  if ($written === false) {
    return false;
  }
  // It holds the key that decrypts every stored secret, so it is not world-readable where that means
  // anything. Windows ignores this, which is why it is not the only protection.
  @chmod(bbl_local_config_path(), 0600);
  return true;
}

// The file's whole content, merged over whatever is already in it. Shared with the page that shows it
// to be pasted by hand, so what somebody copies is exactly what would have been written.
function bbl_local_config_text(array $values) {
  $path = bbl_local_config_path();
  $existing = file_exists($path) ? (array)(require $path) : [];
  $merged = array_merge($existing, $values);

  $lines = [
    '<?php',
    '// Written by the setup page. Safe to edit by hand afterwards — nothing rewrites it unless you',
    '// go through setup again, and then only the values it asks about.',
    '//',
    '// This is a PHP file rather than a plain one on purpose: it lives in the directory being served,',
    '// and a server that has stopped running PHP would hand over a .key or a .env as text while this',
    '// outputs nothing. See config.local.example.php for what each value does.',
    '',
    'return [',
  ];
  foreach ($merged as $key => $value) {
    // var_export, so a value containing a quote is a value rather than a syntax error.
    $lines[] = sprintf('  %-13s => %s,', var_export((string)$key, true), var_export($value, true));
  }
  $lines[] = '];';
  return implode("\n", $lines) . "\n";
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
