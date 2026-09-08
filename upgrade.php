<?php
// Which build this is, which one is newest, and — where this copy can replace its own files — the
// button that does it.
//
// A page rather than a button in the drawer, because there is more to say than fits in a drawer: what
// will be replaced, what will not be touched, where the previous build is kept, and on a machine that
// cannot write to itself, why the button is not here and what to do instead.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/updates.php';
require_once __DIR__ . '/lib/upgrade.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_require_signin();

$error = null;
$notice = null;
$applied = null;

$build = bbl_build();
$blockers = upgrade_blockers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $action = $_POST['action'] ?? '';
  try {
    if ($blockers) {
      throw new RuntimeException('This copy cannot replace its own files, so there is nothing this ' .
        'button could do.');
    }
    if ($action === 'upgrade') {
      $to = (int)($_POST['to'] ?? 0);
      // The number comes from the form rather than from another look at GitHub, so what is installed
      // is the build the page offered and not whatever appeared in the meantime.
      if ($to <= (int)$build['number']) {
        throw new RuntimeException('That build is not newer than this one.');
      }
      $applied = upgrade_apply(upgrade_stage($to), $to);
      $notice = "Build {$applied['to']} is installed.";
    } elseif ($action === 'restore') {
      $back = upgrade_restore();
      $notice = "Build {$back['build']} is back, from {$back['files']} kept files.";
    }
    // Read again: everything below describes the copy that is now on disk, and this request began
    // before it was.
    $build = bbl_build();
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$newest = updates_latest();
$backup = upgrade_backup_available();
$manifest = upgrade_read_manifest();

view_header('Upgrade', true);
view_flash($error, $notice);
?>

<h1>Upgrade</h1>

<div class="card">
  <div class="facts">
    <div><span class="k">Installed</span><span class="v"><?= $build['number'] === null
      ? 'a checkout' : 'build ' . (int)$build['number'] ?></span></div>
    <div><span class="k">Newest published</span><span class="v"><?= $newest === null
      ? 'not known' : 'build ' . (int)$newest ?></span></div>
<?php if ($build['commit'] !== null): ?>
    <div><span class="k">Commit</span><span class="v"><?= h(substr($build['commit'], 0, 7)) ?></span></div>
<?php endif; ?>
  </div>
</div>

<?php if ($applied !== null): ?>
<div class="card">
  <h2>What just happened</h2>
  <p class="small"><?= (int)$applied['files'] ?> files written, <?= (int)$applied['backed_up'] ?> of
     them replacing a file that was kept first in <code><?= h($applied['backup']) ?></code>.</p>
<?php if ($applied['removed']): ?>
  <p class="small"><?= count($applied['removed']) ?> file(s) the previous build installed and this one
     no longer has were removed: <code><?= h(implode(', ', $applied['removed'])) ?></code>.</p>
<?php endif; ?>
<?php if ($applied['migrations']['failed'] !== null): ?>
  <p class="error">The files are in place, but a migration failed:
     <code><?= h($applied['migrations']['failed']) ?></code> —
     <?= h($applied['migrations']['error']) ?></p>
<?php elseif ($applied['migrations']['applied']): ?>
  <p class="small">Migrations applied:
     <code><?= h(implode(', ', $applied['migrations']['applied'])) ?></code>.</p>
<?php else: ?>
  <p class="small muted">No migrations to apply.</p>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($blockers): ?>
<h2>Why there is no button here</h2>
<div class="card">
<?php foreach ($blockers as $blocker): ?>
  <p class="small"><?= h($blocker) ?></p>
<?php endforeach; ?>
  <p class="small">Unpacking the download over this directory does the same job:
     <a href="<?= h(updates_download_url()) ?>" target="_blank" rel="noopener">how to do that</a>.
     Your <code>config.local.php</code> and everything in <code>data/</code> are not in the download,
     so they survive it either way.</p>
</div>
<?php elseif ($newest !== null && $build['number'] !== null && $newest > (int)$build['number']): ?>
<h2>Build <?= (int)$newest ?> is out</h2>
<div class="card">
  <p class="small">This will fetch build <?= (int)$newest ?> from GitHub, check it against the hash
     published with it, unpack it under <code>data/</code> and copy it over this directory. Every file
     it replaces is kept first, so there is a way back on the next screen.</p>
  <p class="small"><strong>Not touched:</strong> <code>config.local.php</code>, and everything in
     <code>data/</code> — the database, the jobs, this backup. None of them is in the download.</p>
  <p class="small muted">A job running right now holds the files, and this waits for it rather than
     interrupting it — if something is in flight, come back when the dashboard is quiet. The copy
     itself takes a second or two.</p>
  <form method="post">
    <?= bbl_csrf_field() ?>
    <input type="hidden" name="action" value="upgrade">
    <input type="hidden" name="to" value="<?= (int)$newest ?>">
    <button type="submit">Install build <?= (int)$newest ?></button>
  </form>
</div>
<?php else: ?>
<div class="card">
  <p class="small"><?= $newest === null
    ? 'GitHub could not be asked which build is newest, so there is nothing to compare this one with.
       The check runs again within the hour.'
    : 'This is the newest published build. Nothing to do.' ?></p>
</div>
<?php endif; ?>

<?php if ($backup !== null): ?>
<h2>Going back</h2>
<div class="card">
  <p class="small">Build <?= (int)$backup['build'] ?> is kept, <?= (int)$backup['files'] ?> files of it,
     from the last time this was upgraded. Restoring copies them back over this directory — the
     database is not touched, so a migration the newer build applied stays applied.</p>
  <form method="post">
    <?= bbl_csrf_field() ?>
    <input type="hidden" name="action" value="restore">
    <button type="submit" class="link">restore build <?= (int)$backup['build'] ?></button>
  </form>
</div>
<?php endif; ?>

<?php if ($manifest !== null): ?>
<p class="muted small"><?= count($manifest['files']) ?> files were installed by build
   <?= (int)$manifest['build'] ?> on <?= h(substr((string)$manifest['installed_at'], 0, 10)) ?>. That
   list is what lets the next upgrade remove a file this application no longer ships without touching
   anything you added yourself.</p>
<?php endif; ?>

<?php
view_footer();
