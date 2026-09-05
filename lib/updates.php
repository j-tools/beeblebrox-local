<?php
// Whether a newer build has been published.
//
// This exists because the number in the drawer answers half a question. "Build 30" is only useful
// next to "and 31 is out" — otherwise somebody has to remember to go and look, which is exactly the
// thing nobody does, and a worker quietly runs a build from March.
//
// The number is asked of GitHub's releases API rather than of the website. The release workflow tags
// every build there, so it is the one place that cannot be stale — the website is a separately built
// static image and would go out of date the moment this repository releases without it.
//
// Asked at most once a day, from a page render, and only ever while somebody is signed in and
// looking at the drawer. Deliberately not from hook.php or the runner: a delivery must not wait on
// GitHub being up, and a scheduled pass that made an outbound call every few minutes would be a
// worse citizen than the thing it is checking on.

require_once __DIR__ . '/settings.php';

function updates_repo() {
  return 'j-tools/beeblebrox-local';
}

// Where somebody is sent to get it. The website rather than the GitHub release, because the page
// explains what unpacking it over an existing install does and does not touch, and the release page
// is a list of files.
function updates_download_url() {
  return 'https://www.beeblebrox.cloud/local/';
}

// The newest published build, or null when nothing is known yet.
//
// Cached in the settings, and the interval depends on what is cached: a day once a number is known,
// an hour when none is, so a machine that was offline the first time it asked is not silent until
// tomorrow. A later failure simply leaves yesterday's number in place — a number one build stale is
// still a useful hint, and re-asking every hour to correct it would not be.
function updates_latest() {
  $known = (string)setting('latest_build');
  $checked = (int)setting('latest_checked_at');
  $interval = $known === '' ? 3600 : 86400;
  if ($checked > 0 && (time() - $checked) < $interval) {
    return $known === '' ? null : (int)$known;
  }

  $fetched = updates_fetch();
  setting_set('latest_checked_at', (string)time());
  if ($fetched !== null) {
    setting_set('latest_build', (string)$fetched);
    return $fetched;
  }
  return $known === '' ? null : (int)$known;
}

// The build number of the newest release, or null if that could not be established. Every failure is
// the same answer here — unreachable, rate limited, no releases yet, something unrecognizable in the
// tag — because there is nothing a person could do differently about any of them, and a page that
// says nothing is the right outcome for all four.
function updates_fetch() {
  if (!function_exists('curl_init')) {
    return null;
  }
  $ch = curl_init('https://api.github.com/repos/' . updates_repo() . '/releases/latest');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 4,
    CURLOPT_CONNECTTIMEOUT => 3,
    // GitHub refuses a request with no user agent, and the version tells their logs which build is
    // asking, which is the only thing this call says about the machine making it.
    CURLOPT_HTTPHEADER     => [
      'Accept: application/vnd.github+json',
      'User-Agent: beeblebrox-local/' . (bbl_build()['number'] ?? 'checkout'),
    ],
  ]);
  $body = (string)curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($status < 200 || $status >= 300) {
    return null;
  }
  return updates_parse($body);
}

// The build number out of a release payload, or null if there is not one in there.
//
// Separate from the call that fetches it because this is the half that can be wrong quietly — a
// scheme that changed, a repository with no releases yet, an error document with HTTP 200 on it —
// and the half a test can reach.
function updates_parse($body) {
  $decoded = json_decode((string)$body, true);
  if (!is_array($decoded) || !isset($decoded['tag_name']) || !is_string($decoded['tag_name'])) {
    return null;
  }
  // build-30. The workflow makes the tag, so anything else means the tagging scheme changed and this
  // has not caught up — which is not something to guess at.
  if (preg_match('/^build-(\d+)$/', $decoded['tag_name'], $m) !== 1) {
    return null;
  }
  return (int)$m[1];
}

// What the drawer shows, or null when there is nothing to say — which is the usual case and includes
// running from a checkout, since a working copy has no build number to compare and its own code is
// probably newer than any release.
function updates_available() {
  $mine = bbl_build()['number'];
  if ($mine === null) {
    return null;
  }
  try {
    $latest = updates_latest();
  } catch (Throwable $e) {
    // A version check is not worth a white page. Every other reader of the settings still throws.
    return null;
  }
  if ($latest === null || $latest <= $mine) {
    return null;
  }
  return ['latest' => $latest, 'url' => updates_download_url()];
}
