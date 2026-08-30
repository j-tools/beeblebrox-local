<?php
// Everything a person configures, read and written in one place.
//
// Two kinds of value live here. A plain one is stored as typed. A secret one is encrypted under
// SECRET_KEY, because both secrets this thing holds have to be *used* rather than compared: the API
// key is sent as a bearer token, and the webhook secret has to reproduce the same HMAC the platform
// produced. Neither can be hashed.
//
// The defaults below are what a fresh install runs on. They are deliberately the cautious ones — no
// webhooks accepted until a signing secret exists, and an agent that may edit files but not run
// arbitrary commands — so that installing this and forgetting about it for a week is safe.

require_once __DIR__ . '/secrets.php';

function bbl_setting_defaults() {
  return [
    // Which Beeblebrox instance this machine works for. Every URL in an envelope is checked against
    // this before anything is fetched from it, so a forged envelope cannot point the API key at
    // somebody else's host.
    'instance_url'          => '',

    // What to call the company whose work this machine does. Presentation only — nothing routes on
    // it — but it is what the bar says and what every link back to the instance is labelled, and a
    // person running a worker for two companies needs to know at a glance which window is which.
    'company_name'          => '',

    // Bearer token. Issued on the instance's own API keys page — <instance>/keys.php, company admin
    // only — and shown there once. There is deliberately no way to get one from here: authenticating
    // a request for a key would need a credential, which is the problem being solved.
    'api_key'               => '',

    // Shared with the dispatcher on the instance. Without it webhooks are refused outright rather
    // than accepted unsigned, because an unsigned envelope is an open invitation to run an agent.
    'webhook_secret'        => '',

    'accept_webhooks'       => '1',
    // How much clock difference is tolerated between the instance and this machine, in seconds. Also
    // the replay window: an envelope captured today cannot be sent again tomorrow.
    'signature_tolerance'   => '300',
    // Comma-separated. Empty means any address, which is the right answer when the instance reaches
    // this machine through a tunnel and its source address is not stable.
    'allowed_ips'           => '',

    // The other way work arrives, and the one that needs no inbound networking at all: ask the
    // instance what is open and take it. This is the default because a laptop behind a router cannot
    // receive a webhook without a tunnel, and asking is free.
    'poll_enabled'          => '1',
    // Which roles this machine will pick up. Empty means any, which is almost never what you want
    // once a second worker exists.
    'poll_roles'            => '',
    // 'webhook' takes only work whose role is dispatched to a webhook — the work meant for a machine.
    // 'manual' takes work nothing else will start. 'any' takes both.
    'poll_dispatch'         => 'webhook',

    // The agent, as an argv template. Parsed into arguments here and executed without a shell, so
    // nothing in a ticket can become part of a command line. The prompt goes in on stdin.
    //
    //   {model}      the role's model, or default_model
    //   {workspace}  the working directory for this job
    //   {job_dir}    where the prompt, the raw output and the result file live
    //   {result_file} the file the agent is asked to write its verdict to
    'agent_command'         => 'claude -p --output-format json --model {model} --permission-mode acceptEdits',
    'agent_timeout_seconds' => '3600',
    'default_model'         => 'sonnet',

    // Where a project's workspace is created when one is not mapped by hand. Empty means a project
    // must be mapped explicitly before anything runs in it.
    'workspace_root'        => '',

    // One at a time by default, matching the platform's own invariant that a project has one active
    // task. Raising it only helps if you work several projects at once.
    'max_jobs_per_run'      => '1',
    'report_max_attempts'   => '8',

    // Sign-in for these pages. Set on first run.
    'admin_password_hash'   => '',

    // Written by the runner at the end of every pass. The only way the pages can tell "nothing has
    // arrived" apart from "nothing is looking", which are the same picture and different problems.
    'last_pass_at'          => '',

    // Which setup questions have actually been answered. Two of the four have a working default, so
    // the settings behind them cannot say whether anybody has read them — and those two are the pair
    // most worth having read.
    'setup_answered'        => '',
  ];
}

function bbl_secret_settings() {
  return ['api_key', 'webhook_secret'];
}

// All settings, defaults filled in, secrets left encrypted. Read once per request.
function settings_raw($refresh = false) {
  static $cache = null;
  if ($cache === null || $refresh) {
    $cache = bbl_setting_defaults();
    foreach (db_all('SELECT name, value FROM settings') as $row) {
      $cache[$row['name']] = $row['value'];
    }
  }
  return $cache;
}

function setting($name, $default = null) {
  $all = settings_raw();
  return array_key_exists($name, $all) ? $all[$name] : $default;
}

function setting_bool($name) {
  return (string)setting($name) === '1';
}

function setting_int($name, $default = 0) {
  $value = setting($name);
  return $value === null || $value === '' ? $default : (int)$value;
}

// The plaintext of a secret, or '' when none is stored. Throws only if the stored value cannot be
// decrypted, which means SECRET_KEY changed and the value has to be entered again — a caller
// swallowing that would turn a fixable configuration error into a mystery.
function setting_secret($name) {
  $stored = (string)setting($name);
  return $stored === '' ? '' : secrets_decrypt($stored);
}

function setting_secret_is_set($name) {
  return (string)setting($name) !== '';
}

// Writes one setting, encrypting it if it is one of the secret ones. Passing '' for a secret clears
// it; a caller that means "leave it alone" must not call this at all.
function setting_set($name, $value) {
  $is_secret = in_array($name, bbl_secret_settings(), true);
  if ($is_secret && (string)$value !== '') {
    $value = secrets_encrypt($value);
  }
  db_exec(
    'INSERT INTO settings (name, value, is_secret) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value), is_secret = VALUES(is_secret)',
    [$name, (string)$value, $is_secret ? 1 : 0]
  );
  settings_raw(true);
}

// The instance base URL with no trailing slash, which is what every comparison and every built URL
// wants. Returns '' when unset.
function instance_base() {
  return rtrim((string)setting('instance_url'), '/');
}

// What the company is called, falling back to the instance's own name — which is the same word in
// every hosted case, since an instance is <company>.beeblebrox.cloud.
function company_name() {
  $name = trim((string)setting('company_name'));
  return $name !== '' ? $name : instance_name();
}

// The first label of the instance's hostname: 'zaphod' out of zaphod.beeblebrox.cloud. This is the
// word a person actually knows — nobody thinks of their company as a URL — so it is what the setup
// wizard asks for and what a company name is guessed from.
function instance_name() {
  $host = parse_url(instance_base(), PHP_URL_HOST);
  if (!$host) {
    return '';
  }
  $labels = explode('.', $host);
  return $labels[0];
}

// Turns whatever somebody typed into a base URL.
//
// A bare word is the case worth having: an instance is <company>.beeblebrox.cloud, so 'zaphod' is
// enough and asking a customer to produce a URL from that is asking them to get a scheme and a
// domain right for no reason. Anything with a dot or a scheme in it is taken as an address, which is
// what a self-hosted instance needs.
//
// Throws rather than guessing at something unusable, because the message is the whole value here.
function instance_normalize($input) {
  $input = trim((string)$input);
  if ($input === '') {
    return '';
  }
  if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $input)) {
    return 'https://' . strtolower($input) . '.beeblebrox.cloud';
  }
  if (!preg_match('#^https?://#i', $input)) {
    // No scheme but not a bare name either — a hostname, or something with a port or a path. https
    // is the only sane assumption for an instance; a plain-http one can still be typed in full.
    $input = 'https://' . $input;
  }
  // Whatever the host turns out to be is taken as meant. A single-label host like `localhost` is
  // legitimate for a self-hosted instance or a tunnel, and refusing it to catch typos would be
  // choosing the rarer mistake over the real case.
  $host = parse_url($input, PHP_URL_HOST);
  if (!$host || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $host)) {
    throw new RuntimeException(
      "\"{$input}\" is not an address this can use. Either the name of your instance on its own — " .
      'zaphod, say, for zaphod.beeblebrox.cloud — or the full address of a self-hosted one.');
  }
  return rtrim($input, '/');
}

// Whether a URL from an envelope may be fetched. The signature already proves the envelope came from
// the instance, so this is the second lock rather than the first: if the signing secret ever leaks,
// a forged envelope still cannot point this machine's API key at a host of somebody else's choosing.
function url_is_upstream($url) {
  $base = instance_base();
  if ($base === '' || !is_string($url) || $url === '') {
    return false;
  }
  return strncmp($url, $base . '/', strlen($base) + 1) === 0;
}

// Whether enough is configured to do anything at all, and what is missing if not. Used by the
// dashboard and by tools/selftest.php, so both say the same words.
function settings_gaps() {
  $gaps = [];
  if (!secrets_available()) {
    $gaps[] = 'SECRET_KEY is not set in config.local.php, so no secret can be stored yet.';
  }
  if (instance_base() === '') {
    $gaps[] = 'No instance URL. Nothing knows which Beeblebrox this machine works for.';
  }
  if (!setting_secret_is_set('api_key')) {
    $gaps[] = 'No API key. Work cannot be claimed, read or reported without one — issue one on the ' .
      'instance at ' . (instance_base() === '' ? '<instance>/keys.php' : instance_base() . '/keys.php') .
      ' and paste it into the settings page.';
  }
  if (setting_bool('accept_webhooks') && !setting_secret_is_set('webhook_secret')) {
    $gaps[] = 'Webhooks are switched on but no signing secret is stored, so every envelope is refused.';
  }
  if (!setting_bool('poll_enabled') && !setting_bool('accept_webhooks')) {
    $gaps[] = 'Neither polling nor webhooks are on, so no work will ever arrive.';
  }
  if (setting('admin_password_hash') === '') {
    $gaps[] = 'No password is set for these pages.';
  }
  return $gaps;
}
