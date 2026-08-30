<?php
// Everything a person configures, on one page, in the order somebody setting this up needs it.
//
// The two secrets are write-only. Leaving one blank keeps what is stored, which is the only sane
// behavior for a field that cannot show its current value — otherwise saving a change to the poll
// interval would silently unsign every future envelope.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/upstream.php';

bbl_session_start();
bbl_require_signin();

$error = null;
$notice = null;

// Plain values, saved as typed. The secrets and the password are handled separately below, because
// "empty means leave it alone" is true for those and false for these.
$plain = [
  'instance_url', 'company_name', 'accept_webhooks', 'signature_tolerance', 'allowed_ips',
  'poll_enabled', 'poll_roles', 'poll_dispatch',
  'agent_command', 'agent_timeout_seconds', 'default_model',
  'workspace_root', 'max_jobs_per_run', 'report_max_attempts',
];
$checkboxes = ['accept_webhooks', 'poll_enabled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $action = $_POST['action'] ?? 'save';

  if ($action === 'password') {
    $password = (string)($_POST['password'] ?? '');
    if (strlen($password) < 10) {
      $error = 'Use at least 10 characters.';
    } elseif ($password !== (string)($_POST['password_again'] ?? '')) {
      $error = 'The two entries do not match.';
    } else {
      setting_set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
      $notice = 'Password changed.';
    }
  } else {
    try {
      // Same parsing as the setup wizard, so a bare instance name works in both places rather than
      // being a convenience that only exists the first time.
      $url = instance_normalize($_POST['instance_url'] ?? '');
      foreach ($plain as $name) {
        $value = in_array($name, $checkboxes, true)
          ? (empty($_POST[$name]) ? '0' : '1')
          : trim((string)($_POST[$name] ?? ''));
        if ($name === 'instance_url') {
          $value = $url;
        }
        setting_set($name, $value);
      }
      // Empty means unchanged; there is a separate checkbox for actually clearing one, because
      // "clear the signing secret" and "I did not retype it" must not look the same.
      foreach (bbl_secret_settings() as $name) {
        if (!empty($_POST['clear_' . $name])) {
          setting_set($name, '');
        } elseif (trim((string)($_POST[$name] ?? '')) !== '') {
          setting_set($name, trim((string)$_POST[$name]));
        }
      }
      $notice = 'Saved.';

      // Testing saves first, deliberately. Testing what is stored while the form shows something
      // else is the kind of answer that costs an afternoon.
      if ($action === 'test') {
        $health = upstream_health();
        if (!$health['ok']) {
          throw new RuntimeException('Saved, but the instance did not answer: ' . $health['error']);
        }
        $tasks = upstream_open_tasks('any');
        if (!$tasks['ok']) {
          throw new RuntimeException('Saved, and the instance answered, but the key was refused: ' .
            $tasks['error'] . ' — it needs the task_creator permission or better.');
        }
        $notice = 'Saved, and it works. ' . count($tasks['json']['tasks'] ?? []) .
          ' task(s) open on the instance right now.';
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
      // The message says whether the save happened, so a "Saved." above it would only be read twice.
      $notice = null;
    }
  }
}

$hook_url = rtrim(bbl_config()['site_url'], '/') . '/hook.php';

view_header('Settings', true);
view_flash($error, $notice);
?>

<h2>The instance</h2>
<form method="post" class="card stack">
  <?= bbl_csrf_field() ?>
  <label>Beeblebrox instance
    <input type="text" name="instance_url" value="<?= h(setting('instance_url')) ?>"
           placeholder="zaphod">
    <small>The name on its own is enough — <code>zaphod</code> becomes
      <code>https://zaphod.beeblebrox.cloud</code>. Every URL in an envelope is checked against this
      before anything is fetched from it, so a forged envelope cannot point this machine's API key
      somewhere else.</small>
  </label>
  <label>Company name
    <input type="text" name="company_name" value="<?= h(setting('company_name')) ?>"
           placeholder="<?= h(instance_name() ?: 'taken from the instance name') ?>">
    <small>What the top of every page here says, so two workers are never mistaken for each other.
      Presentation only — nothing routes on it.</small>
  </label>
  <label>API key
    <input type="password" name="api_key" autocomplete="off"
           placeholder="<?= setting_secret_is_set('api_key') ? 'stored — leave empty to keep it' : 'not set' ?>">
<?php if (instance_base() !== ''): ?>
    <small>Issue one on the instance:
      <a href="<?= h(instance_base()) ?>/keys.php" target="_blank" rel="noopener">
        <?= h(instance_base()) ?>/keys.php</a> → <strong>New key</strong>. Choose
      <strong>task creator</strong>, which is what a worker needs: read everything, claim, and report.
      It is shown once there, so copy it straight into this box.</small>
<?php else: ?>
    <!-- The link is built from the stored instance URL, so it cannot be offered before there is one.
         Saying that beats a dead link or a bare hostname somebody has to assemble by hand. -->
    <small>Fill in the instance URL above and press Save — a link straight to that instance's key
      page appears here.</small>
<?php endif; ?>
  </label>

  <h3>Being sent work</h3>
  <label class="inline"><input type="checkbox" name="accept_webhooks" value="1"
    <?= setting_bool('accept_webhooks') ? 'checked' : '' ?>> Accept webhooks</label>
  <p class="small muted" style="margin:0">Point a dispatcher on the instance at
     <code><?= h($hook_url) ?></code>. That needs the instance to be able to reach this machine —
     a port forward, or a tunnel. If it cannot, leave this off and let polling do the work.</p>
  <label>Signing secret
    <input type="password" name="webhook_secret" autocomplete="off"
           placeholder="<?= setting_secret_is_set('webhook_secret') ? 'stored — leave empty to keep it' : 'not set' ?>">
    <small>The same string as the dispatcher's secret on the instance. Without a match every envelope
      is refused, which is deliberate: an unsigned envelope eventually starts an agent on this
      machine.</small>
  </label>
  <label>Clock tolerance
    <input type="text" name="signature_tolerance" inputmode="numeric"
           value="<?= h(setting('signature_tolerance')) ?>">
    <small>Seconds. Also the replay window — an envelope older than this is refused even with a good
      signature.</small>
  </label>
  <label>Allowed addresses <span class="muted">optional</span>
    <input type="text" name="allowed_ips" value="<?= h(setting('allowed_ips')) ?>"
           placeholder="65.109.248.148">
    <small>Comma separated. Empty means any, which is right when the instance reaches this machine
      through a tunnel and the address it arrives from belongs to the tunnel.</small>
  </label>

  <h3>Going and looking</h3>
  <label class="inline"><input type="checkbox" name="poll_enabled" value="1"
    <?= setting_bool('poll_enabled') ? 'checked' : '' ?>> Ask the instance for work</label>
  <p class="small muted" style="margin:0">Needs nothing inbound at all, which is why it is on by
     default. Each pass of the runner asks once.</p>
  <label>Roles this machine works
    <input type="text" name="poll_roles" value="<?= h(setting('poll_roles')) ?>"
           placeholder="developer, tester">
    <small>Comma separated, using the instance's slugs. Empty means any role, which is only right
      while this is the only worker.</small>
  </label>
  <label>Which work
    <select name="poll_dispatch">
<?php foreach (['webhook' => 'work meant for a machine (dispatched to a webhook)',
                'manual'  => 'work nothing else will start',
                'any'     => 'both'] as $value => $label): ?>
      <option value="<?= h($value) ?>" <?= setting('poll_dispatch') === $value ? 'selected' : '' ?>>
        <?= h($label) ?></option>
<?php endforeach; ?>
    </select>
  </label>

  <h3>The agent</h3>
  <label>Command
    <input type="text" name="agent_command" value="<?= h(setting('agent_command')) ?>">
    <small>Split into arguments here and run without a shell, so nothing in a ticket can become part
      of a command line. The prompt goes in on stdin. Placeholders:
      <code>{model}</code>, <code>{workspace}</code>, <code>{job_dir}</code>,
      <code>{result_file}</code>, <code>{task_id}</code>, <code>{role}</code>.</small>
  </label>
  <div class="card" style="margin:0">
    <p class="small" style="margin:0"><strong>On permissions.</strong> Shipped with
      <code>--permission-mode acceptEdits</code>, which lets the agent edit files but not run
      commands — so a role that has to build or test will report success having done neither, and
      nothing will say so. <code>bypassPermissions</code> is what makes those roles work, and it
      means the agent can run any command in that workspace as you. Change it once you are happy
      with the workspace it runs in, not before.</p>
  </div>
  <label>Model
    <input type="text" name="default_model" value="<?= h(setting('default_model')) ?>">
    <small>Used when neither the project nor the role names one.</small>
  </label>
  <label>Give up after
    <input type="text" name="agent_timeout_seconds" inputmode="numeric"
           value="<?= h(setting('agent_timeout_seconds')) ?>">
    <small>Seconds. A run that passes this is stopped and the job goes to the needs-a-person list
      with whatever it had written by then.</small>
  </label>
  <label>Suggested workspace root <span class="muted">optional</span>
    <input type="text" name="workspace_root" value="<?= h(setting('workspace_root')) ?>"
           placeholder="C:/work">
    <small>Only prefills the projects page. Nothing runs anywhere until a project is mapped.</small>
  </label>
  <label>Jobs per pass
    <input type="text" name="max_jobs_per_run" inputmode="numeric"
           value="<?= h(setting('max_jobs_per_run')) ?>">
    <small>One at a time matches the instance's own rule that a project has one active task. Raising
      it only helps if you work several projects at once.</small>
  </label>
  <label>Reporting attempts
    <input type="text" name="report_max_attempts" inputmode="numeric"
           value="<?= h(setting('report_max_attempts')) ?>">
    <small>How many times to try handing a finished result back before giving up and asking a person.
      A minute, five, twenty-five, then hourly.</small>
  </label>

<?php foreach (bbl_secret_settings() as $name): ?>
<?php if (setting_secret_is_set($name)): ?>
  <label class="inline danger"><input type="checkbox" name="clear_<?= h($name) ?>" value="1">
    Clear the stored <?= h(str_replace('_', ' ', $name)) ?></label>
<?php endif; ?>
<?php endforeach; ?>

  <div class="actions">
    <button type="submit">Save</button>
    <button type="submit" name="action" value="test" class="secondary">Test the connection</button>
  </div>
</form>

<h2>This page's password</h2>
<form method="post" class="card stack">
  <?= bbl_csrf_field() ?>
  <input type="hidden" name="action" value="password">
  <label>New password
    <input type="password" name="password" autocomplete="new-password">
    <small>At least 10 characters.</small>
  </label>
  <label>Again
    <input type="password" name="password_again" autocomplete="new-password">
  </label>
  <button type="submit">Change it</button>
</form>

<?php
view_footer();
