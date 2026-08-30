<?php
// Layout and presentation. Same visual language as the platform, on purpose — this is the other half
// of one product, and a person moving between the two windows should not have to notice.

require_once __DIR__ . '/html.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/settings.php';

function view_label($value) {
  return ucfirst(str_replace('_', ' ', (string)$value));
}

// Whether the bar may ask the database anything.
//
// The landing page deliberately renders when the database is unreachable, in order to say so — and
// the header sits above that message. Once the bar started naming the company, it started reading a
// setting, and a header that throws on the way to the message replaces the message with a stack
// trace. This is presentation deciding what it is able to show, not a fallback hiding a failure:
// every caller that needs a real answer still gets the exception.
function view_settings_readable() {
  static $ok = null;
  if ($ok === null) {
    try {
      settings_raw();
      $ok = true;
    } catch (Throwable $e) {
      $ok = false;
    }
  }
  return $ok;
}

// Compared against the database clock rather than PHP's. The two are not reliably in step on a
// machine that has been asleep, and a PHP-computed difference can be hours out.
function view_ago($datetime) {
  if (!$datetime) {
    return '';
  }
  $mins = (int)db_one('SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) AS m', [$datetime])['m'];
  if ($mins < 1)    { return 'just now'; }
  if ($mins < 60)   { return $mins . 'm ago'; }
  if ($mins < 1440) { return intdiv($mins, 60) . 'h ago'; }
  return intdiv($mins, 1440) . 'd ago';
}

function view_money($microdollars) {
  return $microdollars === null ? '' : '$' . number_format($microdollars / 1000000, 4);
}

function view_duration($ms) {
  if ($ms === null) {
    return '';
  }
  $seconds = (int)round($ms / 1000);
  if ($seconds < 60) {
    return $seconds . 's';
  }
  return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
}

function view_head($title) {
  ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> — Beeblebrox Local</title>
  <link rel="icon" href="assets/favicon-32.png" sizes="32x32" type="image/png">
  <link rel="apple-touch-icon" href="assets/favicon-180.png">
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="assets/local.css">
<?php
}

function view_menu_items() {
  $items = [
    ['href' => 'index.php',    'label' => 'Dashboard'],
    ['href' => 'jobs.php',     'label' => 'Jobs'],
    ['href' => 'projects.php', 'label' => 'Projects'],
    ['href' => 'settings.php', 'label' => 'Settings'],
    ['href' => 'diagnostics.php', 'label' => 'Diagnostics'],
  ];
  // Offered only while there is something it would still ask. Once everything is answered the
  // settings page is the place to change any of it, and a permanent "Setup" entry would suggest
  // otherwise.
  if (settings_gaps()) {
    array_unshift($items, ['href' => 'setup.php', 'label' => 'Finish setup']);
  }
  return $items;
}

function view_header($title, $signed_in = false) {
  $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
  $counts = $signed_in ? job_counts() : [];
  $needs_attention = (int)($counts['attention'] ?? 0);
  $in_flight = (int)($counts['queued'] ?? 0) + (int)($counts['claimed'] ?? 0) +
               (int)($counts['running'] ?? 0) + (int)($counts['reporting'] ?? 0);
  ?>
<!doctype html>
<html lang="en">
<head>
<?php view_head($title); ?>
</head>
<body>
<?php if ($signed_in): ?>
<!-- The drawer is a checkbox and two labels: no JavaScript, and it therefore cannot fail to open. -->
<input type="checkbox" id="drawer-toggle" class="drawer-toggle" hidden>
<?php endif; ?>
<header class="bar">
<?php if ($signed_in): ?>
  <label for="drawer-toggle" class="hamburger" title="Menu" aria-label="Menu"><span></span></label>
<?php endif; ?>
<?php // Two things side by side: the mark goes home, the wording goes to the instance it names. The
      // instance is a different host on a different machine and the one somebody in this window
      // regularly wants to get back to, so it earns the larger target. ?>
  <a class="brand-mark" href="index.php" title="Dashboard">
    <img src="assets/favicon-32.png" width="28" height="28" alt="Beeblebrox">
  </a>
<?php if (view_settings_readable() && instance_base() !== ''): ?>
  <a class="brand" href="<?= h(instance_base()) ?>" target="_blank" rel="noopener"
     title="Open <?= h(parse_url(instance_base(), PHP_URL_HOST)) ?>">
    <span class="brand-kicker">Local work for</span>
    <span class="brand-company"><?= h(company_name()) ?> <span class="muted">Beeblebrox</span></span>
  </a>
<?php else: ?>
  <?php // Before setup there is nowhere to point it, and a link that goes nowhere is worse than none. ?>
  <span class="brand">
    <span class="brand-kicker">Not set up yet</span>
    <span class="brand-company">Beeblebrox <span class="muted">Local</span></span>
  </span>
<?php endif; ?>
<?php if ($signed_in): ?>
  <span class="bar-counts">
    <a href="jobs.php?status=attention" class="count<?= $needs_attention ? ' count-live' : '' ?>">
      <?= $needs_attention ?> <span class="muted">need you</span></a>
    <a href="jobs.php" class="count"><?= $in_flight ?> <span class="muted">in flight</span></a>
  </span>
<?php endif; ?>
</header>

<?php if ($signed_in): ?>
<nav class="drawer" aria-label="Main">
  <div class="drawer-who">
    <strong><?= h(bbl_env_label()) ?></strong>
    <span class="badge"><?= h(parse_url((string)setting('instance_url'), PHP_URL_HOST) ?: 'no instance') ?></span>
  </div>
<?php foreach (view_menu_items() as $item): ?>
  <a class="drawer-item<?= $item['href'] === $here ? ' current' : '' ?>" href="<?= h($item['href']) ?>">
    <?= h($item['label']) ?></a>
<?php endforeach; ?>
  <form method="post" action="logout.php" class="drawer-signout">
    <?= bbl_csrf_field() ?>
    <button type="submit" class="link">Sign out</button>
  </form>
</nav>
<label for="drawer-toggle" class="scrim" aria-hidden="true"></label>
<?php endif; ?>

<main>
<?php
}

function view_footer() {
  echo "</main>\n</body>\n</html>\n";
}

function view_flash($error = null, $ok = null) {
  if ($error) {
    echo '<p class="error">' . h($error) . "</p>\n";
  }
  if ($ok) {
    echo '<p class="ok">' . h($ok) . "</p>\n";
  }
}

// One job, as a line you can read without opening it: who was asked, for what, and where it got to.
function view_job_row(array $job) {
  $labels = job_statuses();
  ?>
  <a class="row-link" href="job.php?id=<?= (int)$job['id'] ?>">
    <span class="id">#<?= (int)$job['upstream_task_id'] ?></span>
    <strong><?= h($job['nickname'] ?: ($job['role_slug'] ?: 'unassigned')) ?></strong>
    <span class="title"><?= h(mb_strimwidth((string)($job['error'] ?: $job['output']), 0, 90, '…')) ?></span>
    <span class="meta">
      <span class="badge job-<?= h($job['status']) ?>" title="<?= h($labels[$job['status']] ?? '') ?>">
        <?= h(view_label($job['status'])) ?></span>
<?php if (!empty($job['reported_status'])): ?>
      <span class="badge"><?= h($job['reported_status']) ?></span>
<?php endif; ?>
<?php if (!empty($job['is_test'])): ?>
      <span class="badge test">test</span>
<?php endif; ?>
      <span class="muted small">
        <?= h($job['source']) ?>
<?php if ($job['duration_ms'] !== null): ?>
        &middot; <?= h(view_duration((int)$job['duration_ms'])) ?>
<?php endif; ?>
<?php if ($job['cost_microdollars'] !== null): ?>
        &middot; <?= h(view_money((int)$job['cost_microdollars'])) ?>
<?php endif; ?>
        &middot; <?= h(view_ago($job['received_at'])) ?>
      </span>
    </span>
  </a>
<?php
}
