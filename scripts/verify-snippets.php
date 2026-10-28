<?php

declare(strict_types=1);

/**
 * Verifies the skill package.
 *
 *  - every ```php block starts with "<?php", declares strict_types and lints cleanly
 *  - blocks preceded by <!-- verify:run --> are executed (no warnings, no failed assert())
 *  - prpm.json "files" matches what is actually under skills/php-expert/
 *  - SKILL.md frontmatter, size limit and reference links are consistent
 *
 * Usage: php scripts/verify-snippets.php
 */

$root = dirname(__DIR__);
$skillDir = $root . '/skills/php-expert';
$stubs = __DIR__ . '/stubs.php';
$failures = [];
$linted = 0;
$executed = 0;

/**
 * @return list<array{line: int, code: string, run: bool}>
 */
function extractPhpBlocks(string $markdown): array
{
    $blocks = [];
    $codeLines = null;
    $startLine = 0;
    $run = false;
    $previous = '';

    foreach (explode("\n", $markdown) as $index => $line) {
        if ($codeLines === null) {
            if (preg_match('/^```php\s*$/', $line) === 1) {
                $codeLines = [];
                $startLine = $index + 1;
                $run = $previous === '<!-- verify:run -->';
            } elseif (trim($line) !== '') {
                $previous = trim($line);
            }
            continue;
        }

        if (preg_match('/^```\s*$/', $line) === 1) {
            $blocks[] = ['line' => $startLine, 'code' => implode("\n", $codeLines) . "\n", 'run' => $run];
            $codeLines = null;
            $previous = '';
            continue;
        }

        $codeLines[] = $line;
    }

    return $blocks;
}

/**
 * @param list<string> $args
 * @return array{int, string, string} exit code, stdout, stderr
 */
function runPhp(array $args): array
{
    $process = proc_open(
        [PHP_BINARY, ...$args],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP');
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * @return list<string> paths relative to $base, sorted
 */
function listFiles(string $base): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = substr($file->getPathname(), strlen($base) + 1);
        }
    }
    sort($files);

    return $files;
}

// 1. Code blocks ---------------------------------------------------------

$tmp = tempnam(sys_get_temp_dir(), 'php-expert-');
if ($tmp === false) {
    fwrite(STDERR, "Cannot create temp file\n");
    exit(2);
}

foreach (listFiles($skillDir) as $relative) {
    if (!str_ends_with($relative, '.md')) {
        continue;
    }

    $markdown = (string) file_get_contents($skillDir . '/' . $relative);
    foreach (extractPhpBlocks($markdown) as $block) {
        $where = "skills/php-expert/{$relative}:{$block['line']}";

        if (!str_starts_with($block['code'], "<?php\n")) {
            $failures[] = "{$where} block must start with <?php";
            continue;
        }
        if (!str_contains($block['code'], 'declare(strict_types=1);')) {
            $failures[] = "{$where} block must declare strict_types=1";
        }

        file_put_contents($tmp, $block['code']);

        [$exit, $stdout, $stderr] = runPhp(['-l', $tmp]);
        $linted++;
        if ($exit !== 0) {
            $failures[] = "{$where} lint failed: " . trim($stdout . $stderr);
            continue;
        }

        if (!$block['run']) {
            continue;
        }

        [$exit, $stdout, $stderr] = runPhp([
            '-d', 'error_reporting=-1',
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            '-d', 'zend.assertions=1',
            '-d', 'assert.exception=1',
            '-d', 'auto_prepend_file=' . $stubs,
            $tmp,
        ]);
        $executed++;
        if ($exit !== 0 || trim($stderr) !== '') {
            $failures[] = "{$where} execution failed (exit {$exit}): " . trim($stderr . "\n" . $stdout);
        }
    }
}
unlink($tmp);

// 2. Packaging -------------------------------------------------------------

$manifest = json_decode((string) file_get_contents($root . '/prpm.json'), true, flags: JSON_THROW_ON_ERROR);
$shipped = array_map(static fn (string $path): string => 'skills/php-expert/' . $path, listFiles($skillDir));
$declared = $manifest['files'] ?? [];
sort($declared);
if ($declared !== $shipped) {
    $failures[] = 'prpm.json "files" differs from skills/php-expert/: missing ['
        . implode(', ', array_diff($shipped, $declared)) . '] unknown ['
        . implode(', ', array_diff($declared, $shipped)) . ']';
}

$skill = (string) file_get_contents($skillDir . '/SKILL.md');
if (preg_match('/\A---\nname: (php-expert)\ndescription: (.+)\n---\n/', $skill, $m) !== 1) {
    $failures[] = 'SKILL.md frontmatter must be "name: php-expert" followed by a one-line description';
} elseif (strlen($m[2]) > 1024) {
    $failures[] = 'SKILL.md description exceeds 1024 characters';
}

$lineCount = substr_count($skill, "\n");
if ($lineCount > 500) {
    $failures[] = "SKILL.md has {$lineCount} lines (limit 500); move detail into references/";
}

preg_match_all('#\(references/([a-z0-9-]+\.md)\)#', $skill, $links);
foreach (array_unique($links[1]) as $link) {
    if (!is_file($skillDir . '/references/' . $link)) {
        $failures[] = "SKILL.md links to missing references/{$link}";
    }
}
foreach (listFiles($skillDir . '/references') as $file) {
    if (!str_contains($skill, "(references/{$file})")) {
        $failures[] = "references/{$file} is not linked from SKILL.md";
    }
}

// Report -------------------------------------------------------------------

echo "linted {$linted} php blocks, executed {$executed}\n";
if ($failures !== []) {
    echo "\nFAILED (" . count($failures) . "):\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}
echo "OK\n";
