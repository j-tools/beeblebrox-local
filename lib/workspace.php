<?php
// Giving a project somewhere to be worked on, without anybody typing it twice.
//
// The instance already knows what a project is: its repository, the branch workers commit to, the
// branch that is released. All three arrive with every task, in the context this worker fetches
// before it starts an agent. Until now the worker did nothing with them — an unmapped project ended
// in a message that *quoted the repository URL* and asked somebody to go and type that URL on the
// projects page.
//
// So: given a base directory to work in, the worker clones the repository the task names, records
// where it put it, and carries on. A project mapped by hand still wins — that is the answer for a
// checkout that has to live somewhere particular — and a project with no repository, or a worker with
// no base directory, gets the same message as before.
//
// What this deliberately does not do is keep the clone current. That is prepare_command's job, and it
// stays empty by default: a command that fetches and resets is one that can throw away somebody's
// uncommitted work, and this is their machine.

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/agent.php';

// Whether a repository address is one this may hand to git.
//
// This matters more than it looks. `git clone ext::sh -c whatever` runs a command, and an argument
// that begins with a dash is read as an option — so a URL is not a string to pass along, it is an
// instruction to check. The instance is trusted to name a repository; that is a smaller claim than
// trusting it to run anything at all as the person who owns this machine.
//
// Two shapes are allowed, which are the two shapes GitHub offers to copy: https://host/path and
// git@host:path. Everything else — ssh://, git://, file://, ext::, a scp path with no user, a URL
// with whitespace or a control character in it — is refused rather than repaired.
function workspace_repo_is_safe($url) {
  $url = (string)$url;
  if ($url === '' || strlen($url) > 300 || trim($url) !== $url) {
    return false;
  }
  if (preg_match('/[\s\x00-\x1f]/', $url) === 1) {
    return false;
  }
  if (preg_match('#^https://[a-z0-9.-]+(:\d+)?/[A-Za-z0-9._/~-]+$#i', $url) === 1) {
    return true;
  }
  return preg_match('#^git@[a-z0-9.-]+:[A-Za-z0-9._/~-]+$#i', $url) === 1;
}

// The directory name a repository should land in: the last part of its path, without .git.
//
// The repository's own name, because that is what somebody expects to find on disk and what every
// other checkout on their machine is called. The project's id is the fallback for an address whose
// path says nothing useful — it is unique, and it is what the instance calls the project anyway.
function workspace_directory_name($url, $project_id) {
  $url = (string)$url;
  $fallback = 'project-' . (int)$project_id;

  // The path, taken from the address rather than hunted for in it. Walking backwards from the last
  // slash looks equivalent and is not: it reads "github.com" out of https://github.com/ and the whole
  // host out of an address ending in a slash.
  if (preg_match('#^https?://#i', $url) === 1) {
    $path = (string)parse_url($url, PHP_URL_PATH);
  } else {
    $colon = strpos($url, ':');
    $path = $colon === false ? '' : substr($url, $colon + 1);
  }

  $path = preg_replace('/\.git$/i', '', trim($path, '/'));
  if ($path === '') {
    return $fallback;
  }
  $last = preg_replace('/[^A-Za-z0-9._-]/', '',
    (string)substr($path, (int)strrpos($path, '/') + 1));
  return $last === '' || $last === '.' || $last === '..' ? $fallback : $last;
}

// Clones one repository, shallow-free and on the branch the instance says workers commit to.
//
// A full clone rather than --depth 1: an agent asked to look at why something broke needs the
// history, and these are the repositories of one company rather than of the world.
function workspace_clone($url, $branch, $target) {
  $argv = ['git', 'clone'];
  if (trim((string)$branch) !== '') {
    $argv[] = '--branch';
    $argv[] = $branch;
  }
  // Everything after this is an operand, so an address that begins with a dash cannot become an
  // option. workspace_repo_is_safe() refuses those anyway; this is the second lock.
  $argv[] = '--';
  $argv[] = $url;
  $argv[] = $target;

  $run = agent_execute($argv, dirname($target), '', 600);
  $said = trim($run['stderr'] . "\n" . $run['stdout']);
  if (!empty($run['ok']) && (int)$run['exit_code'] === 0) {
    return ['ok' => true, 'said' => $said];
  }
  if (!empty($run['error'])) {
    return ['ok' => false, 'said' => $run['error']];
  }
  return ['ok' => false, 'said' => $said === '' ? 'git said nothing and exited ' .
    var_export($run['exit_code'], true) : $said];
}

// Makes a workspace for a project the instance has given this machine work for, and records it.
//
// Returns the project row on success — the same shape project_for_upstream() returns, so the runner
// carries on as though somebody had mapped it by hand. Returns null when there is nothing sensible to
// do, having said why in $why.
function workspace_adopt(array $context, &$why = null) {
  $project = $context['project'] ?? null;
  if (!is_array($project) || !isset($project['id'])) {
    $why = 'The task names no project.';
    return null;
  }
  $id = (int)$project['id'];
  $name = trim((string)($project['name'] ?? '')) ?: ('project ' . $id);
  $repo = (string)($project['git_repo_url'] ?? '');
  $branch = (string)($project['work_branch'] ?? '');

  $root = rtrim(str_replace('\\', '/', trim((string)setting('workspace_root'))), '/');
  if ($root === '') {
    $why = 'No base directory is set for work on this machine, so there is nowhere to put a clone.';
    return null;
  }
  if ($repo === '') {
    $why = "The instance does not name a repository for \"{$name}\", so there is nothing to clone.";
    return null;
  }
  if (!workspace_repo_is_safe($repo)) {
    $why = "The instance names \"{$repo}\" as the repository for \"{$name}\", and that is not an " .
      'address this will hand to git. Only https://host/path and git@host:path are accepted.';
    return null;
  }
  if (!is_dir($root) && !@mkdir($root, 0775, true)) {
    $why = "The base directory {$root} does not exist and could not be created.";
    return null;
  }

  $target = $root . '/' . workspace_directory_name($repo, $id);

  // Already there — somebody else's clone, or one from an earlier task. Adopted as it is found
  // rather than touched: a directory with work in it is not this function's to reset.
  if (is_dir($target)) {
    workspace_remember($id, $name, $target);
    $why = null;
    return project_for_upstream($id);
  }

  $clone = workspace_clone($repo, $branch, $target);
  if (!$clone['ok']) {
    // Whatever git managed to make before it failed is not a workspace, and leaving it would make the
    // next attempt adopt a broken directory.
    if (is_dir($target)) {
      workspace_remove($target);
    }
    $why = "Could not clone {$repo} into {$target}. git said: " .
      mb_strimwidth($clone['said'], 0, 400, '…');
    return null;
  }

  workspace_remember($id, $name, $target);
  $why = null;
  return project_for_upstream($id);
}

// The mapping, written the way the projects page writes it, so what the worker decided for itself is
// visible and editable in the same place as everything somebody set by hand. prepare_command is left
// empty on purpose.
function workspace_remember($upstream_project_id, $name, $path) {
  db_exec(
    'INSERT INTO projects (upstream_project_id, name, workspace_path, prepare_command, model, is_active)
       VALUES (?, ?, ?, NULL, NULL, 1)
     ON CONFLICT(upstream_project_id) DO UPDATE SET
       name = excluded.name, workspace_path = excluded.workspace_path, is_active = 1',
    [(int)$upstream_project_id, $name, str_replace('\\', '/', $path)]
  );
}

// Deletes a directory tree. Only ever called on one this function's own clone just failed to make.
function workspace_remove($path) {
  if (!is_dir($path)) {
    return;
  }
  $walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($walk as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
  }
  @rmdir($path);
}
