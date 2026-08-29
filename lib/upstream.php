<?php
// The client for the Beeblebrox instance. Every request this machine makes to the platform goes
// through here, so the bearer token is attached in one place and no call site can forget it.
//
// Two rules hold for every call, and both are about the same worry — that a URL this machine did not
// choose ends up carrying its API key:
//
//   A URL taken from an envelope is checked against the configured instance before it is fetched. The
//   signature already proves who sent the envelope; this is what still holds if the signing secret
//   leaks.
//
//   Redirects are never followed. A 302 is a host asking to be given the token instead, and there is
//   no legitimate reason for the API to send one.

require_once __DIR__ . '/settings.php';

function upstream_request($method, $url, $body = null, $accept = 'application/json') {
  if (!url_is_upstream($url)) {
    return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null,
            'error' => "Refused to call {$url}: it is not on the configured instance (" .
                       (instance_base() ?: 'none set') . ')'];
  }

  $key = setting_secret('api_key');
  if ($key === '') {
    return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null,
            'error' => 'No API key is configured, so the instance cannot be called.'];
  }

  $headers = [
    'Authorization: Bearer ' . $key,
    'Accept: ' . $accept,
    'User-Agent: beeblebrox-local/1',
  ];
  $ch = curl_init($url);
  $options = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
  ];
  if ($body !== null) {
    $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers[] = 'Content-Type: application/json';
  }
  $options[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $options);

  $response = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $transport = curl_error($ch);
  curl_close($ch);

  $text = $response === false ? '' : (string)$response;
  $json = null;
  if ($text !== '') {
    $decoded = json_decode($text, true);
    $json = is_array($decoded) ? $decoded : null;
  }

  $ok = $status >= 200 && $status < 300;
  $error = '';
  if ($transport !== '') {
    $error = $transport;
  } elseif (!$ok) {
    // The API answers a refusal with {"error": "..."} and the message is the useful part. Falling
    // back to the raw body matters for the cases that never reach the API at all — a proxy's 502.
    $error = "HTTP {$status}: " . ($json['error'] ?? mb_strimwidth(trim($text), 0, 300, '…'));
  }

  return ['ok' => $ok, 'status' => $status, 'body' => $text, 'json' => $json, 'error' => $error];
}

// Unauthenticated on the instance, but it still goes through the URL check, because the useful
// question is "can I reach the instance I am configured for", not "can I reach a host".
function upstream_health() {
  return upstream_request('GET', instance_base() . '/api/health');
}

function upstream_url($path) {
  return instance_base() . '/' . ltrim($path, '/');
}

// Open work the instance is willing to hand out. dispatch filters by how a task is meant to be
// started: 'webhook' is work meant for a machine, 'manual' is work nothing else will start.
function upstream_open_tasks($dispatch = 'webhook', $role = null) {
  $query = ['status' => 'open'];
  if ($dispatch !== 'any') {
    $query['dispatch'] = $dispatch;
  }
  if ($role !== null && $role !== '') {
    $query['role'] = $role;
  }
  return upstream_request('GET', upstream_url('api/tasks') . '?' . http_build_query($query));
}

// Everything about one task: its role and briefing, the project, and — the part that decides what a
// worker is allowed to say when it is done — allowed_next.
function upstream_task_context($task_id) {
  return upstream_request('GET', upstream_url('api/tasks/' . (int)$task_id));
}

// The whole ticket as one markdown document. This is what the agent actually reads.
function upstream_handoff_markdown($chain_id) {
  return upstream_request('GET', upstream_url('api/chains/' . (int)$chain_id . '/markdown'),
    null, 'text/markdown');
}

// Claiming succeeds exactly once, which is what makes a replayed envelope harmless and what keeps two
// workers off the same task. A 409 is not an error; it is the answer.
function upstream_claim($task_id) {
  return upstream_request('POST', upstream_url('api/tasks/' . (int)$task_id . '/claim'), []);
}

function upstream_complete($task_id, array $payload) {
  return upstream_request('POST', upstream_url('api/tasks/' . (int)$task_id . '/complete'), $payload);
}

function upstream_fail($task_id, $error) {
  return upstream_request('POST', upstream_url('api/tasks/' . (int)$task_id . '/fail'),
    ['error' => $error]);
}
