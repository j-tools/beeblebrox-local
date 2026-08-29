<?php
// Encryption for the few values that have to be stored and later used, rather than stored and later
// compared.
//
// Passwords and API keys are hashed, because nothing ever needs them back. A webhook signing secret is
// the opposite: the platform has to produce the same HMAC the receiver will, so it needs the plaintext.
// Same for a provider's API key when that arrives. Those cannot be hashed and must not sit in the
// database in the clear, so they are encrypted under SECRET_KEY — which lives in the environment and
// never in the database, so a database dump on its own is not a credential breach.
//
// Losing SECRET_KEY means every stored secret has to be entered again. That is the documented trade and
// it is why the key belongs in the instance's env alongside its database password, backed up with it.

// The raw 32 bytes AES-256 needs, derived from whatever form the configured key takes.
//
// A 64-character hex string — what the runbook tells you to generate — is used as the 32 bytes it
// represents. Anything else is hashed to 32 bytes, so a key somebody typed by hand still works rather
// than failing on a length check.
function secrets_key() {
  static $key = null;
  if ($key !== null) {
    return $key;
  }
  $configured = (string)(bbl_config()['secret_key'] ?? '');
  if ($configured === '') {
    throw new RuntimeException(
      'SECRET_KEY is not set for this instance, so secrets cannot be stored. Generate 32 random bytes ' .
      'as hex and set it in the environment.');
  }
  $key = (strlen($configured) === 64 && ctype_xdigit($configured))
    ? hex2bin($configured)
    : hash('sha256', $configured, true);
  return $key;
}

// Whether anything can be encrypted at all. Lets a page say what is missing instead of throwing at the
// moment somebody presses save.
function secrets_available() {
  return (string)(bbl_config()['secret_key'] ?? '') !== '';
}

// Authenticated encryption, so a value that has been tampered with fails to decrypt rather than
// decrypting to something else. The version prefix is there so the algorithm can be changed later
// without having to guess what an existing row was encrypted with.
function secrets_encrypt($plaintext) {
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt((string)$plaintext, 'aes-256-gcm', secrets_key(),
    OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) {
    throw new RuntimeException('Could not encrypt: ' . openssl_error_string());
  }
  return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function secrets_decrypt($stored) {
  $stored = (string)$stored;
  if (strncmp($stored, 'v1:', 3) !== 0) {
    throw new RuntimeException('Stored secret is not in a format this version understands.');
  }
  $raw = base64_decode(substr($stored, 3), true);
  if ($raw === false || strlen($raw) < 29) {
    throw new RuntimeException('Stored secret is truncated or corrupt.');
  }
  $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secrets_key(),
    OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
  if ($plain === false) {
    // Either the key changed or the value was altered. Both mean the same thing to a caller: this
    // secret is no longer usable and has to be entered again.
    throw new RuntimeException(
      'Could not decrypt a stored secret. Either SECRET_KEY has changed since it was saved, or the ' .
      'value has been altered. Enter it again.');
  }
  return $plain;
}

// What to show about a secret without showing it. A stored value proves only that one exists.
function secrets_hint($stored) {
  return (string)$stored === '' ? 'not set' : 'set';
}
