<?php
// Two pages at one address, and deliberately so.
//
// Signed out, this explains what the thing is to somebody who has just been handed the repository. It
// is the only page written for a reader who does not already know, which makes it the landing page,
// and it says nothing about this particular machine — no instance name, no counts, nothing that
// would matter if the address ever ends up somewhere it should not.
//
// Signed in, it is the dashboard: what is set up, what is in flight, what needs a person.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/jobs.php';
require_once __DIR__ . '/lib/view.php';

// The database is the first thing that can be wrong, and a stack trace is a poor way to say so on the
// page somebody opens first.
try {
  db();
  $reachable = true;
} catch (Throwable $e) {
  $reachable = false;
  $db_error = $e->getMessage();
}

if ($reachable) {
  bbl_session_start();
}

if (!$reachable || !bbl_signed_in()) {
  view_header('A worker on your own machine', false);
  ?>
  <h2>What this is</h2>
  <p class="lede">A Beeblebrox instance runs the pipeline. This runs the work — on your own machine,
     in your own checkouts, with your own agent and your own keys. Nothing leaves the machine except
     the result.</p>

  <ol class="steps">
    <li><strong>Work arrives</strong>
      <span>Either the instance posts a signed envelope here, or this asks the instance what is open.
        Either way the envelope names a task and nothing more — the briefing is fetched separately,
        so it is never in transit and never in a log.</span></li>
    <li><strong>It is claimed</strong>
      <span>Claiming succeeds exactly once, so two machines cannot start the same task and a
        redelivered envelope cannot start it twice.</span></li>
    <li><strong>The agent runs</strong>
      <span>In the directory you mapped to that project, with the whole ticket as its prompt, under
        whatever permissions you chose. It writes a status and a summary to a result file.</span></li>
    <li><strong>The result goes back</strong>
      <span>Status and output, with what the run cost. The flow on the instance decides what happens
        next — a worker here never names one.</span></li>
  </ol>

  <div class="card">
    <h3 style="margin-top:0">Setting one up</h3>
    <p class="small">Copy <code>config.local.example.php</code>, load <code>db/schema.sql</code>,
       point a vhost at this directory, then sign in and fill in the settings page.
       <code>INSTALL.md</code> has it in full, including the scheduled task that makes any of it
       happen on its own.</p>
  </div>

<?php if (!$reachable): ?>
  <p class="error">This copy cannot reach its database yet: <?= h($db_error) ?></p>
<?php else: ?>
  <p><a href="login.php">Sign in</a></p>
<?php endif; ?>
<?php
  view_footer();
  exit;
}

// --- signed in ---------------------------------------------------------------------------------

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check') {
  bbl_check_csrf();
  require_once __DIR__ . '/lib/runner.php';
  $lock = runner_lock();
  if ($lock === null) {
    $notice = 'The runner is busy with a pass right now, so nothing was done here.';
  } else {
    try {
      // Reporting and polling only. An agent run belongs to the scheduled task, not to a request
      // somebody is waiting on.
      $pass = runner_pass(false);
      $notice = sprintf('%d reported, %d taken. %s',
        $pass['summary']['reported'], $pass['summary']['accepted'],
        $pass['log'] ? implode(' · ', $pass['log']) : 'Nothing was waiting.');
    } catch (Throwable $e) {
      $notice = 'That did not work: ' . $e->getMessage();
    }
    runner_unlock($lock);
  }
}

$gaps = settings_gaps();
$counts = job_counts();
$recent = jobs_recent(12);
$attention = jobs_by_status('attention', 20);

view_header('Dashboard', true);
view_flash(null, $notice);
?>

<?php if ($gaps): ?>
  <h2>Not ready yet</h2>
  <div class="card">
    <ul>
<?php foreach ($gaps as $gap): ?>
      <li><?= h($gap) ?></li>
<?php endforeach; ?>
    </ul>
    <div class="actions">
      <a href="settings.php" class="secondary">Settings</a>
      <a href="diagnostics.php" class="secondary">Diagnostics</a>
    </div>
  </div>
<?php endif; ?>

<h2>Now</h2>
<div class="card">
  <div class="facts">
    <div><span class="k">Waiting to run</span><span class="v"><?= (int)$counts['queued'] ?></span></div>
    <div><span class="k">Running</span><span class="v"><?= (int)$counts['running'] ?></span></div>
    <div><span class="k">Reporting back</span><span class="v"><?= (int)$counts['reporting'] ?></span></div>
    <div><span class="k">Need a person</span><span class="v"><?= (int)$counts['attention'] ?></span></div>
    <div><span class="k">Done</span><span class="v"><?= (int)$counts['done'] ?></span></div>
    <div><span class="k">Last pass</span><span class="v"><?=
      h(setting('last_pass_at') ? view_ago(setting('last_pass_at')) : 'never') ?></span></div>
  </div>
  <form method="post" class="actions">
    <?= bbl_csrf_field() ?>
    <input type="hidden" name="action" value="check">
    <button type="submit">Check the instance now</button>
    <a href="jobs.php" class="secondary">All jobs</a>
  </form>
  <p class="small muted">Reports what is waiting and takes anything new. Starting an agent is the
     scheduled task's job — it can take an hour and a browser will not wait for it.</p>
</div>

<?php if ($attention): ?>
  <h2>Needs you</h2>
<?php foreach ($attention as $job) { view_job_row($job); } ?>
<?php endif; ?>

<h2>Recent</h2>
<?php if (!$recent): ?>
  <p class="muted">Nothing has arrived yet.
<?php if (setting_bool('accept_webhooks')): ?>
     Point a dispatcher at <code><?= h(rtrim(bbl_config()['site_url'], '/')) ?>/hook.php</code>,
     or wait for a poll to find something.
<?php endif; ?>
  </p>
<?php else: ?>
<?php foreach ($recent as $job) { view_job_row($job); } ?>
<?php endif; ?>

<?php
view_footer();
