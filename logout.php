<?php
// Signing out is a POST, so a link somewhere else cannot sign you out by being loaded.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/session.php';

bbl_session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  bbl_check_csrf();
  $_SESSION = [];
  session_destroy();
}
header('Location: index.php');
