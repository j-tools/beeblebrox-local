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

// The company this machine works for, or '' while that is still unknown — including when the
// database cannot be asked, which is a different reason for the same answer and wants the same
// treatment on a page.
function view_company() {
  return view_settings_readable() && instance_base() !== '' ? company_name() : '';
}

// Where the mark in the bar points: the customer's own instance once there is one, and the public
// site until then, which is the honest answer to "what is this thing" at the moment somebody is
// most likely to be asking it.
function view_home_url() {
  return view_company() === '' ? bbl_public_site() : instance_base();
}

// Computed in PHP, which is the same clock that wrote the value: every timestamp here is either
// PHP's date() or a column default of datetime('now','localtime') on a database file sitting on this
// same machine. There is no second clock left to disagree with.
function view_ago($datetime) {
  if (!$datetime) {
    return '';
  }
  $mins = intdiv(max(0, time() - strtotime($datetime)), 60);
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

// A full-size mark inside the page, for the two screens where somebody is still working out what
// this is: the front door and the setup they walk through once. Everywhere else the bar carries it,
// and a logo on every screen of a tool used daily is decoration rather than identity.
//
// Same target as the bar — the company's own instance once there is one, beeblebrox.cloud until
// then. The image is decorative here because the words beside it already say the name, and a screen
// reader announcing it twice is worse than not announcing it.
function view_masthead() {
  $company = view_company();
  ?>
  <a class="masthead" href="<?= h(view_home_url()) ?>" target="_blank" rel="noopener"
     title="<?= h($company === '' ? 'What Beeblebrox is' : 'Open ' . $company) ?>">
    <img src="assets/favicon-180.png" alt="">
    <span class="masthead-words">
      <span class="masthead-name"><?= h($company === '' ? 'Beeblebrox' : $company . ' Beeblebrox') ?></span>
      <span class="masthead-sub">Local worker</span>
    </span>
  </a>
<?php
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
    // Settings and Diagnostics are not what this is for. They are what you open when something
    // is wrong or not set up yet, which is a different kind of visit from looking at the work —
    // so they sit at the foot of the drawer with the build number, out of the way of the things
    // used every day.
    ['href' => 'settings.php', 'label' => 'Settings', 'foot' => true],
    ['href' => 'diagnostics.php', 'label' => 'Diagnostics', 'foot' => true],
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
<?php
  // The mark and the wording are one link, because they name one thing. It leads out of here rather
  // than to the dashboard: the instance is on another machine and is the thing somebody in this
  // window wants to get back to, and before there is an instance the public site is the only honest
  // answer to what this is. Getting home is the drawer's job, which is where it was anyway.
  $company = view_company();
?>
  <a class="brand-block" href="<?= h(view_home_url()) ?>" target="_blank" rel="noopener"
     title="<?= h($company === '' ? 'What Beeblebrox is' : 'Open ' . $company) ?>">
    <img class="brand-mark" src="assets/favicon-32.png" width="28" height="28" alt="Beeblebrox">
    <span class="brand">
<?php if ($company !== ''): ?>
      <span class="brand-kicker">Local worker for</span>
      <span class="brand-company"><?= h($company) ?> <span class="muted">Beeblebrox</span></span>
<?php else: ?>
      <span class="brand-kicker">Beeblebrox</span>
      <span class="brand-company">Local worker</span>
<?php endif; ?>
    </span>
  </a>
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
  <?php /* What this is and whose, rather than the hostname it is served under. The host told nobody
           anything they did not know — somebody looking at this window typed the address — and on a
           machine reached through a tunnel it names something that is not this one.

           The company links to the instance, because that is where the work comes from and the thing
           somebody in this window usually wants to get back to. The instance's own address rather
           than a constructed <company>.beeblebrox.cloud: an instance can be self-hosted anywhere,
           and a link built from a name would quietly point at a host that does not exist.

           The instance host is not repeated as a badge here the way the proxy repeats its worker's:
           there it is a second machine, here it is the same one this line already links to.

           bbl_env_label() is unchanged — it identifies this worker in what it reports upstream. */ ?>
  <?php /* The bar's own words and the bar's own classes, so the drawer and the bar say the same thing
           the same way. "Local worker for" is what this is; the company links to the instance, which is
           where its work comes from. */ ?>
  <div class="drawer-who">
<?php if (company_name() !== ''): ?>
    <span class="brand-kicker">Local worker for</span>
<?php if (instance_base() !== ''): ?>
    <span class="brand-company"><a href="<?= h(instance_base()) ?>" target="_blank"
      rel="noopener"><?= h(company_name()) ?></a></span>
<?php else: ?>
    <span class="brand-company"><?= h(company_name()) ?></span>
<?php endif; ?>
<?php else: ?>
    <span class="brand-kicker">Beeblebrox</span>
    <span class="brand-company">Local worker</span>
    <span class="muted small">no instance yet</span>
<?php endif; ?>
  </div>
<?php $menu = view_menu_items();
  $feet = array_values(array_filter($menu, function ($i) { return !empty($i['foot']); }));
  $main = array_values(array_filter($menu, function ($i) { return empty($i['foot']); })); ?>
<?php foreach ($main as $item): ?>
  <a class="drawer-item<?= $item['href'] === $here ? ' current' : '' ?>" href="<?= h($item['href']) ?>">
    <?= h($item['label']) ?></a>
<?php endforeach; ?>
<?php /* Pinned to the bottom by margin-top:auto on the group, so the everyday items stay
         where the thumb expects them however many there are. */ ?>
  <div class="drawer-foot">
<?php foreach ($feet as $item): ?>
    <a class="drawer-item<?= $item['href'] === $here ? ' current' : '' ?>" href="<?= h($item['href']) ?>">
      <?= h($item['label']) ?></a>
<?php endforeach; ?>
  </div>
<?php /* Which copy this is, where somebody looking for it would look. A number rather than a
         commit because a number can be compared out loud: "you are on 26, the newest is 28". A
         checkout has no number — the release workflow writes it into the archive — and says so
         instead of showing nothing. */ ?>
<?php $build = bbl_build(); ?>
  <p class="drawer-version muted small">
<?php if ($build['number'] !== null): ?>
    Build <?= (int)$build['number'] ?><?= $build['built'] !== null
      ? ', ' . h($build['built']) : '' ?>
<?php elseif ($build['commit'] !== null): ?>
    Commit <?= h(substr($build['commit'], 0, 7)) ?>
<?php else: ?>
    From a checkout
<?php endif; ?>
  </p>
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

// A value that exists to be copied somewhere else: the signing secret, and nothing else so far.
//
// The text is plain selectable content whether or not the script runs, so the button is an
// improvement on a working page rather than the only way to get the value.
//
// The fallback is not legacy politeness. navigator.clipboard requires a secure context, and a worker
// on a machine's own network is very often plain HTTP — on that install execCommand is the only thing
// that works at all. If even that is refused the text is left selected, so Ctrl+C still finishes the
// job and the button says so.
function view_copyable($value) {
  $GLOBALS['bbl_needs_copy_script'] = true;
  static $n = 0;
  $id = 'copyable' . (++$n);
  echo '<span class="copyable"><code class="wrap" id="' . $id . '">' . h($value) . '</code>'
     . '<button type="button" class="copy" data-copy="' . $id . '">Copy</button></span>';
}

function view_footer() {
  if (!empty($GLOBALS['bbl_needs_copy_script'])) {
    view_copy_script();
  }
  echo "</main>\n</body>\n</html>\n";
}

// Written only on a page that rendered something copyable, so every other page stays script-free.
function view_copy_script() {
  ?>
<script>
document.addEventListener('click', function (event) {
  var button = event.target.closest('button.copy');
  if (!button) { return; }
  var source = document.getElementById(button.getAttribute('data-copy'));
  if (!source) { return; }

  var say = function (word) {
    button.textContent = word;
    setTimeout(function () { button.textContent = 'Copy'; }, 1600);
  };

  // Leaves the text selected on the way out, so a refusal still ends with Ctrl+C working.
  var selectAndCopy = function () {
    var range = document.createRange();
    range.selectNodeContents(source);
    var selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    var copied = false;
    try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
    say(copied ? 'Copied' : 'Press Ctrl+C');
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(source.textContent).then(function () { say('Copied'); },
                                                           selectAndCopy);
    return;
  }
  selectAndCopy();
});
</script>
<?php
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
