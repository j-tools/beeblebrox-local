<?php
// Every question worth asking about whether this machine is actually set up, and the last few
// envelopes that arrived.
//
// The same checks tools/selftest.php runs, from the same code. Worth running that from a terminal as
// well, as the account the scheduled task uses: half the answers here — whether the agent is on PATH,
// whether the workspaces are readable — differ for a service account, and that difference is the
// usual reason a run works by hand and does nothing on a schedule.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/checks.php';

bbl_session_start();
bbl_require_signin();

$checks = checks_run(true);
$marks = ['pass' => '✓', 'warn' => '!', 'fail' => '✕'];
$counts = array_count_values(array_column($checks, 'state'));
$arrivals = db_all('SELECT * FROM webhook_log ORDER BY id DESC LIMIT 20');

view_header('Diagnostics', true);
?>
<h2>Checks</h2>
<div class="card">
<?php foreach ($checks as $check): ?>
  <div class="check <?= h($check['state']) ?>">
    <span class="mark"><?= $marks[$check['state']] ?></span>
    <span>
      <span class="what"><?= h($check['what']) ?></span>
<?php if ($check['detail'] !== ''): ?>
      <span class="detail"><?= h($check['detail']) ?></span>
<?php endif; ?>
    </span>
  </div>
<?php endforeach; ?>
  <p class="small muted" style="margin:.8rem 0 0">
    <?= (int)($counts['pass'] ?? 0) ?> ok,
    <?= (int)($counts['warn'] ?? 0) ?> warning(s),
    <?= (int)($counts['fail'] ?? 0) ?> failure(s).
    Same checks from a terminal: <code>timeout 120 php tools/selftest.php</code></p>
</div>

<h2>Envelopes that arrived</h2>
<?php if (!$arrivals): ?>
  <p class="muted">None yet. That is expected while polling is doing the work — nothing is posted
     here unless a dispatcher on the instance points at
     <code><?= h(rtrim(bbl_config()['site_url'], '/')) ?>/hook.php</code>.</p>
<?php else: ?>
<div class="card scroll-x">
  <table class="grid">
    <thead><tr><th>When</th><th>From</th><th>Task</th><th></th><th>Why</th></tr></thead>
    <tbody>
<?php foreach ($arrivals as $row): ?>
      <tr>
        <td><?= h(view_ago($row['created_at'])) ?></td>
        <td><?= h($row['remote_addr']) ?></td>
        <td><?= $row['task_id'] ? '#' . (int)$row['task_id'] : '—' ?></td>
        <td><span class="badge <?= (int)$row['accepted'] ? 'job-done' : 'job-attention' ?>">
          <?= (int)$row['accepted'] ? 'accepted' : 'refused' ?></span></td>
        <td><?= h($row['reason']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h2>What to give the instance</h2>
<div class="card">
  <p class="small">To have work pushed here rather than polled for, add a dispatcher on the instance
     with these, and give it the same signing secret you set on the settings page.</p>
  <div class="facts">
    <div><span class="k">Kind</span><span class="v">webhook</span></div>
    <div><span class="k">URL</span><span class="v"><?= h(rtrim(bbl_config()['site_url'], '/')) ?>/hook.php</span></div>
    <div><span class="k">Timeout</span><span class="v">10 seconds is plenty</span></div>
  </div>
  <p class="small muted" style="margin-top:.8rem">Accepting is all this has to do, so the timeout
     covers the acceptance and not the work. Use the dispatcher's own test button first — it sends a
     real signed envelope naming task 0, which no task ever is, and the result shows in the table
     above either way.</p>
</div>

<?php
view_footer();
