# Language Features (PHP 8.0 – 8.5)

Use a feature only if the project's minimum PHP version (`require.php` in `composer.json`) includes it. Examples target PHP 8.1 unless the heading carries a version tag.

## Version Map

| Version | Features worth using |
|---|---|
| 8.0 | constructor promotion, union types, `match`, named arguments, nullsafe `?->`, attributes, `str_contains()` / `str_starts_with()` / `str_ends_with()`, `throw` as an expression, `mixed`, `static` return type, `WeakMap` |
| 8.1 | enums, `readonly` properties, first-class callable syntax (`strlen(...)`), `new` in initializers, pure intersection types, `never`, `array_is_list()`, Fibers, `final` class constants |
| 8.2 | `readonly` classes, DNF types, standalone `null` / `false` / `true` types, `#[\SensitiveParameter]`, `Random\Randomizer`. **Dynamic properties are deprecated** |
| 8.3 | typed class constants, `#[\Override]`, `json_validate()`, dynamic class constant fetch `Foo::{$name}`, `mb_str_pad()` |
| 8.4 | property hooks, asymmetric visibility, `new Foo()->bar()` without extra parentheses, `#[\Deprecated]`, `array_find()` / `array_find_key()` / `array_any()` / `array_all()`, lazy objects, `mb_trim()`. **Implicitly nullable parameter types and the default `$escape` argument of `fgetcsv()` / `str_getcsv()` are deprecated** |
| 8.5 | pipe operator `\|>`, `array_first()` / `array_last()`, `clone($object, [...])` with property overrides, `#[\NoDiscard]`, URI extension (`Uri\Rfc3986\Uri`) |

Run the test suite with `error_reporting(E_ALL)` and treat deprecations as failures so upgrades do not surprise you. Check which branches are still supported at https://www.php.net/supported-versions.php before choosing a minimum version.

## Types, Enums, Readonly (8.1)

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

enum OrderStatus: string
{
    case Pending = 'pending';
    case Shipped = 'shipped';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Shipped,
            self::Shipped => $next === self::Delivered,
            self::Delivered => false,
        };
    }
}

final class Product
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $priceCents = 0,
    ) {
    }

    public function withPrice(int $priceCents): self
    {
        return new self($this->id, $this->name, $priceCents);
    }
}

// Backed enums: from() throws ValueError, tryFrom() returns null
$status = OrderStatus::tryFrom('shipped') ?? throw new DomainException('Unknown status');
assert($status->canTransitionTo(OrderStatus::Delivered));
assert(OrderStatus::tryFrom('lost') === null);

// match throws UnhandledMatchError instead of silently falling through
try {
    echo match ('archived') {
        'active', 'published' => 'visible',
        'draft' => 'hidden',
    };
} catch (UnhandledMatchError) {
    echo "unhandled\n";
}

// Named arguments, nullsafe, first-class callable syntax
$product = new Product(id: 'p1', name: 'Pen', priceCents: 120);
$upper = strtoupper(...);
assert($upper($product->name) === 'PEN');
assert($product->withPrice(150)->priceCents === 150);
```

Prefer enums and readonly value objects over string constants and associative arrays for domain data.

## Readonly Classes, DNF Types, Sensitive Parameters (8.2)

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must not be negative');
        }
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currency mismatch');
        }

        return new self($this->amount + $other->amount, $this->currency);
    }
}

// DNF type: (A&B)|C
function countItems((Countable&Iterator)|array $items): int
{
    return count($items);
}

// Keeps the value out of stack traces and logs
function login(string $user, #[\SensitiveParameter] string $password): never
{
    throw new RuntimeException('Login failed');
}

assert((new Money(100, 'JPY'))->add(new Money(200, 'JPY'))->amount === 300);
assert(countItems(new ArrayIterator([1, 2, 3])) === 3);

try {
    login('alice', 'hunter2');
} catch (RuntimeException $e) {
    assert(!str_contains($e->getTraceAsString(), 'hunter2'));
}
```

Dynamic properties (`$object->undeclared = 1`) are deprecated since 8.2. Declare the property, or use `#[\AllowDynamicProperties]` only for legacy code you cannot change.

## Override, Typed Constants, json_validate (8.3)

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

interface Handler
{
    public function handle(string $payload): string;
}

final class JsonHandler implements Handler
{
    public const string FORMAT = 'json';

    #[\Override]
    public function handle(string $payload): string
    {
        if (!json_validate($payload)) {
            throw new InvalidArgumentException('Invalid JSON');
        }

        return $payload;
    }
}

assert((new JsonHandler())->handle('{"ok":true}') === '{"ok":true}');
assert(JsonHandler::{'FORMAT'} === 'json');
```

`#[\Override]` turns a silently broken override (renamed parent method, typo) into a compile-time error. Add it to every method that implements or overrides.

## Property Hooks, Asymmetric Visibility, Array Helpers (8.4)

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

final class User
{
    public string $email {
        set(string $value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Invalid email');
            }
            $this->email = strtolower($value);
        }
    }

    // Anyone can read, only this class can write
    public private(set) int $logins = 0;

    public function __construct(
        private readonly string $first,
        private readonly string $last,
        string $email,
    ) {
        $this->email = $email;
    }

    // Virtual property: computed on read, no backing storage
    public string $fullName {
        get => $this->first . ' ' . $this->last;
    }

    public function recordLogin(): void
    {
        $this->logins++;
    }
}

$user = new User('Ada', 'Lovelace', 'ADA@Example.com');
$user->recordLogin();

assert($user->email === 'ada@example.com');
assert($user->fullName === 'Ada Lovelace');
assert($user->logins === 1);

try {
    $user->logins = 99; // Error: Cannot modify private(set) property
} catch (Error) {
    echo "write rejected\n";
}

assert(array_find([1, 2, 3, 4], fn (int $n): bool => $n > 2) === 3);
assert(array_any([1, 2], fn (int $n): bool => $n > 1));
assert(!array_all([1, 2], fn (int $n): bool => $n > 1));
echo new DateTimeImmutable('2026-01-01')->format('Y'), "\n"; // no wrapping parentheses needed
```

Declare nullable parameters explicitly (`?Foo $foo = null`); implicit nullable types are deprecated in 8.4.

## Pipe, Clone With, NoDiscard (8.5)

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

// Pipe: left value becomes the single argument of the right callable
$result = '  Hello  ' |> trim(...) |> strtoupper(...);
assert($result === 'HELLO');

assert(array_first([3, 4, 5]) === 3);
assert(array_last([3, 4, 5]) === 5);
assert(array_first([]) === null);

final class Point
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
    ) {
    }

    // "Wither" without re-listing every constructor argument
    public function withX(int $x): static
    {
        return clone($this, ['x' => $x]);
    }
}

$moved = (new Point(1, 2))->withX(9);
assert($moved->x === 9 && $moved->y === 2);

#[\NoDiscard]
function reserveSeat(): bool
{
    return true;
}

if (!reserveSeat()) { // ignoring the result would raise a warning
    throw new RuntimeException('Sold out');
}

$uri = new Uri\Rfc3986\Uri('https://example.com:8080/a/b?x=1#frag');
assert($uri->getHost() === 'example.com' && $uri->getPort() === 8080);
```

Use `Uri\Rfc3986\Uri` instead of `parse_url()` where RFC 3986 correctness matters (URL allowlists, SSRF checks). `parse_url()` is lenient with malformed input.

## Attributes (8.0)

An attribute does nothing until something reads it. Always show the reader:

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

#[Attribute(Attribute::TARGET_METHOD)]
final class Route
{
    public function __construct(
        public readonly string $path,
        public readonly string $method = 'GET',
    ) {
    }
}

final class ApiController
{
    #[Route('/products/{id}', 'GET')]
    public function show(string $id): array
    {
        return ['id' => $id];
    }
}

/** @return array<string, array{class-string, string}> "METHOD /path" => [class, method] */
function collectRoutes(string $controller): array
{
    $routes = [];
    foreach ((new ReflectionClass($controller))->getMethods() as $method) {
        foreach ($method->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $routes["{$route->method} {$route->path}"] = [$controller, $method->getName()];
        }
    }

    return $routes;
}

assert(collectRoutes(ApiController::class) === ['GET /products/{id}' => [ApiController::class, 'show']]);
```

Reflection is slow at request time: build the route table once and cache it.

## Strings, Unicode, Dates

- `strlen()` counts bytes. Use `mb_strlen($s, 'UTF-8')`, `mb_substr()`, `mb_strtolower()`, and the `/u` modifier on regular expressions for text that may contain non-ASCII characters.
- `mb_str_pad()` (8.3) and `mb_trim()` (8.4) exist; before that, write a small helper, not a byte-based `str_pad()` call.
- Use `DateTimeImmutable` with an explicit `DateTimeZone`, store UTC, and inject a clock (`Psr\Clock\ClockInterface`) so time is testable.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

$title = '日本語 の タイトル 2026!';
$slug = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($title)) ?? '', '-');
assert($slug === '日本語-の-タイトル-2026');
assert(strlen('日本') === 6 && mb_strlen('日本', 'UTF-8') === 2);

$createdAt = new DateTimeImmutable('2026-03-01 12:00:00', new DateTimeZone('Asia/Tokyo'));
$utc = $createdAt->setTimezone(new DateTimeZone('UTC'));
assert($utc->format('H:i') === '03:00');
assert($createdAt->format('H:i') === '12:00'); // the original is unchanged
```

## Avoid

- `@` error suppression, `extract()`, `$$variable`, `global`, `eval()`, `goto`
- `==` for anything security-relevant (`"1e3" == "1000"` is `true`); use `===` and `hash_equals()`
- Associative arrays as domain data structures across layer boundaries; use readonly classes or enums
- Mutable static state (`static $cache`, singletons) in code that may run in a long-lived worker
- Silencing static analysis with blanket `@phpstan-ignore` instead of fixing the type
