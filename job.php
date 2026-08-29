<?php
// One job in full: what was asked, what ran, what it said, and what was sent back.
//
// This is where somebody goes when a run did not do what they expected, so the raw material is all
// here rather than a summary of it — the prompt the agent actually got, its actual answer, and the
// log in order.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_require_signin();

$job = job_by_id((int)($_GET['id'] ?? 0));
if (!$job) {
  http_response_code(404);
  view_header('No such job', true);
  echo '<p class="error">There is no job with that id.</p>';
  view_footer();
  exit;
}

$notice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $action = $_POST['action'] ?? '';

  if ($action === 'retry') {
    // Back to 'claimed' rather than 'queued' when this machine already holds the claim. Claiming
    // again would come back 409 — the instance would be refusing us on our own claim — and the job
    // would be set aside as somebody else's.
    $held = $job['claimed_at'] !== null;
    job_set((int)$job['id'], [
      'status' => $held ? 'claimed' : 'queued', 'error' => null,
      'finished_at' => null, 'started_at' => null,
      'report_attempts' => 0, 'report_next_at' => null,
    ]);
    job_log((int)$job['id'], 'Queued again by hand' .
      ($held ? ', keeping the claim this machine already holds.' : '.'));
    $notice = 'Queued again. The next pass will pick it up.';
  }

  if ($action === 'report') {
    // For the case the agent did the work and the status was the only thing wrong: fix the status
    // here and hand it back rather than throwing away a run that cost real money.
    require_once __DIR__ . '/lib/runner.php';
    job_set((int)$job['id'], [
      'status' => 'reporting', 'reported_status' => trim((string)($_POST['status'] ?? '')),
      'report_attempts' => 0, 'report_next_at' => date('Y-m-d H:i:s'), 'error' => null,
    ]);
    $log = [];
    runner_report(job_by_id((int)$job['id']), $log);
    $notice = implode(' · ', $log) ?: 'Sent.';
  }

  if ($action === 'dismiss') {
    job_set((int)$job['id'], ['status' => 'skipped', 'finished_at' => date('Y-m-d H:i:s')]);
    job_log((int)$job['id'], 'Set aside by hand. Nothing was reported to the instance.');
    $notice = 'Set aside. Nothing was sent — the task is still claimed on the instance.';
  }

  $job = job_by_id((int)$job['id']);
}

$events = job_events((int)$job['id']);
$project = project_for_upstream($job['project_id']);
$task_url = instance_base() === '' ? null : instance_base() . '/task.php?id=' . (int)$job['upstream_task_id'];

// Read from disk rather than the database: they can be megabytes, and there is no reason to carry
// that through a table when the file is right there.
$prompt = $job['job_dir'] && is_file($job['job_dir'] . '/prompt.md')
  ? file_get_contents($job['job_dir'] . '/prompt.md') : null;
$stdout = $job['job_dir'] && is_file($job['job_dir'] . '/stdout.txt')
  ? file_get_contents($job['job_dir'] . '/stdout.txt') : null;
$stderr = $job['job_dir'] && is_file($job['job_dir'] . '/stderr.txt')
  ? file_get_contents($job['job_dir'] . '/stderr.txt') : null;

view_header('Task ' . (int)$job['upstream_task_id'], true);
view_flash(null, $notice);
?>

<h2>Task <?= (int)$job['upstream_task_id'] ?>
  <span class="muted"><?= h($job['nickname'] ?: ($job['role_slug'] ?: 'no role')) ?></span></h2>

<div class="card">
  <p>
    <span class="badge job-<?= h($job['status']) ?>"><?= h(view_label($job['status'])) ?></span>
<?php if (!empty($job['reported_status'])): ?>
    <span class="badge"><?= h($job['reported_status']) ?></span>
<?php endif; ?>
<?php if (!empty($job['is_test'])): ?>
    <span class="badge test">test</span>
<?php endif; ?>
    <span class="muted small"><?= h(job_statuses()[$job['status']] ?? '') ?></span>
  </p>

  <div class="facts">
    <div><span class="k">Heard about it</span><span class="v"><?= h($job['source']) ?>,
      <?= h(view_ago($job['received_at'])) ?></span></div>
    <div><span class="k">Ticket</span><span class="v"><?= $job['upstream_chain_id']
      ? '#' . (int)$job['upstream_chain_id'] : '—' ?></span></div>
    <div><span class="k">Project</span><span class="v"><?= h($project['name'] ?? ($job['project_id']
      ? 'unmapped (' . (int)$job['project_id'] . ')' : 'none')) ?></span></div>
    <div><span class="k">Attempt</span><span class="v"><?= (int)$job['attempt'] ?>,
      depth <?= (int)$job['depth'] ?></span></div>
<?php if ($job['duration_ms'] !== null): ?>
    <div><span class="k">Ran for</span><span class="v"><?= h(view_duration((int)$job['duration_ms'])) ?></span></div>
<?php endif; ?>
<?php if ($job['model']): ?>
    <div><span class="k">Model</span><span class="v"><?= h($job['model']) ?></span></div>
<?php endif; ?>
<?php if ($job['input_tokens'] !== null || $job['output_tokens'] !== null): ?>
    <div><span class="k">Tokens</span><span class="v"><?= (int)$job['input_tokens'] ?> in,
      <?= (int)$job['output_tokens'] ?> out</span></div>
<?php endif; ?>
<?php if ($job['cost_microdollars'] !== null): ?>
    <div><span class="k">Cost</span><span class="v"><?= h(view_money((int)$job['cost_microdollars'])) ?></span></div>
<?php endif; ?>
<?php if ($job['exit_code'] !== null): ?>
    <div><span class="k">Exit code</span><span class="v"><?= (int)$job['exit_code'] ?></span></div>
<?php endif; ?>
<?php if ($job['workspace_path']): ?>
    <div><span class="k">Workspace</span><span class="v"><?= h($job['workspace_path']) ?></span></div>
<?php endif; ?>
  </div>

  <div class="actions">
<?php if ($task_url): ?>
    <a class="secondary" href="<?= h($task_url) ?>" target="_blank" rel="noopener">Open on the instance</a>
<?php endif; ?>
<?php if (in_array($job['status'], ['attention', 'failed', 'skipped'], true)): ?>
    <form method="post" class="inline">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="action" value="retry">
      <button type="submit" class="secondary">Run it again</button>
    </form>
<?php endif; ?>
<?php if ($job['status'] === 'attention'): ?>
    <form method="post" class="inline">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="action" value="dismiss">
      <button type="submit" class="secondary">Set aside</button>
    </form>
<?php endif; ?>
  </div>
</div>

<?php if ($job['error']): ?>
  <h2>Why it stopped</h2>
  <div class="card"><div class="output"><?= h($job['error']) ?></div></div>
<?php endif; ?>

<?php if ($job['status'] === 'attention' && trim((string)$job['output']) !== ''): ?>
  <h2>Send it anyway</h2>
  <div class="card">
    <p class="small">The agent's output is below and nothing has been sent. If the only thing wrong
       was the status it chose, put the right one here and hand the work back rather than paying for
       the run twice.</p>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="action" value="report">
      <label>Status
        <input type="text" name="status" value="<?= h((string)$job['reported_status']) ?>"
               placeholder="e.g. passed">
        <small>Exactly as the flow on the instance spells it. Leave it empty to report with no
          status, which asks a person there to decide.</small>
      </label>
      <button type="submit">Report this to the instance</button>
    </form>
  </div>
<?php endif; ?>

<?php if (trim((string)$job['output']) !== ''): ?>
  <h2>What it said</h2>
  <div class="card"><div class="output"><?= h($job['output']) ?></div></div>
<?php endif; ?>

<?php if (trim((string)$job['communication']) !== ''): ?>
  <h2>Message for a person</h2>
  <div class="card"><p><?= nl2br(h($job['communication'])) ?></p></div>
<?php endif; ?>

<h2>Log</h2>
<div class="card">
  <ul class="events">
<?php foreach ($events as $event): ?>
    <li>
      <time><?= h(substr((string)$event['created_at'], 11, 8)) ?></time>
      <pre class="<?= h($event['level']) ?>"><?= h($event['message']) ?></pre>
    </li>
<?php endforeach; ?>
<?php if (!$events): ?>
    <li><time></time><span class="muted">Nothing logged.</span></li>
<?php endif; ?>
  </ul>
</div>

<?php if ($stderr !== null && trim($stderr) !== ''): ?>
  <details class="card"><summary>Standard error</summary>
    <div class="output"><?= h($stderr) ?></div></details>
<?php endif; ?>

<?php if ($stdout !== null): ?>
  <details class="card"><summary>Raw output from the agent</summary>
    <div class="output"><?= h($stdout) ?></div></details>
<?php endif; ?>

<?php if ($prompt !== null): ?>
  <details class="card"><summary>The prompt it was given</summary>
    <div class="output"><?= h($prompt) ?></div></details>
<?php endif; ?>

<?php if ($job['envelope']): ?>
  <details class="card"><summary>How it arrived</summary>
    <div class="output"><?= h($job['envelope']) ?></div></details>
<?php endif; ?>

<?php
view_footer();
