---
name: php-expert
description: Use when writing, reviewing, refactoring, debugging, or securing PHP code - .php files, composer.json, PDO/SQL access, PSR-4 autoloading, PHPUnit/Pest tests, PHPStan/Psalm, WordPress plugins and themes, or plain-PHP APIs. Covers PHP 8.1-8.5 language features, architecture, database access, error handling, security review, performance profiling, testing, and tooling.
---

# PHP Expert

Practical guidance for production PHP: write modern, typed, tested code, and review it against a concrete security and correctness checklist. Detailed material lives in `references/`; load only the files the task needs.

## When to Use

- Building, refactoring, or reviewing PHP applications, libraries, CLIs, or APIs
- Database access with PDO: queries, transactions, pagination, performance
- Security review of PHP that handles URLs, files, uploads, sessions, passwords, or external data
- Setting up or fixing tests, static analysis, Composer, or CI for PHP
- WordPress plugins and themes written with modern PHP

In a Laravel or Symfony project, the framework's conventions decide structure, container, and ORM. Use this skill for the language, security, database correctness, and testing rules.

## Workflow

1. **Detect the project first.** Read `composer.json` (`require.php`, `config.platform.php`, framework packages, dev tools), `phpunit.xml*`, `phpstan.neon*`, any style config, and a few existing files. Existing conventions and the framework's idioms win over this skill.
2. **Respect the minimum PHP version.** Use only syntax available at the project's minimum; the version tags are in [language-features](references/language-features.md). If the version cannot be determined, assume PHP 8.1 and say so.
3. **Write the code** following the principles below. Examples in this skill target PHP 8.1 unless tagged.
4. **Verify before reporting done.** Run `php -l` on changed files and the project's own commands (`composer test`, `composer analyse`, `vendor/bin/phpunit`, `vendor/bin/phpstan`). Report failures as they are. If nothing could be run, say so explicitly instead of implying the code works.
5. **For reviews,** apply the Security Gate below and the matching reference files. Report concrete findings with file and line, not general advice.

## Core Principles

1. Built-in functions before custom implementations
2. Generators for large datasets (and make sure the data source really streams)
3. Strict typing everywhere (`declare(strict_types=1);` at file top)
4. SPL data structures when beneficial
5. Profile before optimizing
6. PSR compliance (PSR-1, PSR-4, PER Coding Style as the successor of PSR-12)
7. Use namespaces and Composer PSR-4 autoloading to avoid global symbol collisions and manual `require_once` management
8. Make illegal states unrepresentable: enums, readonly value objects, and constructor validation instead of arrays and magic strings
9. Dependencies point inward; constructors receive collaborators; the domain never touches PDO, HTTP, or WordPress
10. Untrusted input is validated at the boundary, output is escaped for its context, and internal error details never reach clients

## Reference Map

| Task | Read |
|---|---|
| Language features by PHP version, enums, readonly, hooks, attributes, Unicode, dates | [language-features](references/language-features.md) |
| Layering, container, repository, service layer, transactions around use cases, PSR choices | [architecture](references/architecture.md) |
| PDO setup, prepared statements, transactions, N+1, pagination, large result sets | [database](references/database.md) |
| Exception hierarchy, global handlers, validation, safe error responses | [error-handling](references/error-handling.md) |
| Full security checklist, SSRF/path/upload/password/session/CSRF helpers, `php.ini` baseline | [security](references/security.md) |
| Profiling tools, OPcache/JIT, generators, SPL, caching, long-running workers | [performance](references/performance.md) |
| PHPUnit/Pest, PHPStan/Psalm, style, Rector, Composer, CI, debugging | [testing-tooling](references/testing-tooling.md) |
| WordPress plugins and themes | [wordpress](references/wordpress.md) |

## Security Gate

Before calling PHP code finished or reviewed, check these explicitly. The details and helper code are in [security](references/security.md).

- SQL values use placeholders; identifiers come from an allowlist
- Output is escaped for its context; uploads check the real MIME type and use generated names
- External URLs: scheme allowlist, no userinfo, no IP literals, resolved addresses are public, redirects re-validated
- Headers, redirects, filenames, and log lines have CR/LF and control characters removed
- File paths are resolved with `realpath()` and compared with the base directory plus a trailing separator
- Passwords use `password_hash()`; tokens use `random_bytes()`; secret comparisons use `hash_equals()`; secrets are not in the repository
- No `unserialize()`, `eval()`, or `include` on untrusted input; no shell strings built from input
- Fallible calls (`parse_url`, `json_decode`, `preg_match`, `fopen`, `rename`, array offsets) are checked
- Production hides errors from clients (`display_errors=Off`) and returns generic 500 bodies
- `composer.lock` is committed for applications and `composer audit` fails the build

## Output

- Type-safe code with full type coverage
- Memory-efficient processing where data volume warrants it
- Clean architecture with separated concerns
- Queries that are parameterized, indexed, and free of N+1 patterns
- Error handling that logs detail and exposes none
- Secure implementations, checked against the Security Gate
- PSR-compliant code that passes the project's own tests and static analysis
- Measured performance improvements, not guesses
