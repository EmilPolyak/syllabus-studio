<?php
// Syllabus Studio — publish endpoint (Apache/PHP, e.g. SiteGround).
// Receives a published program catalog from Studio and stores it as configs/<slug>.json.
//
// Protection model:
//   1) A shared publishing password (PUBLISH_TOKEN, sent as a Bearer header) gates publishing,
//      unpublishing, and recovery — every program director uses it.
//   2) A per-PD "personal password". The first publish of a slug records a bcrypt hash of it in
//      configs/<slug>.owner. Any later overwrite or unpublish of that slug must present the same
//      password, so one PD cannot change another PD's tools. It is never sent to instructors.
//   3) Logo uploads need NO password: they are validated as real images and stored under a content-hash
//      filename in /logos/, so an upload can never overwrite another logo and cannot host a script.

header('Content-Type: application/json');

// SET YOUR SECRET. Use a long random string. Keep it private. Shared by all program directors.
//   (Better: set it as an environment variable PUBLISH_TOKEN in Site Tools and leave the fallback unused.)
$TOKEN = getenv('PUBLISH_TOKEN') ?: 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';

// Read the payload first (we need it to decide whether this is a password-free logo upload).
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['error' => 'bad request']);
  exit;
}
$dir = __DIR__ . '/configs';
$logosDir = __DIR__ . '/logos';

// Create the configs folder and a guard that prevents the .owner hash files from being served.
function ensure_configs_dir($dir) {
  if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
  $ht = "$dir/.htaccess";
  if (!is_file($ht)) {
    // Mirrors dist/configs/.htaccess. Guarded so an Apache without mod_authz_core skips the
    // block instead of failing on an unknown directive.
    @file_put_contents($ht, <<<'HTACCESS'
<FilesMatch "\.owner$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>

HTACCESS
    );
  }
  return true;
}

// Detect the image type from the file's bytes (not a client-declared extension). Returns a safe
// extension or null. SVGs that carry scripts/handlers are rejected so an uploaded logo can't run code.
function sniff_image_ext($bytes) {
  if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
  if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
  if (strncmp($bytes, 'GIF87a', 6) === 0 || strncmp($bytes, 'GIF89a', 6) === 0) return 'gif';
  if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') return 'webp';
  if (stripos($bytes, '<svg') !== false) {
    if (preg_match('/<script|<foreignObject|javascript:|on[a-z]+\s*=/i', $bytes)) return null;
    return 'svg';
  }
  return null;
}

// Reduce a client-supplied filename to a safe basename: no directories, and only characters that are
// safe in a URL and on disk. The claimed extension is dropped (the real one is sniffed from the bytes)
// and every remaining dot becomes a dash, so the written name has exactly ONE extension — a
// double-extension payload like "..\evil.php.png" can only ever become "evil-php.<sniffed ext>".
function safe_logo_base($name) {
  $name = (string)$name;
  $slash = strrpos($name, '/');
  if ($slash !== false) $name = substr($name, $slash + 1);
  $back = strrpos($name, "\\");
  if ($back !== false) $name = substr($name, $back + 1);
  $dot = strrpos($name, '.');
  if ($dot !== false && $dot > 0) $name = substr($name, 0, $dot); // drop the claimed extension
  $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);          // no dots survive in the base
  $name = trim($name, '-_');
  if (strlen($name) > 80) $name = substr($name, 0, 80);
  return $name;
}

// Keep /logos/ as inert data: no scripts execute there even if a name ever slipped past the sanitiser.
function ensure_logos_dir($logosDir) {
  if (!is_dir($logosDir) && !mkdir($logosDir, 0775, true)) return false;
  $ht = "$logosDir/.htaccess";
  if (!is_file($ht)) {
    // Mirrors dist/logos/.htaccess. php_flag MUST stay inside <IfModule mod_php.c>: it is an
    // UNKNOWN directive under PHP-FPM or CGI, and an unguarded copy makes every request Apache
    // handles in this folder return 500 rather than simply being ignored.
    @file_put_contents($ht, <<<'HTACCESS'
<FilesMatch "\.(php|phtml|phar|phps|php[0-9]|cgi|pl|py|sh|inc)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>

<IfModule mod_mime.c>
  RemoveHandler .php .phtml .phar .cgi .pl .py
  AddType text/plain .php .phtml .phar
</IfModule>

<IfModule mod_php.c>
  php_flag engine off
</IfModule>

HTACCESS
    );
  }
  return true;
}

// Write a validated logo into /logos/, KEEPING the filename the program director uploaded so the file is
// recognisable on the server. The extension comes from the bytes, so the URL is canonical regardless of
// what the client claimed.
//
// Collision rule: many programs share one server, and two of them may both upload "logo.png". If the
// name is taken by a byte-identical file, that file is reused and nothing is written. If it is taken by a
// DIFFERENT image, a numeric suffix is added ("logo-2.png") — one program can never overwrite another's
// logo. The URL actually used is returned, so the editor stores whichever name it got.
function write_logo($logosDir, $name, $b64) {
  $base = safe_logo_base($name);
  $bytes = base64_decode((string)$b64, true);
  if ($bytes === false || strlen($bytes) < 8 || strlen($bytes) > 5 * 1024 * 1024) return null;
  $ext = sniff_image_ext($bytes);
  if ($ext === null) return null;
  if ($base === '') $base = 'logo';
  if (!ensure_logos_dir($logosDir)) return null;

  for ($n = 1; $n <= 200; $n++) {
    $candidate = $n === 1 ? "$base.$ext" : "$base-$n.$ext";
    $path = "$logosDir/$candidate";
    if (is_file($path)) {
      // Already on the server: reuse it when it is the same image, otherwise try the next suffix.
      if (@file_get_contents($path) === $bytes) return "/logos/$candidate";
      continue;
    }
    if (file_put_contents($path, $bytes) === false) return null;
    return "/logos/$candidate";
  }
  return null;
}

// ----- Password-free logo upload -----------------------------------------------------------------
// No shared password required: the payload is validated as a real image, the filename is reduced to a
// safe basename, and an existing file is only ever reused when it is byte-identical — so an upload can
// neither host a script nor clobber another program's logo. Returns the public URL the editor should use.
if (!empty($body['logoUpload'])) {
  $url = write_logo($logosDir, $body['name'] ?? '', $body['data'] ?? '');
  if ($url === null) {
    http_response_code(400);
    echo json_encode(['error' => 'bad logo']);
    exit;
  }
  echo json_encode(['ok' => true, 'url' => $url]);
  exit;
}

// ----- Policy administration ---------------------------------------------------------------------
// The /policy-admin UI edits the two centrally-governed policy sections and the academic calendar rules
// in defaults/. Whoever does this is NOT a program director — it is a registrar or provost's office
// role — so it has its OWN password, separate from the publishing token. Holding one grants nothing
// about the other.
//
// Writes are surgical: only fieldDefaults.gradeScale/academicPolicies, centralRevisions, and the
// calendar rules file are touched. Every other key in editor-defaults.json is read back and written out
// unchanged, so a deployment's identity, logo, technical support text and field defaults survive.
//
// SET YOUR SECRET. Long and random, and different from PUBLISH_TOKEN above.
//   (Better: set POLICY_TOKEN as an environment variable and leave the fallback unused.)
$POLICY_TOKEN = getenv('POLICY_TOKEN') ?: 'CHANGE_ME_TO_A_DIFFERENT_LONG_RANDOM_SECRET';

$defaultsDir = __DIR__ . '/defaults';
$historyDir = $defaultsDir . '/history';
$policyGoverned = ['gradeScale', 'academicPolicies'];

function policy_read_json($path) {
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

/** Write JSON the same way the editor does (2-space indent, unescaped slashes) so diffs stay readable. */
function policy_write_json($path, $data) {
  $text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($text === false) return false;
  return @file_put_contents($path, $text . "\n") !== false;
}

/** Archive ids for one kind, oldest first. Filenames are "<kind>-<n>.json". */
function policy_history_ids($historyDir, $kind) {
  $found = [];
  foreach ((glob("$historyDir/$kind-*.json") ?: []) as $file) {
    if (preg_match('/-(\d+)\.json$/', $file, $m)) $found[] = (int)$m[1];
  }
  sort($found);
  return $found;
}

/* The archive is the ONLY store of previous versions, and it lives here on the server — never in the
   admin's browser. That is deliberate: whoever holds the policy password must be able to step back
   through what was published from any computer, with nothing cached or carried over locally.

   Each entry is listed with the revision numbers it actually holds, so the UI can label a version by
   its real revision rather than by an archive position. */
function policy_history_entries($historyDir, $kind) {
  $entries = [];
  foreach (policy_history_ids($historyDir, $kind) as $id) {
    $data = policy_read_json("$historyDir/$kind-$id.json");
    if ($data === null) continue;
    if ($kind === 'calendar') {
      $entries[] = [
        'id' => $id,
        'savedAt' => isset($data['savedAt']) ? $data['savedAt'] : '',
        'revision' => isset($data['rules']['revision']) ? (int)$data['rules']['revision'] : $id
      ];
    } else {
      $entries[] = [
        'id' => $id,
        'savedAt' => isset($data['savedAt']) ? $data['savedAt'] : '',
        'revisions' => isset($data['revisions']) ? $data['revisions'] : new stdClass()
      ];
    }
  }
  return $entries;
}

if (!empty($body['policyAction'])) {
  $policyAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if ($policyAuth !== "Bearer $POLICY_TOKEN") {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
  }

  $action = (string)$body['policyAction'];
  $defaultsPath = "$defaultsDir/editor-defaults.json";
  $calendarPath = "$defaultsDir/calendar-rules.json";

  if ($action === 'get') {
    $defaults = policy_read_json($defaultsPath);
    $calendar = policy_read_json($calendarPath);
    if ($defaults === null || $calendar === null) {
      http_response_code(500);
      echo json_encode(['error' => 'defaults not readable', 'detail' => 'Could not read defaults/editor-defaults.json or defaults/calendar-rules.json.']);
      exit;
    }
    $sections = [];
    foreach ($policyGoverned as $key) {
      $sections[$key] = isset($defaults['fieldDefaults'][$key]['defaultValue']) ? $defaults['fieldDefaults'][$key]['defaultValue'] : '';
    }
    echo json_encode([
      'ok' => true,
      'sections' => $sections,
      'revisions' => isset($defaults['centralRevisions']) ? $defaults['centralRevisions'] : new stdClass(),
      'calendar' => $calendar,
      'history' => [
        'policies' => policy_history_entries($historyDir, 'policies'),
        'calendar' => policy_history_entries($historyDir, 'calendar')
      ],
      'writable' => is_writable($defaultsDir) && (!is_file($defaultsPath) || is_writable($defaultsPath))
    ]);
    exit;
  }

  // Recall one archived version so the admin can compare it with what is live. Served from the server's
  // own archive, so this works identically on any computer.
  if ($action === 'history') {
    $kind = ($body['kind'] ?? '') === 'calendar' ? 'calendar' : 'policies';
    $id = (int)($body['id'] ?? $body['revision'] ?? 0);
    $entry = policy_read_json("$historyDir/$kind-$id.json");
    if ($entry === null) {
      http_response_code(404);
      echo json_encode(['error' => 'no such version']);
      exit;
    }
    echo json_encode(['ok' => true, 'entry' => $entry]);
    exit;
  }

  if (!is_dir($historyDir) && !@mkdir($historyDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'history not writable', 'detail' => 'Could not create defaults/history/. Make defaults/ writable by the web server.']);
    exit;
  }

  if ($action === 'putPolicies') {
    $defaults = policy_read_json($defaultsPath);
    if ($defaults === null) {
      http_response_code(500);
      echo json_encode(['error' => 'defaults not readable']);
      exit;
    }
    $incoming = is_array($body['sections'] ?? null) ? $body['sections'] : [];
    $revisions = isset($defaults['centralRevisions']) && is_array($defaults['centralRevisions']) ? $defaults['centralRevisions'] : [];
    // Captured BEFORE anything is bumped: this is the version about to be replaced, and it is what
    // gets archived. See the note at the archive write below.
    $previousRevisions = $revisions;
    $previousSections = [
      'gradeScale' => isset($defaults['fieldDefaults']['gradeScale']['defaultValue']) ? $defaults['fieldDefaults']['gradeScale']['defaultValue'] : '',
      'academicPolicies' => isset($defaults['fieldDefaults']['academicPolicies']['defaultValue']) ? $defaults['fieldDefaults']['academicPolicies']['defaultValue'] : ''
    ];
    $changed = [];
    foreach ($policyGoverned as $key) {
      if (!array_key_exists($key, $incoming)) continue;          // section not submitted — leave it alone
      $text = (string)$incoming[$key];
      if ($text === '') continue;                                 // never publish an empty governed section
      $held = isset($defaults['fieldDefaults'][$key]['defaultValue']) ? $defaults['fieldDefaults'][$key]['defaultValue'] : '';
      if ($text === $held) continue;                              // unchanged — no bump, no churn
      if (!isset($defaults['fieldDefaults'][$key]) || !is_array($defaults['fieldDefaults'][$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown section', 'detail' => "fieldDefaults.$key is missing from editor-defaults.json."]);
        exit;
      }
      $defaults['fieldDefaults'][$key]['defaultValue'] = $text;
      $revisions[$key] = (int)(isset($revisions[$key]) ? $revisions[$key] : 0) + 1;
      $changed[] = $key;
    }
    if (!$changed) {
      echo json_encode(['ok' => true, 'changed' => [], 'revisions' => $revisions ?: new stdClass(), 'note' => 'nothing changed']);
      exit;
    }
    $defaults['centralRevisions'] = $revisions;
    if (!policy_write_json($defaultsPath, $defaults)) {
      http_response_code(500);
      echo json_encode(['error' => 'write failed', 'detail' => 'defaults/editor-defaults.json is not writable by the web server.']);
      exit;
    }
    /* Archive the version that was just REPLACED, not the one that is now live.

       Storing the new state instead would make the newest archive entry a duplicate of Live — so the
       first step back would show the same text you are already looking at — and it would never capture
       the wording that existed before the first-ever publish, leaving revision 1 unreachable. Keeping
       the outgoing version means Live is always the newest, and every earlier revision is reachable. */
    $ids = policy_history_ids($historyDir, 'policies');
    $newestId = $ids ? end($ids) : 0;
    $newest = $newestId ? policy_read_json("$historyDir/policies-$newestId.json") : null;
    $alreadyArchived = $newest !== null
      && isset($newest['sections'])
      && $newest['sections'] === $previousSections;
    $id = $newestId;
    if (!$alreadyArchived) {
      $id = $newestId + 1;
      policy_write_json("$historyDir/policies-$id.json", [
        'id' => $id,
        'savedAt' => gmdate('c'),
        'sections' => $previousSections,
        'revisions' => $previousRevisions ?: new stdClass()
      ]);
    }
    echo json_encode(['ok' => true, 'changed' => $changed, 'revisions' => $revisions, 'id' => $id]);
    exit;
  }

  if ($action === 'putCalendar') {
    $rules = is_array($body['rules'] ?? null) ? $body['rules'] : null;
    if ($rules === null || empty($rules['terms'])) {
      http_response_code(400);
      echo json_encode(['error' => 'bad rules']);
      exit;
    }
    $current = policy_read_json($calendarPath);
    $liveRevision = (int)(isset($current['revision']) ? $current['revision'] : 0);
    // The revision always advances past what is live, whichever version the admin submitted from.
    $rules['revision'] = $liveRevision + 1;
    if (!policy_write_json($calendarPath, $rules)) {
      http_response_code(500);
      echo json_encode(['error' => 'write failed', 'detail' => 'defaults/calendar-rules.json is not writable by the web server.']);
      exit;
    }
    // Archive the rules just replaced, under their own revision number — same reasoning as above.
    if ($current !== null && $liveRevision > 0 && !is_file("$historyDir/calendar-$liveRevision.json")) {
      policy_write_json("$historyDir/calendar-$liveRevision.json", [
        'id' => $liveRevision,
        'savedAt' => gmdate('c'),
        'rules' => $current
      ]);
    }
    echo json_encode(['ok' => true, 'revision' => $rules['revision']]);
    exit;
  }

  http_response_code(400);
  echo json_encode(['error' => 'unknown policy action']);
  exit;
}

// ----- Everything below requires the shared publishing password ----------------------------------
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth !== "Bearer $TOKEN") {
  http_response_code(401);
  echo json_encode(['error' => 'unauthorized']);
  exit;
}

$owner = isset($body['owner']) ? trim((string)$body['owner']) : '';

// Recovery: return the slugs whose owner hash matches this personal password.
if (!empty($body['list'])) {
  if ($owner === '') {
    http_response_code(400);
    echo json_encode(['error' => 'owner required']);
    exit;
  }
  $slugs = [];
  foreach ((glob("$dir/*.owner") ?: []) as $ownerFile) {
    $hash = trim((string)file_get_contents($ownerFile));
    if ($hash !== '' && password_verify($owner, $hash)) {
      $slugs[] = basename($ownerFile, '.owner');
    }
  }
  echo json_encode(['ok' => true, 'slugs' => $slugs]);
  exit;
}

// Validate the slug for publish/delete.
$slug = $body['slug'] ?? '';
$isDelete = !empty($body['delete']);
// Flat slug: lowercase letters, digits, hyphens, underscores (per-instructor uses "program__instructor").
if (!preg_match('#^[a-z0-9_-]+$#', $slug) || (!$isDelete && !isset($body['config']))) {
  http_response_code(400);
  echo json_encode(['error' => 'bad request']);
  exit;
}

$ownerFile = "$dir/$slug.owner";

// Ownership check: if this slug already has an owner, the personal password must match it.
if (is_file($ownerFile)) {
  $hash = trim((string)file_get_contents($ownerFile));
  if ($hash !== '' && !password_verify($owner, $hash)) {
    http_response_code(403);
    echo json_encode(['error' => 'wrong owner']);
    exit;
  }
}

// Unpublish: delete configs/<slug>.json (and its owner file). Succeed quietly if already gone.
if ($isDelete) {
  $path = "$dir/$slug.json";
  if (is_file($path) && !unlink($path)) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot delete config']);
    exit;
  }
  if (is_file($ownerFile)) @unlink($ownerFile);
  // Logos live in /logos/ under content-hash names and may be shared by other tools, so they are not
  // deleted here (an orphaned logo is harmless; deleting a shared one would break another tool).
  echo json_encode(['ok' => true, 'deleted' => true]);
  exit;
}

// Publish/refresh: write configs/<slug>.json next to this script (flat file, no subfolders).
if (!ensure_configs_dir($dir)) {
  http_response_code(500);
  echo json_encode(['error' => 'cannot create configs folder']);
  exit;
}
if (file_put_contents("$dir/$slug.json", json_encode($body['config'])) === false) {
  http_response_code(500);
  echo json_encode(['error' => 'cannot write config']);
  exit;
}
// Record the owner on the first publish of this slug (later writes already matched the check above).
if (!is_file($ownerFile) && $owner !== '') {
  @file_put_contents($ownerFile, password_hash($owner, PASSWORD_DEFAULT));
}

echo json_encode(['ok' => true]);
