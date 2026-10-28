# php-expert

Modern PHP development skill for Claude Code. It guides Claude to write, review, and secure production PHP, and to verify the result with the project's own tests and static analysis.

## Features

- PHP 8.0 – 8.5 language features with the version that introduced each (enums, readonly classes, property hooks, asymmetric visibility, pipe operator, ...)
- Architecture patterns: layering, PSR-11 container, repository and service layer with race-free stock handling
- Database access with PDO: prepared statements, transactions and savepoints, N+1 prevention, keyset pagination, large result sets
- Error handling that logs detail and exposes none; validation
- Security review checklist with tested helpers (SSRF guard, path safety, uploads, passwords, sessions, CSRF)
- Performance: profiling tools, OPcache/JIT, generators, SPL, long-running workers
- Testing and tooling: PHPUnit/Pest, PHPStan/Psalm, PHP-CS-Fixer, Rector, Composer, CI
- WordPress plugins and themes with namespaces and Composer autoloading

## Structure

```text
skills/php-expert/
├── SKILL.md              workflow, principles, security gate, reference map
└── references/           loaded on demand
    ├── language-features.md
    ├── architecture.md
    ├── database.md
    ├── error-handling.md
    ├── security.md
    ├── performance.md
    ├── testing-tooling.md
    └── wordpress.md
```

## Installation

### npx skills

```bash
npx skills add hrism/php-expert
```

### prpm

```bash
prpm install php-expert
```

### Manual

Copy the whole `skills/php-expert/` directory (`SKILL.md` and `references/`) to `~/.claude/skills/php-expert/`.

## Usage

The skill activates automatically when working on PHP projects. Claude reads `SKILL.md` first and opens the files in `references/` that match the task.

## Development

Every PHP example in the skill is linted, and examples marked `<!-- verify:run -->` are executed with assertions enabled. The script also checks that `prpm.json` lists every shipped file and that `SKILL.md` links every reference.

```bash
php scripts/verify-snippets.php   # requires PHP 8.5+ because some examples use 8.4/8.5 syntax
```

## License

MIT, see [LICENSE](LICENSE).
