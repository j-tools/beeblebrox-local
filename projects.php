<?php
// Which directory on this machine each upstream project's work happens in.
//
// This is the one thing that cannot be configured on the instance, because it is the one thing that
// is about this machine rather than about the work. Nothing is guessed: a project with no row here
// stops and asks rather than running an agent somewhere plausible.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_require_signin();

$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();

  if (($_POST['action'] ?? '') === 'delete') {
    db_exec('DELETE FROM projects WHERE id = ?', [(int)$_POST['id']]);
    $notice = 'Removed. Work for that project will stop and ask again.';
  } else {
    $upstream = (int)($_POST['upstream_project_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $path = trim((string)($_POST['workspace_path'] ?? ''));

    if ($upstream <= 0) {
      $error = 'The upstream project id is the number the instance uses. It is on the project page there.';
    } elseif ($name === '') {
      $error = 'Give it a name — it is what the job list will call it.';
    } elseif ($path === '') {
      $error = 'A workspace path is the whole point of the row.';
    } elseif (!is_dir($path)) {
      // Refused rather than warned. A path that does not exist is a typo often enough that saving it
      // only moves the discovery to the middle of a run.
      $error = "There is no directory at {$path}. Create it, or fix the path.";
    } else {
      db_exec(
        'INSERT INTO projects (upstream_project_id, name, workspace_path, prepare_command, model, is_active)
           VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(upstream_project_id) DO UPDATE SET
           name = excluded.name, workspace_path = excluded.workspace_path,
           prepare_command = excluded.prepare_command, model = excluded.model,
           is_active = excluded.is_active',
        [$upstream, $name, str_replace('\\', '/', $path),
         trim((string)($_POST['prepare_command'] ?? '')) ?: null,
         trim((string)($_POST['model'] ?? '')) ?: null,
         empty($_POST['is_active']) ? 0 : 1]
      );
      $notice = 'Saved.';
    }
  }
}

$projects = projects_all();
$mapped = array_column($projects, 'upstream_project_id');

// Projects this machine has been sent work for and has no directory for. Derived from the jobs it
// has actually seen, because there is no endpoint that lists an instance's projects and asking a
// person to remember which ones matter is how one gets missed.
$unmapped = array_filter(db_all(
  'SELECT DISTINCT project_id FROM jobs WHERE project_id IS NOT NULL ORDER BY project_id'),
  function ($row) use ($mapped) {
    return !in_array((int)$row['project_id'], array_map('intval', $mapped), true);
  });

$edit = null;
if (isset($_GET['edit'])) {
  $edit = db_one('SELECT * FROM projects WHERE id = ?', [(int)$_GET['edit']]);
}

view_header('Projects', true);
view_flash($error, $notice);
?>
<h2>Mapped</h2>
<?php if (!$projects): ?>
  <p class="muted">None yet. Until a project is mapped, work that belongs to it stops and asks.</p>
<?php else: ?>
<?php foreach ($projects as $project): ?>
  <div class="card">
    <strong><?= h($project['name']) ?></strong>
    <span class="badge">project <?= (int)$project['upstream_project_id'] ?></span>
<?php if (!(int)$project['is_active']): ?>
    <span class="badge">paused</span>
<?php endif; ?>
<?php if (!is_dir($project['workspace_path'])): ?>
    <span class="badge job-attention">missing</span>
<?php endif; ?>
    <p class="small muted" style="margin:.4rem 0 0"><?= h($project['workspace_path']) ?></p>
<?php if ($project['prepare_command']): ?>
    <p class="small muted" style="margin:.2rem 0 0">before each run: <code><?= h($project['prepare_command']) ?></code></p>
<?php endif; ?>
    <div class="actions">
      <a class="secondary" href="projects.php?edit=<?= (int)$project['id'] ?>">Edit</a>
      <form method="post" class="inline">
        <?= bbl_csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$project['id'] ?>">
        <button type="submit" class="secondary">Remove</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($unmapped): ?>
  <h2>Seen but not mapped</h2>
  <div class="card">
    <p class="small">Work has arrived for
      <?php foreach ($unmapped as $row): ?>
        <a href="projects.php?prefill=<?= (int)$row['project_id'] ?>">project
          <?= (int)$row['project_id'] ?></a>
      <?php endforeach; ?>
      and there is nowhere to run it. Add each below.</p>
  </div>
<?php endif; ?>

<h2><?= $edit ? 'Edit ' . h($edit['name']) : 'Add a project' ?></h2>
<form method="post" class="card stack">
  <?= bbl_csrf_field() ?>
  <label>Upstream project id
    <input type="text" name="upstream_project_id" inputmode="numeric" required
           value="<?= h($edit['upstream_project_id'] ?? ($_GET['prefill'] ?? '')) ?>">
    <small>The number the instance uses for it — the <code>id</code> in its project page URL. Saving
      the same number again edits that row rather than adding a second.</small>
  </label>
  <label>Name
    <input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>">
    <small>Only used here, in the job list.</small>
  </label>
  <label>Workspace
    <input type="text" name="workspace_path" required value="<?= h($edit['workspace_path'] ?? setting('workspace_root')) ?>">
    <small>The directory the agent runs in — normally a checkout of that project's repository. It has
      to exist already; this refuses a path that does not.</small>
  </label>
  <label>Prepare command <span class="muted">optional</span>
    <input type="text" name="prepare_command" value="<?= h($edit['prepare_command'] ?? '') ?>"
           placeholder="git fetch --all">
    <small>Run in the workspace before every agent run, so each one starts from a known state. It
      fails loudly: if this does not succeed, the agent is not started. Split like a command line,
      not a shell — no pipes, no <code>&amp;&amp;</code>, quotes for arguments with spaces.</small>
  </label>
  <label>Model <span class="muted">optional</span>
    <input type="text" name="model" value="<?= h($edit['model'] ?? '') ?>" placeholder="opus">
    <small>Overrides the role's own model for this project. Leave empty to use whatever the role on
      the instance asks for.</small>
  </label>
  <label class="inline"><input type="checkbox" name="is_active" value="1"
    <?= (!$edit || (int)$edit['is_active']) ? 'checked' : '' ?>> Active</label>
  <button type="submit">Save</button>
</form>

<?php
view_footer();
