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

// The address this page was actually reached on, for setup to offer as a suggestion.
//
// Includes the directory, which is the part that is easy to forget: served from
// /beeblebrox-local/setup.php, the answer is https://host/beeblebrox-local and not https://host. A
// site_url missing that path would put the wrong URL in front of every dispatcher and scope the
// sign-in cookie to the whole host rather than to this application.
//
// Only ever a suggestion a person confirms. Everywhere the site_url is used for a decision it comes
// from configuration, precisely so a forged Host header cannot make it.
// How this request actually arrived, as opposed to how the configuration says it should have.
//
// X-Forwarded-Proto matters here rather than being sloppy: a worker reached through a tunnel gets
// plain HTTP at the door, and reading only $_SERVER['HTTPS'] would call that connection insecure
// when it is not.
function bbl_request_scheme() {
  $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
  if ($forwarded === 'https' || $forwarded === 'http') {
    return $forwarded;
  }
  return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
}

function bbl_guess_site_url() {
  // Suggesting http:// to somebody who then accepts it would silently drop the Secure flag from
  // their session cookie, so the scheme is taken from how the request really arrived.
  $scheme = bbl_request_scheme();

  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // dirname of the running script: '/beeblebrox-local' in a subdirectory, '/' or '\' at the root.
  // Backslashes because dirname uses the platform separator, and this runs on Windows more often
  // than not.
  $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

  return $scheme . '://' . $host . $dir;
}

// What the sign-in cookie is scoped to, taken from the configured address rather than the request.
//
// A subdirectory install that scoped its cookie to '/' would hand it to everything else on the same
// host — and two of these on one host would sign each other out, since they would collide on both
// name and path.
// The name and the path of this install's cookies, both taken from where it actually sits — on disk
// and on the server — rather than from site_url.
//
// This matters far more than it sounds. Every PHP application defaults to a cookie called PHPSESSID
// on path /, so two of them under one hostname overwrite each other's session: signing in to one
// silently signs you out of the other, and arriving at a page holding the wrong installation's id
// looks exactly like a session that expired for no reason. A development machine serving several
// sites from one hostname hits this at once, and so does anyone keeping a staging copy beside a live
// one — three copies of this very application on one host is what found it.
//
// Taking the path from site_url could not fix that, because site_url does not exist yet on the screen
// where it is first needed: setup writes it, and setup is behind the sign-in. So the path comes from
// the script's own directory, and the name carries a digest of the installation directory so that no
// two copies can collide however they are served. Both are facts the server knows about itself;
// neither can be influenced by a request, which is what site_url was being used to avoid.
// Whether an address is one where traffic never leaves the machine. The same test browsers use to
// decide a page is a secure context, and the reason a worker on somebody's own laptop needs no
// certificate: there is no network between the browser and the server to protect.
//
// Hostnames that merely resolve to a loopback address are deliberately not accepted. Whether they do
// is a DNS answer that can change, it cannot be checked from the browser's side at all, and a name
// like worker.example.internal is exactly the case where somebody believes it is local and it is not.
function site_url_is_local($url) {
  $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
  return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
    || str_ends_with($host, '.localhost');
}

function bbl_cookie_name() {
  static $name = null;
  if ($name === null) {
    $name = 'bbl_' . substr(sha1(__DIR__), 0, 10);
  }
  return $name;
}

// The one-hour token that ties a submitted sign-in form to the browser that was shown it. Same
// reasoning, same digest, so two installs cannot invalidate each other's forms either.
function bbl_form_cookie_name() {
  return bbl_cookie_name() . '_form';
}

function bbl_cookie_path() {
  // dirname of the running script: '/beeblebrox-local' in a subdirectory, or the root itself.
  // DIRECTORY_SEPARATOR rather than a literal backslash: dirname returns the platform's separator,
  // and this runs on Windows more often than not. SCRIPT_NAME is decided by the server, never sent
  // by the client, which is what makes it safe to scope a cookie by.
  $dir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
  return $dir === '' ? '/' : $dir . '/';
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

// Which build this copy is, from the VERSION file the release archive stamps.
//
// Returns ['commit' => '936cbb6…', 'built' => '2026-09-04'] for a copy unpacked from a zip, or
// ['commit' => null, 'built' => null] in a checkout — git archive's export-subst only fills those in
// when it builds an archive, so a checkout legitimately has placeholders and `git log` is the answer
// there instead.
//
// This exists because "am I on the newest one?" is unanswerable from a zip install otherwise: the
// information ships in the archive and nothing was reading it.
function bbl_build() {
  // BUILD is written into the archive by the release workflow — how many commits main had, which
  // is a number people can compare out loud where a commit hash only says "different".
  $number = null;
  $build_file = __DIR__ . '/BUILD';
  if (is_file($build_file) && preg_match('/^\s*(\d+)\s*$/', (string)file_get_contents($build_file), $m)) {
    $number = (int)$m[1];
  }

  $file = __DIR__ . '/VERSION';
  if (!is_file($file)) {
    return ['number' => $number, 'commit' => null, 'built' => null];
  }
  $text = (string)file_get_contents($file);
  $commit = null;
  $built = null;
  // \s*$ rather than $: the archive carries CRLF when the repository normalises line endings,
  // and a bare $ would not match past the carriage return. Which is to say this read NULL on
  // every real release until it was tried against one.
  if (preg_match('/^commit\s+([0-9a-f]{7,40})\s*$/mi', $text, $m)) {
    $commit = $m[1];
  }
  if (preg_match('/^built\s+(\d{4}-\d{2}-\d{2})\s*$/mi', $text, $m)) {
    $built = $m[1];
  }
  return ['number' => $number, 'commit' => $commit, 'built' => $built];
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
