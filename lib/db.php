<?php
// Database access. Every query goes through these helpers, so there is exactly one place where
// values are bound and no code path where a value can reach SQL unbound.
//
// SQLite, in one file. This runs on one machine, for one person, and holds at most a few thousand
// rows — so a database server would be a second thing to install, a second set of credentials to
// get right, and a second thing that can be down, in exchange for nothing this actually uses.
//
// Timestamps are local time throughout, written either by PHP's date() or by a column default of
// datetime('now','localtime'). SQLite's bare CURRENT_TIMESTAMP is UTC, which would put two clocks in
// one table and show a job log two hours out for anybody east of London.

function db() {
  static $pdo = null;
  if ($pdo !== null) {
    return $pdo;
  }

  $file = bbl_config()['db_file'];
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException("Could not create {$dir} to keep the database in.");
  }

  try {
    $pdo = new PDO('sqlite:' . $file, null, null, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      // Values come back as PHP strings, the way mysqli returned them. Every caller already casts
      // what it needs as an int, and changing that here would mean auditing all of them.
      PDO::ATTR_STRINGIFY_FETCHES  => true,
    ]);
  } catch (PDOException $e) {
    throw new RuntimeException('Could not open the database at ' . $file . ': ' . $e->getMessage());
  }

  // Off by default in SQLite, which would silently make job_events' foreign key decorative.
  $pdo->exec('PRAGMA foreign_keys = ON');
  // A reader no longer blocks a writer, which matters here because the runner holds the database
  // while an agent runs and somebody is usually watching a page at the same time.
  $pdo->exec('PRAGMA journal_mode = WAL');
  // And when they do collide, wait rather than failing instantly.
  $pdo->exec('PRAGMA busy_timeout = 5000');

  db_install($pdo);
  return $pdo;
}

// Creates the schema the first time anything opens the database, so installing this is unzipping it.
//
// Every statement in schema.sql is IF NOT EXISTS, and this runs inside a transaction, so two
// processes racing on the first request produce one schema rather than half of two.
function db_install(PDO $pdo) {
  $exists = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'settings'")
                ->fetchColumn();
  if ((int)$exists > 0) {
    return;
  }

  $schema = @file_get_contents(__DIR__ . '/../db/schema.sql');
  if ($schema === false) {
    throw new RuntimeException('db/schema.sql is missing, so the database cannot be created.');
  }

  $pdo->beginTransaction();
  try {
    $pdo->exec($schema);
    // Recorded as already applied, because schema.sql has every migration folded into it. Without
    // this the runner would try to apply them all to a schema that already has them.
    $insert = $pdo->prepare('INSERT OR IGNORE INTO schema_migrations (filename) VALUES (?)');
    foreach (glob(__DIR__ . '/../db/migrations/*.sql') ?: [] as $path) {
      $insert->execute([basename($path)]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw new RuntimeException('Could not create the database: ' . $e->getMessage());
  }
}

// Prepares, binds and executes. Integers are bound as integers so that LIMIT and numeric comparisons
// behave; everything else goes as a string, which is what SQLite's loose typing wants anyway.
function db_stmt($sql, $params = []) {
  $stmt = db()->prepare($sql);
  foreach (array_values($params) as $i => $value) {
    if ($value === null) {
      $stmt->bindValue($i + 1, null, PDO::PARAM_NULL);
    } elseif (is_int($value)) {
      $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
    } elseif (is_bool($value)) {
      $stmt->bindValue($i + 1, $value ? 1 : 0, PDO::PARAM_INT);
    } else {
      $stmt->bindValue($i + 1, $value);
    }
  }
  $stmt->execute();
  return $stmt;
}

function db_all($sql, $params = []) {
  return db_stmt($sql, $params)->fetchAll();
}

function db_one($sql, $params = []) {
  $row = db_stmt($sql, $params)->fetch();
  return $row === false ? null : $row;
}

// Returns the new id for an INSERT, otherwise the number of affected rows.
//
// Decided from the statement rather than from lastInsertId being non-zero: PDO keeps the last id
// around after an UPDATE, so asking it would report the id of whatever was inserted before.
function db_exec($sql, $params = []) {
  $stmt = db_stmt($sql, $params);
  if (preg_match('/^\s*INSERT\b/i', $sql)) {
    return (int)db()->lastInsertId();
  }
  return $stmt->rowCount();
}

function db_begin() {
  db()->beginTransaction();
}

function db_commit() {
  db()->commit();
}

function db_rollback() {
  if (db()->inTransaction()) {
    db()->rollBack();
  }
}

// Counts come back as strings, and a caller comparing one against an int gets it wrong silently.
// This makes the intent explicit at the call site.
function db_count($sql, $params = []) {
  $row = db_one($sql, $params);
  return $row === null ? 0 : (int)reset($row);
}

// Whether a table exists. Replaces the information_schema questions the MySQL version asked, which
// SQLite does not have.
function db_table_exists($table) {
  return db_count("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) > 0;
}

// The timestamp to write. One place, so nothing in this application can accidentally record UTC
// alongside a column whose default is local.
function db_now($offset_seconds = 0) {
  return date('Y-m-d H:i:s', time() + $offset_seconds);
}
