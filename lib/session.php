<?php
// Sessions and CSRF for the local pages. Deliberately NOT required by hook.php, which authenticates
// with a signature and must never depend on a cookie.
//
// There is one account, because there is one machine. A password rather than nothing, because these
// pages hold an API key, a signing secret and a button that starts an agent — and "it is only on
// localhost" stops being true the first time somebody opens a tunnel to it.

function bbl_session_lifetime() {
  return bbl_config()['session_lifetime_days'] * 24 * 60 * 60;
}

// In the database rather than in PHP's temp directory, so that clearing that directory does not sign
// you out and so two checkouts on one machine keep their own.
function bbl_session_store() {
  session_set_save_handler(
    function () { return true; },
    function () { return true; },
    function ($id) {
      $row = db_one('SELECT payload FROM sessions WHERE id = ? AND last_active > ?',
        [$id, time() - bbl_session_lifetime()]);
      return $row ? $row['payload'] : '';
    },
    function ($id, $payload) {
      // An anonymous page view opens a session and writes nothing into it. Storing those would add a
      // row per visit that only ages out after the full window.
      if ($payload === '') {
        db_exec('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
      }
      db_exec(
        'INSERT INTO sessions (id, payload, last_active) VALUES (?, ?, ?)
           ON CONFLICT(id) DO UPDATE SET payload = excluded.payload,
                                         last_active = excluded.last_active',
        [$id, $payload, time()]
      );
      return true;
    },
    function ($id) {
      db_exec('DELETE FROM sessions WHERE id = ?', [$id]);
      return true;
    },
    function () {
      db_exec('DELETE FROM sessions WHERE last_active < ?', [time() - bbl_session_lifetime()]);
      return 1;
    }
  );

  // The handlers close over the database connection, and PHP tears globals down before the implicit
  // session write at shutdown — without this the final write finds a dead connection.
  register_shutdown_function('session_write_close');
}

function bbl_session_start() {
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }
  $cfg = bbl_config();

  // Secure comes from the configured site URL and never from the request: behind a tunnel that
  // terminates TLS elsewhere, $_SERVER['HTTPS'] is false on exactly the setup that needs it, and
  // X-Forwarded-Proto is a header anybody can send. The path comes from where this script actually
  // is, which the server knows and a request cannot influence — and which, unlike site_url, is
  // already true on the sign-in screen that precedes setup.
  $cookie = [
    'path'     => bbl_cookie_path(),
    'secure'   => str_starts_with($cfg['site_url'], 'https://'),
    'httponly' => true,
    'samesite' => 'Lax',
  ];

  ini_set('session.gc_maxlifetime', (string)bbl_session_lifetime());
  ini_set('session.use_strict_mode', '1');

  // Not PHPSESSID. Every PHP application on a host uses that name by default, so two of them under
  // one hostname hand each other session ids belonging to a database the other has never seen — and
  // the symptom is a sign-in that succeeds and then asks again, with a row written each time.
  session_name(bbl_cookie_name());
  session_set_cookie_params(['lifetime' => bbl_session_lifetime()] + $cookie);
  bbl_session_store();
  session_start();

  // Sliding rather than counting down from sign-in.
  if (!empty($_SESSION['signed_in'])) {
    setcookie(session_name(), session_id(), ['expires' => time() + bbl_session_lifetime()] + $cookie);
  }

  // If this ever ends up on a public address through a tunnel, it should not also end up in an index.
  header('X-Robots-Tag: noindex, nofollow');
}

function bbl_signed_in() {
  return !empty($_SESSION['signed_in']);
}

// Sends anyone who is not signed in to the sign-in page, remembering where they were going.
function bbl_require_signin() {
  if (bbl_signed_in()) {
    return;
  }
  $here = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
  $query = $_SERVER['QUERY_STRING'] ?? '';
  header('Location: login.php?next=' . urlencode($here . ($query === '' ? '' : '?' . $query)));
  exit;
}

// A new session id on sign-in, so a session fixed before sign-in is not the one that ends up
// authenticated.
// Why signing in can appear to do nothing at all.
//
// The session cookie's Secure flag and its path both come from the configured site_url and never from
// the request — deliberately, so a forged Host header cannot weaken either. The cost is that a
// site_url which does not match how somebody actually reaches these pages produces a cookie the
// browser is then right to refuse to send back. The password is checked, the sign-in succeeds, the
// redirect happens, and the next request arrives with no session: the form returns looking exactly
// like a wrong password, and nothing anywhere says why.
//
// Returns a sentence naming the mismatch, or null when there is nothing wrong.
function bbl_cookie_warning() {
  $configured = (string)bbl_config()['site_url'];

  // The fatal one. A Secure cookie is not sent over plain HTTP, so no session can ever survive a
  // redirect on an install configured for https and reached over http.
  if (str_starts_with($configured, 'https://') && bbl_request_scheme() !== 'https') {
    return 'This install is configured as ' . $configured . ' but you are reading this over plain ' .
      'HTTP. The sign-in cookie is marked Secure because of that setting, so your browser will not ' .
      'send it back and signing in cannot work however right the password is. Reach these pages over ' .
      'https, or correct site_url in config.local.php.';
  }

  // There is deliberately no check here for the cookie's path. It is taken from this script's own
  // directory now, so it always covers the page being read — the mismatch that used to be possible
  // cannot happen any more, and a check for it would only be a thing to keep working.
  return null;
}

function bbl_sign_in() {
  session_regenerate_id(true);
  $_SESSION['signed_in'] = true;
}

function bbl_csrf_token() {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function bbl_csrf_field() {
  return '<input type="hidden" name="csrf_token" value="' . bbl_csrf_token() . '">';
}

// Ends the request rather than returning a boolean: every caller wants the same response to a failed
// check, and a check whose result can be ignored is not really a check.
function bbl_check_csrf() {
  $sent = $_POST['csrf_token'] ?? '';
  if (is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
    return;
  }
  http_response_code(403);
  exit('This form has expired. Reload the page and try again.');
}
// The two forms shown before anyone is signed in cannot use a session token: writing to $_SESSION
// creates a row, so every anonymous visit would leave one behind. A cookie the form echoes back
// proves the request came from a page this application served, which is what is actually needed.
//
// True once anything has been produced, under every SAPI. headers_sent() alone is not enough: PHP's
// development server buffers, so the check passes there while Apache has already flushed and dropped
// the cookie — and the only symptom is "this form has expired" on a form created seconds earlier.
function bbl_output_started() {
  if (headers_sent()) {
    return true;
  }
  $length = ob_get_length();
  return $length !== false && $length > 0;
}
function bbl_pre_auth_start() {
  $token = $_COOKIE[bbl_form_cookie_name()] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    if (bbl_output_started()) {
      throw new RuntimeException(
        'bbl_pre_auth_start() ran after output began. The cookie would be dropped and every '
        . 'sign-in would fail as an expired form.'
      );
    }
    $token = bin2hex(random_bytes(32));
    setcookie(bbl_form_cookie_name(), $token, [
      'expires'  => time() + 3600,
      'path'     => bbl_cookie_path(),
      'secure'   => str_starts_with(bbl_config()['site_url'], 'https://'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    $_COOKIE[bbl_form_cookie_name()] = $token;
  }
  $GLOBALS['bbl_pre_auth_token'] = $token;
}

function bbl_pre_auth_field() {
  if (empty($GLOBALS['bbl_pre_auth_token'])) {
    throw new RuntimeException(
      'bbl_pre_auth_field() was called without bbl_pre_auth_start() having run before '
      . 'output. Emitting a token with no matching cookie would make the form unusable.'
    );
  }
  return '<input type="hidden" name="form_token" value="' . $GLOBALS['bbl_pre_auth_token'] . '">';
}

function bbl_check_pre_auth() {
  $cookie = $_COOKIE[bbl_form_cookie_name()] ?? '';
  $sent = $_POST['form_token'] ?? '';
  if (is_string($sent) && $sent !== '' && $cookie !== '' && hash_equals($cookie, $sent)) {
    return;
  }
  http_response_code(403);
  exit('This form has expired. Reload the page and try again.');
}

// Only same-site absolute paths are honored, so neither a doctored form field nor a crafted ?return=
// can turn this application into somebody else's open redirect.
function bbl_safe_return($path, $fallback) {
  if (!$path || $path[0] !== '/' || str_starts_with($path, '//')) {
    return $fallback;
  }
  return $path;
}
