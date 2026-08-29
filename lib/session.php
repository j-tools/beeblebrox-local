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
           ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_active = VALUES(last_active)',
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

  // Secure is decided from the configured site URL, never from $_SERVER['HTTPS']. Behind a tunnel
  // that terminates TLS elsewhere, that test is false on exactly the setup that needs the flag.
  $cookie = [
    'path'     => '/',
    'secure'   => str_starts_with($cfg['site_url'], 'https://'),
    'httponly' => true,
    'samesite' => 'Lax',
  ];

  ini_set('session.gc_maxlifetime', (string)bbl_session_lifetime());
  ini_set('session.use_strict_mode', '1');
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
  $token = $_COOKIE['bbl_form'] ?? '';
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    if (bbl_output_started()) {
      throw new RuntimeException(
        'bbl_pre_auth_start() ran after output began. The cookie would be dropped and every '
        . 'sign-in would fail as an expired form.'
      );
    }
    $token = bin2hex(random_bytes(32));
    setcookie('bbl_form', $token, [
      'expires'  => time() + 3600,
      'path'     => '/',
      'secure'   => str_starts_with(bbl_config()['site_url'], 'https://'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    $_COOKIE['bbl_form'] = $token;
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
  $cookie = $_COOKIE['bbl_form'] ?? '';
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
