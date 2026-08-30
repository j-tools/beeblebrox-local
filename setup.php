<?php
// Setting this up, in the order the questions actually depend on each other.
//
// The settings page has every field on one screen, which is right once you know what they all are and
// wrong the first time. This asks four questions, each one enough to answer on its own, and each one
// checked before it moves on — so a wrong instance name or a refused key is found at the moment it is
// entered rather than at the first pass of the runner, where the symptom is silence.
//
// It writes exactly what the settings page writes, through the same setting_set. There is no separate
// state to get out of step, and no way to end up configured through one and not the other.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/upstream.php';
require_once __DIR__ . '/lib/agent.php';

bbl_session_start();
bbl_require_signin();

// Ordered, and the order is the dependency: nothing can be asked about a key until there is an
// instance to issue it, and nothing about the agent matters until work can reach the machine.
//
// A step is done when somebody answered it, not when the setting behind it happens to hold something.
// Two of these ship with a working default — polling is on, and there is an agent command — so
// judging by the value would tick them off before they had been read, which is exactly the pair a
// person most needs to have looked at.
function setup_steps() {
  $answered = setup_answered();
  return [
    'company' => ['title' => 'Your Beeblebrox',  'done' => in_array('company', $answered, true)],
    'key'     => ['title' => 'Your key',         'done' => in_array('key', $answered, true)],
    'work'    => ['title' => 'How work arrives', 'done' => in_array('work', $answered, true)],
    'agent'   => ['title' => 'Your agent',       'done' => in_array('agent', $answered, true)],
  ];
}

function setup_answered() {
  return preg_split('/[\s,]+/', (string)setting('setup_answered'), -1, PREG_SPLIT_NO_EMPTY);
}

function setup_mark_answered($step) {
  $answered = setup_answered();
  if (!in_array($step, $answered, true)) {
    $answered[] = $step;
    setting_set('setup_answered', implode(',', $answered));
  }
}

// Where somebody who just opens setup.php should land: the first thing not yet answered, or the end
// if everything is.
function setup_first_unanswered() {
  foreach (setup_steps() as $name => $step) {
    if (!$step['done']) {
      return $name;
    }
  }
  return 'done';
}

$steps = setup_steps();
$step = $_GET['step'] ?? '';
if (!isset($steps[$step]) && $step !== 'done') {
  $step = setup_first_unanswered();
}

$error = null;
$notice = null;

// Something true and worth acting on that is not a failure — so neither the red flash nor the green
// one. Carried across the redirect that leaves the step where it was found, because the next screen
// is where somebody can still do something about it.
$warning = null;
if (!empty($_SESSION['setup_worker_warning'])) {
  $warning = $_SESSION['setup_worker_warning'];
  unset($_SESSION['setup_worker_warning']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $step = $_POST['step'] ?? $step;
  try {
    if ($step === 'company') {
      $url = instance_normalize($_POST['instance'] ?? '');
      if ($url === '') {
        throw new RuntimeException('Nothing to go on yet — the name of your instance is enough.');
      }
      setting_set('instance_url', $url);

      // Guessed from the instance name, because that is the same word in every hosted case and
      // typing it twice is a question nobody should be asked.
      $company = trim((string)($_POST['company_name'] ?? ''));
      setting_set('company_name', $company !== '' ? $company : ucfirst(instance_name()));

      // Checked now rather than trusted. A typo here is otherwise found much later, as nothing
      // happening, which is the hardest symptom there is to work back from.
      $health = upstream_health();
      if (!$health['ok']) {
        throw new RuntimeException("Saved, but nothing answered at {$url}. " .
          ($health['error'] ?: '') . ' Check the name and try again — or carry on, if you know the ' .
          'instance is simply down at the moment.');
      }
      setup_mark_answered('company');
      header('Location: setup.php?step=key');
      exit;
    }

    if ($step === 'key') {
      $key = trim((string)($_POST['api_key'] ?? ''));
      if ($key === '' && !setting_secret_is_set('api_key')) {
        throw new RuntimeException('Paste the key from the instance. It is shown there once, on the ' .
          'page the link below opens.');
      }
      if ($key !== '') {
        setting_set('api_key', $key);
      }
      $who = upstream_whoami();
      // An instance older than this worker has no /api/whoami. Fall back to a call every release
      // has, so setting up against one still works — it just cannot confirm which worker this is.
      if ($who['status'] === 404) {
        $tasks = upstream_open_tasks();
        if (!$tasks['ok']) {
          throw new RuntimeException('That key was not accepted: ' . $tasks['error'] .
            ' It needs the "task creator" permission or better. Issue another and try again.');
        }
        $_SESSION['setup_worker_warning'] =
          'That key works. This instance is running a release older than this worker, though, so it ' .
          'cannot say which worker the key belongs to — and until it is deployed, this machine will ' .
          'be offered every task meant for a machine rather than only its own.';
        setup_mark_answered('key');
        header('Location: setup.php?step=work');
        exit;
      }
      if (!$who['ok']) {
        throw new RuntimeException('That key was not accepted: ' . $who['error'] .
          ' It needs the "task creator" permission or better. Issue another and try again.');
      }
      // Not fatal: a key belonging to no worker still works, it is just shown every machine's queue.
      // Said here rather than left to the diagnostics page, because this is the moment it can be
      // fixed by issuing a different key, and five minutes later it is a mystery.
      if (($who['json']['worker'] ?? null) === null) {
        $_SESSION['setup_worker_warning'] =
          'That key works, but it does not belong to a worker — so this machine will be offered ' .
          'every task meant for a machine, including other workers\'. That is only safe while this ' .
          'is the only one. Issue a key that belongs to a worker to fix it.';
      } else {
        unset($_SESSION['setup_worker_warning']);
      }
      setup_mark_answered('key');
      header('Location: setup.php?step=work');
      exit;
    }

    if ($step === 'work') {
      $poll = !empty($_POST['poll_enabled']);
      $hook = !empty($_POST['accept_webhooks']);
      if (!$poll && !$hook) {
        throw new RuntimeException('Pick at least one. With neither, no work can ever reach this ' .
          'machine.');
      }
      $secret = trim((string)($_POST['webhook_secret'] ?? ''));
      if ($hook && $secret === '' && !setting_secret_is_set('webhook_secret')) {
        throw new RuntimeException('A webhook needs a signing secret, or every envelope is refused. ' .
          'Put the same string here and on the dispatcher — or leave webhooks off and let polling ' .
          'do the work.');
      }
      setting_set('poll_enabled', $poll ? '1' : '0');
      setting_set('accept_webhooks', $hook ? '1' : '0');
      if ($secret !== '') {
        setting_set('webhook_secret', $secret);
      }
      setup_mark_answered('work');
      header('Location: setup.php?step=agent');
      exit;
    }

    if ($step === 'agent') {
      $command = trim((string)($_POST['agent_command'] ?? ''));
      if ($command === '') {
        throw new RuntimeException('Without a command there is nothing to run the work.');
      }
      $argv = command_split($command);
      // Started before anything depends on it. "Could not start" now is a typo in a path; the same
      // words in three weeks are a job that stopped for no visible reason.
      $probe = agent_execute([$argv[0], '--version'], bbl_config()['job_root'], '', 30);
      if ($probe['exit_code'] !== 0 && empty($_POST['confirm_agent'])) {
        setting_set('agent_command', $command);
        setting_set('default_model', trim((string)($_POST['default_model'] ?? '')) ?: 'sonnet');
        throw new RuntimeException("Saved, but {$argv[0]} could not be started here. " .
          ($probe['error'] ?: '') . ' Use the full path to the program, and remember that a .cmd or ' .
          '.bat wrapper needs "cmd /c" in front of it. Submit again to carry on regardless.');
      }
      setting_set('agent_command', $command);
      setting_set('default_model', trim((string)($_POST['default_model'] ?? '')) ?: 'sonnet');
      setup_mark_answered('agent');
      header('Location: setup.php?step=done');
      exit;
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$steps = setup_steps();

view_header('Setup', true);
view_masthead();
?>

<h2>Setting up</h2>

<ol class="wizard">
<?php foreach ($steps as $name => $meta): ?>
  <li class="<?= $name === $step ? 'here' : ($meta['done'] ? 'done' : '') ?>">
<?php if ($meta['done'] && $name !== $step): ?>
    <a href="setup.php?step=<?= h($name) ?>"><?= h($meta['title']) ?></a>
<?php else: ?>
    <?= h($meta['title']) ?>
<?php endif; ?>
  </li>
<?php endforeach; ?>
  <li class="<?= $step === 'done' ? 'here' : '' ?>">Done</li>
</ol>

<?php view_flash($error, $notice); ?>

<?php if ($warning !== null): ?>
  <div class="card"><p class="small" style="margin:0"><strong>Worth knowing.</strong>
    <?= h($warning) ?></p></div>
<?php endif; ?>

<?php if ($step === 'company'): ?>
  <div class="card">
    <p class="lede" style="margin-top:0">Which Beeblebrox does this machine work for?</p>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="step" value="company">
      <label>Your instance
        <input type="text" name="instance" autofocus required
               value="<?= h(instance_name() ?: '') ?>" placeholder="zaphod">
        <small>Just the name is enough — <code>zaphod</code> becomes
          <code>https://zaphod.beeblebrox.cloud</code>. If your instance is somewhere else, put the
          whole address in instead.</small>
      </label>
      <label>Call it
        <input type="text" name="company_name" value="<?= h(setting('company_name')) ?>"
               placeholder="left empty, the name above is used">
        <small>What appears at the top of every page here, so you can tell one worker from another
          at a glance. It links back to the instance.</small>
      </label>
      <button type="submit">Continue</button>
    </form>
  </div>
  <p class="small muted">Everything an envelope points at is checked against this address before it is
     fetched, so getting it right matters more than it looks: it is what stops a forged envelope
     sending this machine's key somewhere else.</p>

<?php elseif ($step === 'key'): ?>
  <div class="card">
    <p class="lede" style="margin-top:0">A key to talk to
      <?= h(company_name()) ?> with.</p>
    <ol class="steps">
      <li><strong>Open your instance's key page</strong>
        <span><a href="<?= h(instance_base()) ?>/keys.php" target="_blank" rel="noopener">
          <?= h(instance_base()) ?>/keys.php</a> — you need to be signed in there as a company
          admin.</span></li>
      <li><strong>New key</strong>
        <span>Name it after this machine, so it is obvious later which one it is. Permission
          <strong>task creator</strong>: read everything, claim, and report — what a worker needs and
          nothing more.</span></li>
      <li><strong>Copy it straight here</strong>
        <span>It is shown once and only a hash is kept there, so it cannot be read back. If it goes
          missing, revoke it and issue another.</span></li>
    </ol>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="step" value="key">
      <label>The key
        <input type="password" name="api_key" autocomplete="off" autofocus
               placeholder="<?= setting_secret_is_set('api_key')
                 ? 'stored — leave empty to keep it' : 'paste it here' ?>">
        <small>Stored encrypted, and never shown again from this side either.</small>
      </label>
      <button type="submit">Check it and continue</button>
    </form>
  </div>

<?php elseif ($step === 'work'): ?>
  <div class="card">
    <p class="lede" style="margin-top:0">How should work reach this machine?</p>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="step" value="work">

      <label class="inline"><input type="checkbox" name="poll_enabled" value="1"
        <?= setting_bool('poll_enabled') ? 'checked' : '' ?>> Ask the instance for work</label>
      <p class="small muted" style="margin:0">Needs nothing from your network — no port forward, no
         tunnel. Each pass of the runner asks once, and gets back this worker's own open tasks.
         There is nothing here to choose: which work is this worker's was decided on the instance,
         on its roles and its projects. This is the one to use unless you have a reason not to.</p>

      <label class="inline"><input type="checkbox" name="accept_webhooks" value="1"
        <?= setting_bool('accept_webhooks') ? 'checked' : '' ?>> Let the instance push work here</label>
      <p class="small muted" style="margin:0">Faster, but your instance has to be able to reach this
         machine — a port forward or a tunnel to
         <code><?= h(rtrim(bbl_config()['site_url'], '/')) ?>/hook.php</code>. You can turn this on
         later.</p>
      <label>Signing secret
        <input type="password" name="webhook_secret" autocomplete="off"
               placeholder="<?= setting_secret_is_set('webhook_secret')
                 ? 'stored — leave empty to keep it' : 'only needed for webhooks' ?>">
        <small>Any long random string, set identically on the dispatcher at your instance. Without a
          match every envelope is refused — deliberately, because an envelope eventually starts a
          program on this machine.</small>
      </label>
      <button type="submit">Continue</button>
    </form>
  </div>

<?php elseif ($step === 'agent'): ?>
  <div class="card">
    <p class="lede" style="margin-top:0">What actually does the work.</p>
    <form method="post" class="stack">
      <?= bbl_csrf_field() ?>
      <input type="hidden" name="step" value="agent">
<?php if ($error !== null): ?>
      <!-- Set once the probe has already failed and been shown, so a second submission is a person
           saying they know and meant it rather than the same refusal on a loop. -->
      <input type="hidden" name="confirm_agent" value="1">
<?php endif; ?>
      <label>Command
        <input type="text" name="agent_command" autofocus required
               value="<?= h(setting('agent_command')) ?>">
        <small>Started directly, without a shell, with the ticket on its input. Use the full path if
          it is not on PATH for the account that will run the schedule.</small>
      </label>
      <label>Model
        <input type="text" name="default_model" value="<?= h(setting('default_model')) ?>">
        <small>Used when neither the project nor the role on the instance names one.</small>
      </label>
      <button type="submit">Check it and finish</button>
    </form>
  </div>
  <div class="card">
    <p class="small" style="margin:0"><strong>Before you raise the permissions.</strong> The command
      above ships with <code>--permission-mode acceptEdits</code>: the agent may edit files but not
      run commands. A refused command does not stop it — it quietly does not happen — so a role that
      has to build or test will report success having done neither, and nothing will say so.
      <code>bypassPermissions</code> is what makes those roles work, and it lets the agent run any
      command in that workspace as you. Change it on the settings page once you are happy with the
      workspace it runs in.</p>
  </div>

<?php else: ?>
  <div class="card">
    <p class="lede" style="margin-top:0">This machine is a worker for
      <a href="<?= h(instance_base()) ?>" target="_blank" rel="noopener"><?= h(company_name()) ?></a>.</p>
    <p class="small">Two things left, and neither is a setting:</p>
    <ol class="steps">
      <li><strong>Say where each project lives</strong>
        <span>The instance says a task belongs to a project; only this machine knows which directory
          that is. Work for an unmapped project stops and asks rather than running somewhere
          plausible. <a href="projects.php">Map them</a>.</span></li>
      <li><strong>Put the runner on a schedule</strong>
        <span>Nothing happens on its own until <code>tools/run.php</code> runs every minute. The
          command is in <code>INSTALL.md</code>, section 9 — run it as your own account, not as a
          service account, since the agent needs your PATH and your checkouts.</span></li>
    </ol>
    <div class="actions">
      <a class="secondary" href="diagnostics.php">Check everything</a>
      <a class="secondary" href="index.php">Dashboard</a>
      <a class="secondary" href="settings.php">All settings</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($step !== 'done'): ?>
  <p class="small muted">Every one of these is on the <a href="settings.php">settings page</a> too,
     alongside the ones this does not ask about.</p>
<?php endif; ?>

<?php
view_footer();
