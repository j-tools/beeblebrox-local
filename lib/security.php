<?php
// Deciding whether an envelope is really from the instance.
//
// This matters more here than it would in most receivers. Accepting an envelope eventually starts an
// agent with file and command access on somebody's own machine, so the failure mode of getting this
// wrong is not a corrupt row — it is a stranger's instructions running as you. Everything below errs
// towards refusing.

// The signature the platform produces, reproduced here. Over "timestamp.body" rather than the body
// alone, so a captured envelope cannot be replayed tomorrow with its signature still valid.
function signature_expected($body, $secret, $timestamp) {
  return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

// Returns null when the envelope is acceptable, or a sentence saying why it is not. A sentence rather
// than a code because it goes straight into the webhook log, which is the only place anybody will
// look when deliveries are failing.
//
// The reason is deliberately specific in the log and deliberately vague in the HTTP response: telling
// a caller which half of the check it failed is telling it how to pass.
function signature_check($body, $signature, $timestamp, $secret, $tolerance_seconds) {
  if ($secret === '') {
    return 'no signing secret is configured on this receiver';
  }
  if ($signature === '') {
    return 'no X-Beeblebrox-Signature header';
  }
  if ($timestamp === '' || !ctype_digit((string)$timestamp)) {
    return 'no usable X-Beeblebrox-Timestamp header';
  }

  $drift = abs(time() - (int)$timestamp);
  if ($drift > $tolerance_seconds) {
    return "the timestamp is {$drift}s away from this machine's clock, past the {$tolerance_seconds}s " .
           'tolerance — either a replay, or the two clocks disagree';
  }

  // hash_equals, so a wrong signature cannot be discovered a character at a time.
  if (!hash_equals(signature_expected($body, $secret, (string)$timestamp), $signature)) {
    return 'the signature does not match — this receiver and the dispatcher hold different secrets';
  }
  return null;
}

// An empty list means any address. That is the right default rather than a lax one: when the instance
// reaches this machine through a tunnel, the address it arrives from belongs to the tunnel and is not
// stable enough to pin.
function ip_allowed($remote, $list) {
  $list = trim((string)$list);
  if ($list === '') {
    return true;
  }
  foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $allowed) {
    if ($allowed === $remote) {
      return true;
    }
  }
  return false;
}

// Splits a command template into argv without going near a shell.
//
// This is the reason nothing from a ticket can become part of a command line: the template is
// configuration a person typed on the settings page, it is tokenised here, and the placeholders are
// substituted into whole arguments afterwards — never into the string being parsed. A workspace path
// with a space in it is one argument, and a project named `; rm -rf /` is a project name.
function command_split($template) {
  $args = [];
  $current = '';
  $in_quotes = false;
  $has_token = false;
  $length = strlen($template);

  for ($i = 0; $i < $length; $i++) {
    $char = $template[$i];
    if ($char === '"') {
      $in_quotes = !$in_quotes;
      $has_token = true;
      continue;
    }
    if (!$in_quotes && ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r")) {
      if ($has_token) {
        $args[] = $current;
        $current = '';
        $has_token = false;
      }
      continue;
    }
    $current .= $char;
    $has_token = true;
  }
  if ($has_token) {
    $args[] = $current;
  }
  return $args;
}

// Fills {placeholders} in each argument. Done after splitting, so a value containing a space or a
// quote stays one argument no matter what it contains.
function command_fill(array $args, array $values) {
  return array_map(function ($arg) use ($values) {
    foreach ($values as $name => $value) {
      $arg = str_replace('{' . $name . '}', (string)$value, $arg);
    }
    return $arg;
  }, $args);
}
