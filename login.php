<?php
// Sign in, and — on a fresh install — set the password in the first place.
//
// The two are one page because they are one moment: the first person to open this is setting it up,
// and sending them to a separate URL to do it is a step that can be got wrong or skipped.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/html.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_pre_auth_start();

$next = preg_replace('/[^A-Za-z0-9_.?=&-]/', '', (string)($_GET['next'] ?? 'index.php')) ?: 'index.php';
$hash = (string)setting('admin_password_hash');
$first_run = $hash === '';
$error = null;

if (bbl_signed_in()) {
  header('Location: ' . $next);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_pre_auth();

  if ($first_run) {
    $password = (string)($_POST['password'] ?? '');
    $again = (string)($_POST['password_again'] ?? '');
    if (strlen($password) < 10) {
      $error = 'Use at least 10 characters.';
    } elseif ($password !== $again) {
      $error = 'The two entries do not match.';
    } else {
      setting_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
      bbl_sign_in();
      // Straight into setup rather than the settings page. Somebody who has just set a password has
      // never seen any of this, and a screen of thirteen fields is the wrong thing to hand them.
      header('Location: setup.php');
      exit;
    }
  } else {
    // A fixed comparison cost whether or not the password is right, and no hint about which part was
    // wrong — there is one account, so "wrong password" is the only thing it could be anyway.
    if (password_verify((string)($_POST['password'] ?? ''), $hash)) {
      bbl_sign_in();
      header('Location: ' . $next);
      exit;
    }
    $error = 'That is not the password.';
    usleep(400000);
  }
}

view_header($first_run ? 'Set a password' : 'Sign in');
?>
<div class="narrow-wrap">
<?php if ($first_run): ?>
  <h2>First run</h2>
  <p>Nobody has set a password for this receiver yet. Choose one — it is the only thing between this
     machine's API key and whoever else can reach this address.</p>
  <?php view_flash($error); ?>
  <form method="post" class="card stack">
    <?= bbl_pre_auth_field() ?>
    <label>New password
      <input type="password" name="password" autocomplete="new-password" autofocus required>
      <small>At least 10 characters.</small>
    </label>
    <label>Again
      <input type="password" name="password_again" autocomplete="new-password" required>
    </label>
    <button type="submit">Set it and continue</button>
  </form>
<?php else: ?>
  <h2>Sign in</h2>
  <?php view_flash($error); ?>
  <form method="post" class="card stack">
    <?= bbl_pre_auth_field() ?>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" autofocus required>
    </label>
    <button type="submit">Sign in</button>
  </form>
  <p class="small muted">Forgotten it? Clear the row from the database and reload this page:
     <code>DELETE FROM settings WHERE name = 'admin_password_hash';</code></p>
<?php endif; ?>
</div>
<?php
view_footer();
