<?php
// Everything this machine has been asked to do, newest first, filtered by state.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';

bbl_session_start();
bbl_require_signin();

$statuses = job_statuses();
$filter = $_GET['status'] ?? '';
$filter = isset($statuses[$filter]) ? $filter : '';

$jobs = $filter === ''
  ? db_all('SELECT * FROM jobs ORDER BY id DESC LIMIT 200')
  : db_all('SELECT * FROM jobs WHERE status = ? ORDER BY id DESC LIMIT 200', [$filter]);

$counts = job_counts();

view_header('Jobs', true);
?>
<h2>Jobs</h2>

<p class="small">
  <a href="jobs.php"<?= $filter === '' ? ' class="muted"' : '' ?>>All</a>
<?php foreach ($statuses as $status => $describe): ?>
  &middot; <a href="jobs.php?status=<?= h($status) ?>"<?= $filter === $status ? ' class="muted"' : '' ?>
     title="<?= h($describe) ?>"><?= h(view_label($status)) ?> (<?= (int)$counts[$status] ?>)</a>
<?php endforeach; ?>
</p>

<?php if (!$jobs): ?>
  <p class="muted"><?= $filter === '' ? 'Nothing has arrived yet.' : 'Nothing in that state.' ?></p>
<?php else: ?>
<?php foreach ($jobs as $job) { view_job_row($job); } ?>
<?php endif; ?>

<?php
view_footer();
