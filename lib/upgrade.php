<?php
// Installing a newer build over this one, from inside the application.
//
// The download page and the drawer could say a new build was out, and that was the end of what this
// could do about it: somebody had to find the machine, fetch a zip and unpack it over the top. On a
// machine that runs unattended for months, "somebody has to remember" is the same as "it does
// not happen", and a worker on an old build is exactly the one nobody notices.
//
// What happens, in order:
//
//   1. Prove this copy can be written to at all, by writing — not by asking is_writable(), which
//      answers from the permission bits and is wrong under an ACL, a read-only mount or Windows.
//   2. Fetch the release's .tar.gz and its SHA256SUMS from GitHub, and check the hash.
//   3. Unpack it under data/, which is not served, and check it is the build it claims to be.
//   4. Copy every file it will replace into data/backups/build-<current>/ first.
//   5. Copy the new files over the old ones.
//   6. Apply any migrations the new build brought.
//
// Nothing here touches config.local.php or data/: neither is in the archive, and the archive is the
// only thing this copies. A file that vanished between builds is removed only if the manifest from
// the previous upgrade says this application put it there — anything somebody added themselves is
// left alone, which is why the deletions wait for the second upgrade rather than guessing on the
// first.
//
// On the hash: the archive and the sums come from the same release over the same TLS connection, so
// this proves a complete download and not an honest one. GitHub over https is the trust anchor
// either way. A signature would be the thing that changes that, and would need a key of ours in the
// archive to check against.

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/updates.php';
require_once __DIR__ . '/runner.php';

// The name the release asset carries, and the directory inside it.
function upgrade_archive_name() {
  return 'beeblebrox-local';
}

function upgrade_install_root() {
  return dirname(__DIR__);
}

// Somewhere writable that is not served. The database's own directory is both by definition — it
// holds the SQLite file and ships with an .htaccess denying the lot.
function upgrade_data_root() {
  return dirname((string)bbl_config()['db_file']);
}

function upgrade_work_root() {
  return upgrade_data_root() . '/upgrade';
}

function upgrade_backup_root() {
  return upgrade_data_root() . '/backups';
}

function upgrade_manifest_file() {
  return upgrade_data_root() . '/installed-files.json';
}

// ---------------------------------------------------------------------------------------------
// Whether this copy can upgrade itself

// The directories an archive writes into: the install's own root and every directory of code under
// it. data/, and anything else this application writes to rather than ships, is not among them.
function upgrade_target_dirs() {
  $root = upgrade_install_root();
  $dirs = [$root];
  foreach (['assets', 'db', 'db/migrations', 'lib', 'tools'] as $rel) {
    if (is_dir($root . '/' . $rel)) {
      $dirs[] = $root . '/' . $rel;
    }
  }
  return $dirs;
}

// Writes a file and deletes it again, which is the only honest answer to "can this be written to".
//
// The warning from a refused write is the measurement, not a fault, so it is suppressed here and
// nowhere else in this file.
function upgrade_dir_is_writable($dir) {
  $probe = rtrim($dir, '/\\') . '/.upgrade-probe';
  $written = @file_put_contents($probe, 'probe');
  if ($written === false) {
    return false;
  }
  @unlink($probe);
  return true;
}

// What stops this machine upgrading itself, as sentences a person can act on. Empty means it can.
function upgrade_blockers() {
  $blockers = [];
  if (bbl_build()['number'] === null) {
    $blockers[] = 'This copy came from git rather than from a download, and has no build number to ' .
      'compare or replace. Upgrade it with git pull, which is both quicker and reversible.';
  }
  if (!class_exists('PharData')) {
    $blockers[] = 'PHP here has no phar extension, so there is nothing to unpack the download with. ' .
      'It is enabled in a default PHP build; a host that has removed it means upgrading by hand.';
  }
  if (!function_exists('curl_init')) {
    $blockers[] = 'PHP here has no curl extension, so the download cannot be fetched.';
  }

  $unwritable = [];
  foreach (upgrade_target_dirs() as $dir) {
    if (!upgrade_dir_is_writable($dir)) {
      $unwritable[] = upgrade_relative_path($dir);
    }
  }
  if ($unwritable) {
    $blockers[] = 'The files here cannot be replaced by the web server: ' .
      implode(', ', array_map(function ($d) { return $d === '' ? 'the install directory' : $d . '/'; },
        $unwritable)) .
      ' cannot be written to. That is a perfectly sound way to run this — and it means upgrading is ' .
      'a job for whoever owns the files, from a terminal, with tools/upgrade.php or by unpacking the ' .
      'download by hand.';
  }
  if (!is_dir(upgrade_data_root()) || !upgrade_dir_is_writable(upgrade_data_root())) {
    $blockers[] = 'The data directory cannot be written to, so there is nowhere to put the download ' .
      'or to keep a copy of the files being replaced.';
  }
  return $blockers;
}

// A path as somebody thinks of it: relative to the install, forward slashes, '' for the root itself.
function upgrade_relative_path($path) {
  $root = str_replace('\\', '/', upgrade_install_root());
  $path = str_replace('\\', '/', $path);
  if (strncmp($path, $root, strlen($root)) === 0) {
    $path = ltrim(substr($path, strlen($root)), '/');
  }
  return $path;
}

// ---------------------------------------------------------------------------------------------
// The download

function upgrade_release_url($build, $file) {
  return 'https://github.com/' . updates_repo() . '/releases/download/build-' . (int)$build . '/' . $file;
}

// Fetches one release file to a path, or throws saying which one and why. Not silent like the version
// check: somebody pressed a button and is waiting for this.
function upgrade_fetch($url, $to) {
  $handle = fopen($to, 'wb');
  if ($handle === false) {
    throw new RuntimeException("Could not open {$to} for writing.");
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FILE           => $handle,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FAILONERROR    => true,
    CURLOPT_HTTPHEADER     => ['User-Agent: ' . upgrade_archive_name() . '/upgrade'],
  ]);
  $ok = curl_exec($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($handle);

  if ($ok === false) {
    @unlink($to);
    throw new RuntimeException("Could not fetch {$url}: " .
      ($error !== '' ? $error : "HTTP {$status}") . '.');
  }
}

// The hash SHA256SUMS gives for one file. Its format is one "<hash>  <name>" per line, which is what
// sha256sum writes and what every checker expects.
function upgrade_expected_hash($sums, $filename) {
  foreach (preg_split('/\r?\n/', (string)$sums) as $line) {
    if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m) !== 1) {
      continue;
    }
    if (trim($m[2]) === $filename) {
      return strtolower($m[1]);
    }
  }
  return '';
}

// ---------------------------------------------------------------------------------------------
// Staging

// Every file in a directory tree, as paths relative to it, sorted. Directories are not listed: they
// are made as needed by whatever copies into them.
function upgrade_tree_files($root) {
  $root = rtrim(str_replace('\\', '/', $root), '/');
  if (!is_dir($root)) {
    return [];
  }
  $found = [];
  $walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST);
  foreach ($walk as $entry) {
    if ($entry->isFile()) {
      $found[] = ltrim(substr(str_replace('\\', '/', $entry->getPathname()), strlen($root)), '/');
    }
  }
  sort($found);
  return $found;
}

function upgrade_rmtree($path) {
  if (!is_dir($path)) {
    return;
  }
  $walk = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($walk as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
  }
  rmdir($path);
}

// Downloads a build, checks it and unpacks it. Returns the directory holding the new copy — the one
// with hook.php in it, not the one the archive was unpacked into.
function upgrade_stage($build) {
  $build = (int)$build;
  $work = upgrade_work_root();
  if (!is_dir($work) && !mkdir($work, 0775, true)) {
    throw new RuntimeException("Could not make a working directory at {$work}.");
  }

  $name = upgrade_archive_name() . '.tar.gz';
  $archive = $work . '/' . upgrade_archive_name() . '-' . $build . '.tar.gz';
  upgrade_fetch(upgrade_release_url($build, $name), $archive);

  // The sums are fetched second and separately, so a release that has an archive but no sums file
  // fails here rather than after the copy has begun.
  $sums_file = $work . '/SHA256SUMS';
  upgrade_fetch(upgrade_release_url($build, 'SHA256SUMS'), $sums_file);
  $expected = upgrade_expected_hash(file_get_contents($sums_file), $name);
  if ($expected === '') {
    throw new RuntimeException("The release's SHA256SUMS does not mention {$name}, so the download " .
      'cannot be checked. Nothing has been changed.');
  }
  $actual = hash_file('sha256', $archive);
  if (!hash_equals($expected, $actual)) {
    @unlink($archive);
    throw new RuntimeException('The download does not match the hash the release publishes for it, ' .
      'so it arrived damaged or incomplete. Nothing has been changed; trying again is safe.');
  }

  $dir = $work . '/build-' . $build;
  upgrade_rmtree($dir);
  (new PharData($archive))->extractTo($dir, null, true);

  // The archive carries its own directory, and the build number written into it by the release
  // workflow. Both are checked: a tarball whose BUILD disagrees with the tag it was fetched from is
  // not something to copy over a working install.
  $root = $dir . '/' . upgrade_archive_name();
  if (!is_file($root . '/hook.php')) {
    throw new RuntimeException('The download does not look like this application — there is no ' .
      'hook.php in it. Nothing has been changed.');
  }
  $stated = trim((string)@file_get_contents($root . '/BUILD'));
  if ($stated !== (string)$build) {
    throw new RuntimeException("The download says it is build \"{$stated}\" but was fetched as build " .
      "{$build}. Nothing has been changed.");
  }
  return $root;
}

// ---------------------------------------------------------------------------------------------
// Applying

function upgrade_read_manifest() {
  $file = upgrade_manifest_file();
  if (!is_file($file)) {
    return null;
  }
  $decoded = json_decode((string)file_get_contents($file), true);
  return isset($decoded['files']) && is_array($decoded['files']) ? $decoded : null;
}

function upgrade_write_manifest($build, array $files) {
  file_put_contents(upgrade_manifest_file(), json_encode(
    ['build' => (int)$build, 'installed_at' => date('c'), 'files' => $files],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Files the last upgrade installed that this build no longer has. Only ever from a manifest: without
// one there is no way to tell a file this application shipped from a file somebody put there, and
// deleting on that guess is how an upgrade eats somebody's own .htaccess.
function upgrade_orphans(array $new_files) {
  $manifest = upgrade_read_manifest();
  if ($manifest === null) {
    return [];
  }
  return array_values(array_diff($manifest['files'], $new_files));
}

function upgrade_copy_file($from, $to) {
  $dir = dirname($to);
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    throw new RuntimeException('Could not make ' . upgrade_relative_path($dir) . '.');
  }
  if (!copy($from, $to)) {
    throw new RuntimeException('Could not write ' . upgrade_relative_path($to) .
      '. Everything replaced so far is in the backup, and "restore" puts it back.');
  }
}

// Copies the staged build over this one. Returns what it did.
//
// Every file that is about to be overwritten is copied into the backup first — all of them, before
// any of them is replaced — so a copy that dies half way has a complete previous build sitting next
// to it rather than a partial one.
function upgrade_apply($staged_root, $to_build) {
  // The runner's own lock, held for the whole copy. A pass that began while files were being
  // replaced would run an agent with half of one build and half of another — and the lock is
  // already the thing that keeps two runners apart, so there is nothing new to invent here.
  $lock = runner_lock();
  if ($lock === null) {
    throw new RuntimeException('A job is running right now, so the files are in use. This is worth ' .
      'waiting for rather than forcing: try again when the dashboard shows nothing in flight.');
  }
  try {
    return upgrade_copy_over($staged_root, $to_build);
  } finally {
    runner_unlock($lock);
  }
}

// The copy itself, with the lock already held.
function upgrade_copy_over($staged_root, $to_build) {
  $from_build = bbl_build()['number'];
  $install = upgrade_install_root();
  $files = upgrade_tree_files($staged_root);
  if (!$files) {
    throw new RuntimeException('The staged build has no files in it.');
  }

  $backup = upgrade_backup_root() . '/build-' . ($from_build === null ? 'unknown' : (int)$from_build);
  upgrade_rmtree($backup);
  $kept = 0;
  foreach ($files as $rel) {
    $target = $install . '/' . $rel;
    if (is_file($target)) {
      upgrade_copy_file($target, $backup . '/' . $rel);
      $kept++;
    }
  }

  foreach ($files as $rel) {
    upgrade_copy_file($staged_root . '/' . $rel, $install . '/' . $rel);
  }

  $removed = [];
  foreach (upgrade_orphans($files) as $rel) {
    $target = $install . '/' . $rel;
    if (is_file($target)) {
      upgrade_copy_file($target, $backup . '/' . $rel);
      if (unlink($target)) {
        $removed[] = $rel;
      }
    }
  }
  upgrade_write_manifest($to_build, $files);

  // Compiled copies of the files just replaced would otherwise be served until the cache noticed,
  // which on a host with validate_timestamps off is never.
  if (function_exists('opcache_reset')) {
    opcache_reset();
  }

  // Required here rather than at the top of the file: applying migrations is the one thing in
  // this library that needs the database open, and everything else in it stays loadable
  // without one.
  require_once __DIR__ . '/migrate.php';
  $migrations = migrations_apply();

  // So the drawer stops offering the build that was just installed, without waiting out the day the
  // version check normally caches for.
  setting_set('latest_checked_at', '0');

  return [
    'from'       => $from_build,
    'to'         => (int)$to_build,
    'files'      => count($files),
    'backed_up'  => $kept,
    'removed'    => $removed,
    'backup'     => upgrade_relative_path($backup),
    'migrations' => $migrations,
  ];
}

// Which build is sitting in the backup, if any, and how many files it holds.
function upgrade_backup_available() {
  $root = upgrade_backup_root();
  if (!is_dir($root)) {
    return null;
  }
  $best = null;
  foreach (glob($root . '/build-*') ?: [] as $dir) {
    if (!is_dir($dir) || preg_match('/build-(\d+)$/', $dir, $m) !== 1) {
      continue;
    }
    $files = upgrade_tree_files($dir);
    if ($files && ($best === null || (int)$m[1] > $best['build'])) {
      $best = ['build' => (int)$m[1], 'files' => count($files), 'dir' => $dir];
    }
  }
  return $best;
}

// Puts a backup back. The same copy in the other direction, and deliberately not clever: it restores
// what it has and says how much that was, rather than trying to work out what the failed upgrade did.
function upgrade_restore() {
  $backup = upgrade_backup_available();
  if ($backup === null) {
    throw new RuntimeException('There is no backup to restore.');
  }
  $install = upgrade_install_root();
  $files = upgrade_tree_files($backup['dir']);
  foreach ($files as $rel) {
    upgrade_copy_file($backup['dir'] . '/' . $rel, $install . '/' . $rel);
  }
  if (function_exists('opcache_reset')) {
    opcache_reset();
  }
  setting_set('latest_checked_at', '0');
  return ['build' => $backup['build'], 'files' => count($files)];
}
