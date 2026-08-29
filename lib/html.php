<?php
// Output escaping. Its own file because the sign-in and set-password pages need it and deliberately
// load nothing else — pulling in the domain layer just to escape a string would drag the whole
// pipeline into pages that must work before anyone is authenticated.

function h($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
