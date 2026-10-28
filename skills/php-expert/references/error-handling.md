# Error Handling and Validation

## Exception Hierarchy and Validation

Do not name your base class `DomainException`; that is a built-in SPL class and declaring it in the global namespace is a fatal error. Use a project-specific name inside a namespace.

Only exceptions that extend the application base class are safe to show to clients. Everything else (`PDOException`, `TypeError`, library errors) can contain SQL, file paths, or credentials and is reported as a generic 500.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

namespace App\Http;

use RuntimeException;
use Throwable;

abstract class AppException extends RuntimeException
{
    /** @param array<string, mixed> $context details that are safe to expose to the client */
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class NotFoundException extends AppException
{
    public function __construct(string $message = 'Resource not found', ?Throwable $previous = null)
    {
        parent::__construct($message, 404, [], $previous);
    }
}

final class ValidationException extends AppException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed', 422, ['errors' => $errors]);
    }
}

/**
 * Pure mapping from exception to HTTP response, so it can be unit-tested.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function toResponse(Throwable $e): array
{
    if ($e instanceof AppException) {
        return ['status' => $e->statusCode, 'body' => ['success' => false, 'error' => $e->getMessage(), ...$e->context]];
    }

    return ['status' => 500, 'body' => ['success' => false, 'error' => 'Internal Server Error']];
}

/**
 * Minimal validator. For more than a handful of rules, use Symfony Validator,
 * Respect\Validation, or the framework's own.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    public function required(string $field, mixed $value): self
    {
        // Not empty(): empty(0), empty('0') and empty(false) are true, yet they are legitimate values
        $blank = $value === null || $value === [] || (is_string($value) && trim($value) === '');
        if ($blank) {
            $this->errors[$field][] = "{$field} is required";
        }

        return $this;
    }

    public function email(string $field, mixed $value): self
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field][] = "{$field} must be a valid email";
        }

        return $this;
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
    }
}

// Internal detail never reaches the client
$leaky = new \PDOException('SQLSTATE[42S02]: table secret_internal_table not found (dsn=mysql:host=10.0.0.5)');
$response = toResponse($leaky);
assert($response['status'] === 500);
assert(!str_contains(json_encode($response['body'], JSON_THROW_ON_ERROR), 'secret_internal_table'));

assert(toResponse(new NotFoundException())['status'] === 404);

// 0, '0' and false are present values; blank strings, null and [] are not
(new Validator())->required('qty', 0)->required('flag', false)->email('email', 'ada@example.com')->validate();

try {
    (new Validator())->required('name', '  ')->required('tags', [])->required('x', null)->email('email', ['a'])->validate();
    assert(false, 'validation must fail');
} catch (ValidationException $e) {
    assert(array_keys($e->errors) === ['name', 'tags', 'x', 'email']);
    assert(toResponse($e)['status'] === 422);
}
```

## Global Handlers

Log the full detail server-side, return the generic body to the client, and convert PHP warnings into exceptions so they cannot be ignored.

```php
<?php
declare(strict_types=1);

use function App\Http\toResponse;

set_exception_handler(static function (Throwable $e): void {
    ['status' => $status, 'body' => $body] = toResponse($e);

    if ($status >= 500) {
        error_log(sprintf(
            "[%s] %s: %s in %s:%d\n%s",
            date('c'),
            $e::class,
            preg_replace('/[\r\n]+/', ' ', $e->getMessage()), // one log line per event
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($body) ?: '{"success":false}';
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // respect the configured error_reporting level
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
```

- Production `php.ini`: `display_errors=Off`, `log_errors=On`, `error_reporting=E_ALL`. Development shows errors; production logs them.
- Converting every deprecation into an exception can break on third-party code. If that happens, convert warnings and notices and only log deprecations.
- For new HTTP APIs, prefer RFC 9457 `application/problem+json` as the error body.

## Rules

- Catch an exception only where you can handle it or add context. Rethrow with the cause attached: `throw new ImportFailed('Row 12', previous: $e)`.
- Catching `Throwable` is for the outermost boundary (the global handler, a queue worker loop). Never use an empty `catch`.
- Validate at the boundary with a precise exception (`ValidationException` → 422), and let domain invariants throw `InvalidArgumentException` / domain exceptions from constructors.
- Use `never` as the return type of helpers that always throw or exit.
- `finally` for cleanup (file handles, locks, temp files); do not `return` from `finally`.

