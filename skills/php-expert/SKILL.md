---
name: php-expert
description: Modern PHP development combining language mastery, architecture patterns, and performance optimization. For building scalable, maintainable PHP applications with clean architecture.
metadata:
  model: inherit
---

# PHP Expert

Modern PHP development skill covering language features, architecture patterns, database optimization, and performance tuning.

## When to Use

- Building or refactoring PHP applications
- Designing API backends without frameworks
- Optimizing PHP performance and memory usage
- Implementing clean architecture patterns

## Core Principles

1. Built-in functions before custom implementations
2. Generators for large datasets
3. Strict typing everywhere (`declare(strict_types=1);` at file top)
4. SPL data structures when beneficial
5. Profile before optimizing
6. PSR compliance (PSR-1, PSR-4, PSR-12)

## Language Features (PHP 8+)

### Type System

```php
<?php
declare(strict_types=1);

// Union types
function process(string|int $id): array|false { }

// Intersection types
function handle(Countable&Iterator $collection): void { }

// Constructor property promotion
class Product {
    public function __construct(
        private readonly string $id,
        private string $name,
        private int $price = 0
    ) {}
}

// Enums with methods
enum OrderStatus: string {
    case Pending = 'pending';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    
    public function label(): string {
        return match($this) {
            self::Pending => '処理中',
            self::Shipped => '発送済',
            self::Delivered => '配達完了',
        };
    }
}
```

### Match Expressions

```php
// Replace switch with match
$result = match($status) {
    'active', 'published' => $this->handleActive($item),
    'draft' => $this->handleDraft($item),
    'archived' => throw new InvalidStateException(),
    default => null,
};
```

### Attributes

```php
#[Attribute(Attribute::TARGET_METHOD)]
class Route {
    public function __construct(
        public string $path,
        public string $method = 'GET'
    ) {}
}

class ApiController {
    #[Route('/products/{id}', 'GET')]
    public function show(string $id): array { }
}
```

## Memory-Efficient Data Processing

### Generators

```php
// ❌ BAD: Loads everything into memory
function getAllProducts(PDO $db): array {
    return $db->query('SELECT * FROM products')->fetchAll();
}

// ✅ GOOD: Yields one at a time
function iterateProducts(PDO $db): Generator {
    $stmt = $db->query('SELECT * FROM products');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        yield $row;
    }
}

// Chained generators for ETL
function extractCsv(string $path): Generator {
    $handle = fopen($path, 'r');
    fgetcsv($handle); // skip header
    while (($row = fgetcsv($handle)) !== false) {
        yield $row;
    }
    fclose($handle);
}

function transform(Generator $rows): Generator {
    foreach ($rows as $row) {
        yield [
            'sku' => trim($row[0]),
            'price' => (int) $row[1],
            'stock' => max(0, (int) $row[2]),
        ];
    }
}
```

### SPL Data Structures

```php
// SplQueue for FIFO processing
$queue = new SplQueue();
$queue->enqueue($task1);
$queue->enqueue($task2);
while (!$queue->isEmpty()) {
    $task = $queue->dequeue();
    process($task);
}

// SplHeap for priority queue
class TaskHeap extends SplMaxHeap {
    protected function compare($a, $b): int {
        return $a['priority'] <=> $b['priority'];
    }
}

// SplFixedArray: memory-efficient for large fixed-size arrays
// Note: PHP 8+ optimized regular arrays, so speed benefit is minimal.
// Use primarily for memory constraints, not speed optimization.
$arr = new SplFixedArray(10000);
```

## Architecture Patterns

### Dependency Injection Container

```php
namespace App\Core;

class Container {
    private array $definitions = [];
    private array $instances = [];

    public function set(string $id, callable $factory): void {
        $this->definitions[$id] = $factory;
    }

    public function get(string $id): mixed {
        if (!isset($this->instances[$id])) {
            if (!isset($this->definitions[$id])) {
                throw new \RuntimeException("Service not found: {$id}");
            }
            $this->instances[$id] = $this->definitions[$id]($this);
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool {
        return isset($this->definitions[$id]);
    }
}

// Registration
$container->set(PDO::class, fn() => new PDO(
    getenv('DB_DSN'),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
));

$container->set(ProductRepository::class, fn($c) => 
    new ProductRepository($c->get(PDO::class))
);
```

### Repository Pattern

```php
interface RepositoryInterface {
    public function find(string $id): ?array;
    public function findBy(array $criteria): array;
    public function save(array $data): string;
    public function delete(string $id): bool;
}

class ProductRepository implements RepositoryInterface {
    public function __construct(private PDO $db) {}

    public function find(string $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByIds(array $ids): array {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM products WHERE id IN ({$placeholders})"
        );
        $stmt->execute(array_values($ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data): string {
        if (isset($data['id'])) {
            return $this->update($data);
        }
        return $this->insert($data);
    }

    // Note: $data keys must come from trusted source (DTO/Entity), not raw user input
    private function insert(array $data): string {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $this->db->prepare(
            "INSERT INTO products ({$columns}) VALUES ({$placeholders})"
        );
        $stmt->execute(array_values($data));
        return $this->db->lastInsertId();
    }
}
```

### Service Layer

```php
class ProductService {
    public function __construct(
        private ProductRepository $repo,
        private InventoryService $inventory,
        private EventDispatcher $events
    ) {}

    public function purchase(string $productId, int $quantity): Order {
        $product = $this->repo->find($productId);
        
        if (!$product) {
            throw new NotFoundException('Product not found');
        }

        if (!$this->inventory->hasStock($productId, $quantity)) {
            throw new InsufficientStockException();
        }

        $order = $this->createOrder($product, $quantity);
        $this->inventory->decrement($productId, $quantity);
        $this->events->dispatch(new OrderCreated($order));

        return $order;
    }
}
```

## Database Optimization

### Transaction with Savepoints

```php
public function bulkImport(array $items): ImportResult {
    $this->db->beginTransaction();
    $success = 0;
    $failed = [];

    try {
        foreach ($items as $i => $item) {
            $savepoint = "item_{$i}";
            $this->db->exec("SAVEPOINT {$savepoint}");
            
            try {
                $this->import($item);
                $success++;
            } catch (ValidationException $e) {
                $this->db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $failed[] = ['index' => $i, 'error' => $e->getMessage()];
            }
        }
        $this->db->commit();
    } catch (Exception $e) {
        $this->db->rollBack();
        throw $e;
    }

    return new ImportResult($success, $failed);
}
```

### N+1 Prevention

```php
// ❌ BAD: N+1 queries
$orders = $orderRepo->findAll();
foreach ($orders as &$order) {
    $order['customer'] = $customerRepo->find($order['customer_id']);
}

// ✅ GOOD: Batch fetch with index
$orders = $orderRepo->findAll();
$customerIds = array_unique(array_column($orders, 'customer_id'));
$customers = $customerRepo->findByIds($customerIds);
$customerIndex = array_column($customers, null, 'id');

foreach ($orders as &$order) {
    $order['customer'] = $customerIndex[$order['customer_id']] ?? null;
}
```

### Cursor-based Pagination

```php
function paginateCursor(PDO $db, ?string $cursor, int $limit = 20): array {
    $sql = 'SELECT * FROM products WHERE 1=1';
    $params = [];

    if ($cursor) {
        $decoded = json_decode(base64_decode($cursor), true);
        $sql .= ' AND (created_at, id) < (?, ?)';
        $params[] = $decoded['created_at'];
        $params[] = $decoded['id'];
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
    $params[] = $limit + 1;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    $nextCursor = null;
    if ($hasMore && !empty($rows)) {
        $last = end($rows);
        $nextCursor = base64_encode(json_encode([
            'created_at' => $last['created_at'],
            'id' => $last['id']
        ]));
    }

    return ['data' => $rows, 'next_cursor' => $nextCursor];
}
```

## Error Handling

### Exception Hierarchy

```php
abstract class DomainException extends Exception {
    public function __construct(
        string $message,
        public readonly int $statusCode = 400,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}

class NotFoundException extends DomainException {
    public function __construct(string $message = 'Resource not found') {
        parent::__construct($message, 404);
    }
}

class ValidationException extends DomainException {
    public function __construct(public readonly array $errors) {
        parent::__construct('Validation failed', 422, ['errors' => $errors]);
    }
}
```

### Global Error Handler

```php
set_exception_handler(function (Throwable $e): void {
    $status = $e instanceof DomainException ? $e->statusCode : 500;
    $context = $e instanceof DomainException ? $e->context : [];
    
    if ($status >= 500) {
        error_log(sprintf(
            "[%s] %s in %s:%d\n%s",
            date('Y-m-d H:i:s'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        ...$context
    ]);
});
```

## Security

### Input Validation

```php
class Validator {
    private array $errors = [];

    public function required(string $field, mixed $value): self {
        if (empty($value) && $value !== '0') {
            $this->errors[$field][] = "{$field} is required";
        }
        return $this;
    }

    public function email(string $field, mixed $value): self {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "{$field} must be valid email";
        }
        return $this;
    }

    public function validate(): void {
        if (!empty($this->errors)) {
            throw new ValidationException($this->errors);
        }
    }
}
```

### Password Handling

```php
// Hash with cost factor
$hash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3
]);

// Verify and rehash if needed
if (!password_verify($input, $storedHash)) {
    throw new AuthenticationException();
}

if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {
    $newHash = password_hash($input, PASSWORD_ARGON2ID);
    $this->updateHash($userId, $newHash);
}
```

## Performance Profiling

### Simple Profiler

```php
class Profiler {
    private static array $timers = [];
    private static array $memory = [];

    public static function start(string $label): void {
        self::$timers[$label] = hrtime(true);
        self::$memory[$label] = memory_get_usage();
    }

    public static function end(string $label): array {
        $duration = (hrtime(true) - self::$timers[$label]) / 1e6;
        $memory = memory_get_usage() - self::$memory[$label];
        
        return [
            'duration_ms' => round($duration, 2),
            'memory_bytes' => $memory,
            'memory_readable' => self::formatBytes($memory)
        ];
    }

    private static function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log(abs($bytes) + 1, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}

// Usage
Profiler::start('import');
$result = $service->bulkImport($data);
$stats = Profiler::end('import');
```

## Output

- Type-safe code with full type coverage
- Memory-efficient processing using generators
- Clean architecture with separated concerns
- Optimized database queries
- Comprehensive error handling
- Secure implementations
- PSR-compliant code
- Profiled and measured improvements
