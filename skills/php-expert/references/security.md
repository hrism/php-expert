# Security

Review PHP that touches URLs, files, external data, output, sessions, or credentials against this list. Report findings with file and line, and say which items you could not check.

## Review Checklist

**Input and output**
- SQL: prepared statements for every value; identifiers from an allowlist (see [database](database.md))
- XSS: escape on output for the context (HTML body, attribute, JS, URL, CSS). Prefer a template engine with auto-escaping
- Header safety: strip CRLF from header values, redirects, filenames, and download names before sending them
- Log safety: normalize newlines and control characters before writing untrusted values to logs
- Open redirects: redirect only to relative paths or an allowlist of hosts

**URLs, files, and processes**
- URL allowlists: accept only needed schemes (`http` / `https`); reject `javascript:`, `ftp:`, `phar:`, `file:`, userinfo, and malformed hosts
- SSRF guards: reject IP literals in user-provided external URLs, resolve hosts when fetching server-side, and block private, reserved, and link-local ranges; fetch the validated IP, and re-validate every redirect hop
- Path safety: resolve paths with `realpath()` and compare against the base directory plus a trailing separator; never trust joined strings alone
- Uploads: check the upload error code, size limit, and the real MIME type (`finfo`, not `$_FILES['type']`); generate the stored file name yourself; store outside the web root
- Public file exposure: keep cache, logs, uploads-in-progress, secrets, `.env`, and `vendor/` outside the public web root or deny direct access
- Command execution: avoid `exec`, `shell_exec`, backticks; use `proc_open()` with an argument array so no shell parses the input
- Atomic writes: write JSON/config/cache files to a temp file in the same directory, then `rename()`; check every `fopen`, `mkdir`, `chmod`, `filemtime`, `json_encode`, and `rename` failure

**Authentication, sessions, secrets**
- Passwords: `password_hash()` / `password_verify()` / `password_needs_rehash()` with one shared options constant
- Sessions: `session_regenerate_id(true)` at login, cookie flags `Secure`, `HttpOnly`, `SameSite`, `session.use_strict_mode=1`
- CSRF: a per-session random token compared with `hash_equals()` for every state-changing request, plus `SameSite` cookies
- Secrets: from environment or a secret manager, never in the repository; rotate any that were committed
- Tokens and IDs: `random_bytes()` / `random_int()`, never `rand()`, `mt_rand()`, or `uniqid()`
- Secret comparisons: `hash_equals()`, never `==` or `===`

**Data handling**
- External data schemas: validate scraper/API payload types, required keys, array shape, and empty-result semantics before use
- Null guards: treat `parse_url`, `json_decode`, `preg_match`, `base64_decode`, array offsets, and optional WordPress APIs as fallible
- Deserialization: never `unserialize()` untrusted data; use JSON, or pass `['allowed_classes' => false]` when you must
- XML: do not enable `LIBXML_NOENT` or load external entities from untrusted XML

**Operations**
- Error output: `display_errors=Off` in production; clients get a generic message (see [error-handling](error-handling.md))
- Dependency policy: commit lockfiles for applications and make CI fail on relevant vulnerability levels with `composer audit`; do not hide audit failures with `continue-on-error`
- Operational docs: README/deploy steps must be executable in the target hosting model; do not document impossible restarts or unavailable shell access

### Context-Dependent Review Points

These are not always defects, but each should be an explicit decision:

- Hardcoded content, nav, FAQ, reviews, and footer links: keep in code only when update frequency and ownership justify it; otherwise use config/CMS/data files
- Scrapers: document brittle selectors/XPath/regex assumptions and add detection for stale or empty cache updates
- Dependency pinning: decide whether `require-dev` and build tools need exact pins or range constraints
- CI workflow: decide which audit/severity levels block PRs and keep the rule consistent with README

## Dangerous APIs and Their Replacements

| Avoid | Use instead |
|---|---|
| `unserialize($input)` | `json_decode($input, true, 512, JSON_THROW_ON_ERROR)` |
| `rand()`, `mt_rand()`, `uniqid()` for tokens | `random_bytes()`, `random_int()` |
| `==`, `strcmp()` on secrets | `hash_equals()` |
| `md5()` / `sha1()` for passwords | `password_hash()` |
| `exec("tool $arg")`, backticks | `proc_open([...$argv], ...)` (no shell), else `escapeshellarg()` |
| `include $_GET['page']` | an allowlist map of page name → file |
| `extract($_POST)`, `$$name` | explicit assignment |
| `eval()`, `create_function()`, `preg_replace` with `/e` | do not use |
| `$_REQUEST` | the specific superglobal you expect |
| `header("Location: $url")` | validate against allowed hosts/paths first |
| `@` suppression | handle the error |

Loose comparison still surprises: `"1e3" == "1000"` is `true`, so hashes and tokens written as numeric strings can collide. Use `===`.

## Safe External URLs (SSRF)

Validate before every server-side fetch. Rejecting IP literals in user-supplied URLs removes the easiest bypasses (`http://127.0.0.1`, `http://[::1]`, decimal/octal forms); resolving the host and checking every address covers names that point at internal ranges, including the cloud metadata address `169.254.169.254`.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

final class ExternalUrlGuard
{
    /** @throws InvalidArgumentException when the URL must not be fetched */
    public static function assertSafe(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Malformed URL');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Scheme not allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials in URL are not allowed');
        }

        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('IP literals are not allowed');
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        if ($records === []) {
            throw new InvalidArgumentException('Host does not resolve');
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw new InvalidArgumentException('Host resolves to a private or reserved address');
            }
        }

        return $url;
    }
}

$rejected = [
    'javascript:alert(1)',
    'ftp://example.com/file',
    'phar:///tmp/x.phar',
    'https://user:secret@example.com/',
    'http://127.0.0.1/admin',
    'http://[::1]/',
    'http://169.254.169.254/latest/meta-data/',
    'http:///nohost',
    'not a url',
];
foreach ($rejected as $url) {
    try {
        ExternalUrlGuard::assertSafe($url);
        assert(false, "must reject {$url}");
    } catch (InvalidArgumentException) {
        // expected
    }
}
```

DNS can change between the check and the request (rebinding). When the stakes are high, connect to the address you validated (`CURLOPT_RESOLVE`) and set `CURLOPT_FOLLOWLOCATION` to `false`, validating each redirect target yourself.

## Header, Log, Path, and File Helpers

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

function headerValue(string $value): string
{
    return str_replace(["\r", "\n", "\0"], '', $value);
}

function logValue(string $value): string
{
    return preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
}

function downloadFilename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\]+/', '', $name) ?? '';

    return $name === '' ? 'download' : $name;
}

/** Returns the real path of $relative, guaranteed to be inside $baseDir. */
function resolveInside(string $baseDir, string $relative): string
{
    $base = realpath($baseDir);
    if ($base === false) {
        throw new RuntimeException('Base directory does not exist');
    }

    $path = realpath($base . DIRECTORY_SEPARATOR . $relative);
    // The trailing separator matters: "/srv/base-evil" also starts with "/srv/base"
    if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Path escapes the base directory');
    }

    return $path;
}

/** @param array<string, mixed> $data */
function writeJsonAtomically(string $path, array $data): void
{
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create {$dir}");
    }

    $tmp = tempnam($dir, '.tmp');
    if ($tmp === false) {
        throw new RuntimeException("Cannot create a temp file in {$dir}");
    }

    try {
        if (file_put_contents($tmp, $json . "\n") === false || !chmod($tmp, 0644) || !rename($tmp, $path)) {
            throw new RuntimeException("Cannot write {$path}");
        }
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
}

// Read-modify-write cycles also need flock() on a dedicated lock file around the read and the write.

assert(headerValue("a\r\nSet-Cookie: x=1") === 'aSet-Cookie: x=1');
assert(logValue("user\nINFO forged entry") === 'user INFO forged entry');
assert(downloadFilename('../../etc/pass"wd') === 'passwd');
assert(downloadFilename('') === 'download');

$base = sys_get_temp_dir() . '/php-expert-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0755, true);
mkdir($base . '/data-evil', 0755, true);
touch($base . '/data/ok.txt');
touch($base . '/data-evil/secret.txt');

assert(str_ends_with(resolveInside($base . '/data', 'ok.txt'), 'data/ok.txt'));
foreach (['../data-evil/secret.txt', '../../etc/hosts', 'missing.txt'] as $attempt) {
    try {
        resolveInside($base . '/data', $attempt);
        assert(false, "must reject {$attempt}");
    } catch (RuntimeException) {
        // expected
    }
}

writeJsonAtomically($base . '/out/config.json', ['name' => '日本語']);
assert(json_decode((string) file_get_contents($base . '/out/config.json'), true) === ['name' => '日本語']);
assert(array_values(array_diff(scandir($base . '/out'), ['.', '..'])) === ['config.json']); // no temp file left behind
```

`realpath()` returns `false` for files that do not exist yet. When creating a new file, resolve the directory with `resolveInside()` and append a name you generated.

## Output Escaping

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

assert(e("<a href='x'>") === '&lt;a href=&#039;x&#039;&gt;');

// JSON inside an inline <script>: encode with the HEX flags so "</script>" and quotes cannot break out
$json = json_encode(['q' => '</script><script>alert(1)</script>'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
assert(!str_contains($json, '<'));
```

`strip_tags()` and `addslashes()` are not escaping functions. For URLs placed in `href`, validate the scheme first (reject `javascript:`), then escape.

## Password Handling

One shared options constant means `password_needs_rehash()` compares against the same parameters the hash was created with. Passing different options (or none) at the check makes every hash look outdated.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

final class PasswordHasher
{
    // #[\SensitiveParameter] needs 8.2 to take effect; PHP 8.1 parses and ignores it
    private const OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    public function hash(#[\SensitiveParameter] string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    public function verify(#[\SensitiveParameter] string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }
}

$hasher = new PasswordHasher();
$hash = $hasher->hash('correct horse');

assert($hasher->verify('correct horse', $hash));
assert(!$hasher->verify('wrong', $hash));
assert(!$hasher->needsRehash($hash));
assert($hasher->needsRehash(password_hash('legacy', PASSWORD_BCRYPT))); // upgrade old hashes at next login
```

```php
<?php
declare(strict_types=1);

// Login: verify, then upgrade the stored hash if the parameters changed
if (!$hasher->verify($input, $user->passwordHash)) {
    throw new AuthenticationFailed(); // same message for unknown user and wrong password
}
if ($hasher->needsRehash($user->passwordHash)) {
    $users->updatePasswordHash($user->id, $hasher->hash($input));
}
```

- `PASSWORD_ARGON2ID` requires PHP built with Argon2 support; check `defined('PASSWORD_ARGON2ID')`, or use `PASSWORD_DEFAULT` and store hashes in a column of at least 255 characters so the algorithm can change.
- Rate-limit login attempts per account and per IP, and compare against a dummy hash for unknown users to keep timing similar.

## Sessions, CSRF, Headers

```php
<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// After a successful login (prevents session fixation)
session_regenerate_id(true);

function csrfToken(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function assertCsrf(?string $submitted): void
{
    if ($submitted === null || !hash_equals(csrfToken(), $submitted)) {
        throw new RuntimeException('CSRF token mismatch'); // map to HTTP 403
    }
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Strict-Transport-Security: max-age=31536000'); // only once the whole site is HTTPS
```

CORS: answer with an allowlist of exact origins, never `*` together with credentials. Tune the CSP to the real asset origins and test it in report-only mode first.

## File Uploads

```php
<?php
declare(strict_types=1);

/** @param array{name: string, tmp_name: string, size: int, error: int} $file one entry of $_FILES */
function storeImageUpload(array $file, string $targetDir): string
{
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload failed');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('File too large');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new RuntimeException('Unsupported file type');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime]; // never reuse the client's name
    if (!move_uploaded_file($file['tmp_name'], $targetDir . '/' . $name)) {
        throw new RuntimeException('Cannot store the file');
    }

    return $name;
}
```

Also set `upload_max_filesize` and `post_max_size` in `php.ini`, serve uploads from a separate domain or with `Content-Disposition: attachment` when they are not images you display, and re-encode images if they will be processed further.

## Production `php.ini` Baseline

```ini
expose_php = Off
display_errors = Off
log_errors = On
error_reporting = E_ALL
zend.exception_ignore_args = On   ; keeps argument values (passwords, tokens) out of stack traces
allow_url_include = Off
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
```
