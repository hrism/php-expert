# Testing, Static Analysis, and Tooling

Use the project's own tools and scripts first (`composer.json` → `scripts`). Add the tools below only when the project has none, and add one at a time.

## Tests (PHPUnit 10+ / Pest)

- Unit-test domain classes with no database, filesystem, clock, or network. That is the payoff of the layering in [architecture](architecture.md).
- Integration-test repositories against a real database: SQLite in memory for portable SQL, the production engine (Docker) when you use engine-specific features. Roll back or truncate between tests.
- Test behaviour, not private methods. Do not mock value objects or the class under test.
- Inject time (`ClockInterface`) and randomness instead of calling `new DateTimeImmutable()` or `random_int()` in the domain.
- A bug fix starts with a failing test that reproduces it.

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function addsAmountsOfTheSameCurrency(): void
    {
        $sum = (new Money(100, 'JPY'))->add(new Money(200, 'JPY'));

        self::assertSame(300, $sum->amount);
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function rejectsInvalidAmounts(int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money($amount, 'JPY');
    }

    /** @return iterable<string, array{int}> */
    public static function invalidAmounts(): iterable
    {
        yield 'negative' => [-1];
        yield 'very negative' => [-1000];
    }
}
```

```xml
<!-- phpunit.xml.dist: fail loudly instead of passing with warnings (check the attribute names against your PHPUnit version) -->
<phpunit bootstrap="vendor/autoload.php" colors="true"
         failOnWarning="true" failOnRisky="true" failOnDeprecation="true"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="unit"><directory>tests/Unit</directory></testsuite>
    </testsuites>
</phpunit>
```

## Static Analysis

PHPStan or Psalm finds type errors that tests miss. Start at the highest level the code tolerates and freeze existing violations in a baseline so new code is held to the standard:

```neon
# phpstan.neon
includes:
    - phpstan-baseline.neon
parameters:
    level: max
    paths: [src, tests]
```

- Generate the baseline once: `vendor/bin/phpstan analyse --generate-baseline`. Shrink it over time; never grow it to silence new code.
- Describe array and iterable shapes in docblocks (`@param list<int>`, `@return array{id: int, name: string}`, `@template T`) so the analyser can check them.
- Do not suppress with `@phpstan-ignore` without a reason in the same comment.

## Code Style and Automated Upgrades

- **Style:** PER Coding Style (the successor of PSR-12) enforced by PHP-CS-Fixer or PHP_CodeSniffer in CI; `--dry-run --diff` locally. Do not hand-format.
- **Rector** upgrades syntax and removes dead code. Run it in `--dry-run` first, apply one rule set at a time, review the diff, and let the test suite and PHPStan confirm. Never combine a Rector run with feature work in one commit.

## Composer

- Commit `composer.lock` for applications; do not commit it for libraries.
- Pin the resolver to your minimum PHP version so it never picks dependencies that need a newer one: `"config": {"platform": {"php": "8.2.0"}}` for a project whose minimum is 8.2. The version of PHPUnit must also support that minimum (PHPUnit 11 needs PHP 8.2+).
- Production install: `composer install --no-dev --optimize-autoloader --classmap-authoritative`.
- Run `composer audit` and `composer validate --strict` in CI.
- Review `allow-plugins`: Composer plugins run code at install time.
- Prefer `^` constraints for libraries you trust to follow semver; avoid `*` and `dev-main` in `require`.

```json
{
    "require": { "php": ">=8.2" },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.0"
    },
    "autoload": { "psr-4": { "App\\": "src/" } },
    "autoload-dev": { "psr-4": { "Tests\\": "tests/" } },
    "config": {
        "sort-packages": true,
        "platform": { "php": "8.2.0" }
    },
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse --memory-limit=1G",
        "cs": "php-cs-fixer fix --dry-run --diff",
        "check": ["@cs", "@analyse", "@test"]
    }
}
```

## CI

Run the same `composer check` locally and in CI, on every PHP version you support, and let an audit failure fail the build:

```yaml
name: ci
on: [push, pull_request]
jobs:
  check:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2', '8.3', '8.4']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - run: composer audit
      - run: composer check
```

## Debugging

- `php -l file.php` for syntax; `php -r 'var_dump(PHP_VERSION);'` for quick checks.
- Xdebug step debugging (`xdebug.mode=debug`) beats `var_dump` in anything with more than two call levels.
- Reproduce with the smallest script, then turn it into a test before fixing.
- Read the PHP error log first; it usually names the file and line.
