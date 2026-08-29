<?php
// Database access. Every query in the application goes through these helpers, so there is exactly one
// place where values are bound and no code path where a value can reach SQL unbound.

// Single connection per request, opened on first use.
function db() {
  static $conn = null;
  if ($conn === null) {
    $cfg = bbl_config();
    $conn = mysqli_connect(
      $cfg['db_host'],
      $cfg['db_user'],
      $cfg['db_password'],
      $cfg['db_name'],
      $cfg['db_port']
    );
    if (!$conn) {
      throw new RuntimeException('Database connection failed: ' . mysqli_connect_error());
    }
    mysqli_set_charset($conn, 'utf8mb4');
  }
  return $conn;
}

// Prepares, binds and executes. Types are derived from the PHP values rather than declared at every
// call site, which is what keeps callers readable enough that nobody is tempted to interpolate.
function db_stmt($sql, $params = []) {
  $stmt = mysqli_prepare(db(), $sql);
  if ($stmt === false) {
    throw new RuntimeException('Prepare failed: ' . mysqli_error(db()) . ' | ' . $sql);
  }
  if ($params) {
    $types = '';
    foreach ($params as $value) {
      if (is_int($value)) {
        $types .= 'i';
      } elseif (is_float($value)) {
        $types .= 'd';
      } else {
        $types .= 's';
      }
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
  }
  if (!mysqli_stmt_execute($stmt)) {
    throw new RuntimeException('Execute failed: ' . mysqli_stmt_error($stmt) . ' | ' . $sql);
  }
  return $stmt;
}

function db_all($sql, $params = []) {
  $stmt = db_stmt($sql, $params);
  $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
  mysqli_stmt_close($stmt);
  return $rows;
}

function db_one($sql, $params = []) {
  $stmt = db_stmt($sql, $params);
  $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  return $row ?: null;
}

// Returns the new id for an INSERT, otherwise the number of affected rows.
function db_exec($sql, $params = []) {
  $stmt = db_stmt($sql, $params);
  $insert_id = mysqli_insert_id(db());
  $affected = mysqli_stmt_affected_rows($stmt);
  mysqli_stmt_close($stmt);
  return $insert_id > 0 ? $insert_id : $affected;
}

function db_begin() {
  mysqli_begin_transaction(db());
}

function db_commit() {
  mysqli_commit(db());
}

function db_rollback() {
  mysqli_rollback(db());
}

// Counts come back from mysqlnd as native ints on prepared statements, but a caller that compares
// against a string still gets it wrong silently. This makes the intent explicit at the call site.
function db_count($sql, $params = []) {
  $row = db_one($sql, $params);
  return $row === null ? 0 : (int)reset($row);
}
