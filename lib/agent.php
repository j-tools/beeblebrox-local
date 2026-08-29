<?php
// Running the agent, and getting an answer out of it that the platform will accept.
//
// The agent is whatever the settings say it is — Claude Code is what this was built against, but the
// contract here is only "a command that reads a prompt on stdin and writes a result file", which is a
// low enough bar that swapping it is a settings change rather than a release.
//
// Two things are deliberate and worth not undoing:
//
//   The prompt goes in on stdin, never as an argument. A ticket is arbitrary text of arbitrary length;
//   Windows gives up on a command line somewhere around 32k, and a briefing reaches that easily.
//
//   The verdict comes back in a file the agent is told to write, not scraped out of its prose. A model
//   asked to end with a machine-readable block will sometimes explain the block instead. Asking for a
//   file makes the two impossible to confuse, and reading the prose is only the fallback.

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/settings.php';

// What the agent is told, on top of the ticket itself. Everything here is either a fact it cannot
// look up or a rule the platform will enforce on the way back in — nothing that belongs in a role
// briefing, because those live on the instance and are already in the handoff.
function agent_prompt(array $context, $handoff_markdown, $result_file) {
  $role = $context['role'] ?? [];
  $task = $context['task'] ?? [];
  $allowed = $context['allowed_next']['statuses'] ?? [];
  $nickname = $role['display_name'] ?? ($role['name'] ?? $role['slug'] ?? 'the worker');

  $out = [];
  $out[] = '# Your assignment';
  $out[] = '';
  $out[] = "You are **{$nickname}** (`" . ($role['slug'] ?? 'unknown') . '`), working task ' .
           (int)($task['id'] ?? 0) . ' on a Beeblebrox instance. The whole ticket is below. Do the ' .
           'work the role asks for, in the working directory you were started in.';
  $out[] = '';

  if (!empty($task['is_test'])) {
    $out[] = '> **This is a test run.** Describe what you would do and change nothing: no commits, ' .
             'no pushes, no deploys, no messages to anybody. Report as normal.';
    $out[] = '';
  }

  $out[] = '## Reporting back';
  $out[] = '';
  $out[] = 'When you are finished, write your verdict to this file, as JSON, and nothing else to it:';
  $out[] = '';
  $out[] = '    ' . $result_file;
  $out[] = '';
  $out[] = '```json';
  $out[] = '{';
  $out[] = '  "status": "<one of the statuses below>",';
  $out[] = '  "output": "<markdown: what you did, what you found, what the next role needs to know>",';
  $out[] = '  "communication": "<optional: one plain-language paragraph for a person, or omit>"';
  $out[] = '}';
  $out[] = '```';
  $out[] = '';

  if ($allowed) {
    $out[] = 'The status must be exactly one of:';
    $out[] = '';
    foreach ($allowed as $status) {
      $out[] = '- `' . $status . '`';
    }
    // The entry role settles which project a ticket belongs to, and nothing downstream can do it for
    // it. Said only when it applies, so every other role reads a shorter instruction.
    if (($role['scope'] ?? 'project') === 'company') {
      $out[] = '';
      $out[] = 'You may also set `"project_id": <number>` to say which project this belongs in.';
    }
  } else {
    $out[] = 'The instance did not offer any statuses for this step, which means it cannot route what ' .
             'you report. Do the work, write your output, and set `"status": ""` — a person will pick ' .
             'it up from there.';
  }
  $out[] = '';
  $out[] = 'Do not name a role to hand to. A worker reports a status and the flow decides what ' .
           'follows; naming a role is refused on the way in.';
  $out[] = '';
  $out[] = 'Your `output` is the only thing the next role will see. Written for it, not for you: what ' .
           'changed, where, what you could not do, and anything that would otherwise have to be ' .
           'rediscovered.';
  $out[] = '';
  $out[] = '---';
  $out[] = '';
  $out[] = $handoff_markdown;

  return implode("\n", $out) . "\n";
}

// Runs the command and waits for it, with a deadline. Returns exit code, both streams, and how long
// it took.
//
// proc_open is given an array, so the arguments are passed to the process as they are rather than
// through a shell. That is what makes a workspace path containing a space, or a project named
// something unfortunate, uninteresting rather than dangerous.
function agent_execute(array $argv, $cwd, $prompt, $timeout_seconds) {
  $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $pipes = [];
  $started = microtime(true);

  $process = @proc_open($argv, $descriptors, $pipes, $cwd);
  if (!is_resource($process)) {
    return ['ok' => false, 'exit_code' => null, 'stdout' => '', 'stderr' => '',
            'duration_ms' => 0,
            'error' => 'Could not start ' . $argv[0] . '. Check the agent command on the settings ' .
                       'page, and that the program is on PATH for the account the runner uses.'];
  }

  // Everything the agent gets to read is written here and then the pipe is shut, so a command that
  // waits for more input finds end-of-file rather than hanging until the deadline.
  fwrite($pipes[0], $prompt);
  fclose($pipes[0]);

  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);

  $stdout = '';
  $stderr = '';
  $deadline = $started + $timeout_seconds;
  $timed_out = false;

  while (true) {
    $read = [];
    if (!feof($pipes[1])) { $read[] = $pipes[1]; }
    if (!feof($pipes[2])) { $read[] = $pipes[2]; }

    if ($read) {
      $write = null;
      $except = null;
      // A second at a time rather than blocking indefinitely, so the deadline is checked even while
      // the agent is silent — which, during a long build, is most of the run.
      if (@stream_select($read, $write, $except, 1) === false) {
        break;
      }
      foreach ($read as $stream) {
        $chunk = fread($stream, 65536);
        if ($chunk === false || $chunk === '') {
          continue;
        }
        if ($stream === $pipes[1]) {
          $stdout .= $chunk;
        } else {
          $stderr .= $chunk;
        }
      }
    }

    $status = proc_get_status($process);
    if (!$status['running']) {
      // Drain whatever was written between the last read and the process exiting.
      $stdout .= (string)stream_get_contents($pipes[1]);
      $stderr .= (string)stream_get_contents($pipes[2]);
      break;
    }
    if (microtime(true) > $deadline) {
      $timed_out = true;
      proc_terminate($process);
      break;
    }
    if (!$read) {
      usleep(200000);
    }
  }

  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit_code = proc_close($process);
  $duration_ms = (int)round((microtime(true) - $started) * 1000);

  return [
    'ok'          => !$timed_out && $exit_code === 0,
    'exit_code'   => $timed_out ? null : $exit_code,
    'stdout'      => $stdout,
    'stderr'      => $stderr,
    'duration_ms' => $duration_ms,
    'error'       => $timed_out
      ? "The agent was still running after {$timeout_seconds}s and was stopped."
      : ($exit_code === 0 ? '' : "The agent exited with code {$exit_code}."),
  ];
}

// Claude Code's --output-format json wraps the run: the assistant's final text under 'result', plus
// what it cost. Anything that is not that shape is treated as plain output, so a different agent — or
// the same one without the flag — still works.
function agent_unwrap($stdout) {
  $decoded = json_decode(trim($stdout), true);
  if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
    return ['text' => $stdout, 'usage' => null];
  }
  $usage = $decoded['usage'] ?? [];
  return [
    'text'  => (string)$decoded['result'],
    'usage' => [
      'model'         => $decoded['model'] ?? null,
      'input_tokens'  => isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : null,
      'output_tokens' => isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : null,
      // The platform stores cost in microdollars, so it is converted once, here, rather than at each
      // call site guessing at the unit.
      'cost_microdollars' => isset($decoded['total_cost_usd'])
        ? (int)round((float)$decoded['total_cost_usd'] * 1000000) : null,
      'is_error'      => !empty($decoded['is_error']),
      'session_id'    => $decoded['session_id'] ?? null,
    ],
  ];
}

// The verdict, from the file if it is there and from the prose if it is not.
//
// Returns ['result' => array|null, 'source' => 'file'|'output'|null].
function agent_verdict($result_file, $text) {
  if (is_file($result_file)) {
    $decoded = json_decode((string)file_get_contents($result_file), true);
    if (is_array($decoded) && array_key_exists('status', $decoded)) {
      return ['result' => $decoded, 'source' => 'file'];
    }
  }

  // Fallback: the last fenced block in the answer that parses and names a status. The last rather
  // than the first, because a model that shows the shape before filling it in puts the real one
  // second.
  if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/s', (string)$text, $matches)) {
    foreach (array_reverse($matches[1]) as $candidate) {
      $decoded = json_decode($candidate, true);
      if (is_array($decoded) && array_key_exists('status', $decoded)) {
        return ['result' => $decoded, 'source' => 'output'];
      }
    }
  }

  return ['result' => null, 'source' => null];
}
