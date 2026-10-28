# Architecture

## Choose the Structure by Context

| Context | Approach |
|---|---|
| Laravel / Symfony / Slim / another framework already in use | Use the framework's container, routing, ORM, and conventions. Apply this skill for language rules, security, tests, and database correctness only. |
| Plain PHP service or API | Thin front controller, PSR-7/15 request pipeline, PSR-11 container, PDO or Doctrine DBAL |
| WordPress plugin or theme | See [wordpress](wordpress.md) |
| CLI or batch job | Symfony Console (or a plain `$argv` script), generators for streaming input |

Do not import the hand-rolled container below into a framework project.

## Layering Rules

- Dependencies point inward: Infrastructure → Application → Domain. Domain code never touches PDO, HTTP, `$_SERVER`, or WordPress functions.
- Constructor injection. No `new` of collaborators inside business classes (value objects and DTOs are fine). Never pass the container around (service locator).
- A transaction belongs to the use case (application service), not to a repository method.
- Cross layer boundaries with readonly value objects and enums, not `array` shapes.
- Dispatch domain events after the transaction commits, never inside it.

## Container (PSR-11)

Prefer PHP-DI or Symfony DependencyInjection in real projects. If you hand-roll one, implement `Psr\Container\ContainerInterface` (`composer require psr/container:^2`) so it is swappable, and detect cycles:

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ServiceNotFound extends RuntimeException implements NotFoundExceptionInterface
{
}

final class ServiceFailed extends RuntimeException implements ContainerExceptionInterface
{
}

final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @param Closure(self): mixed $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new ServiceNotFound("Service not found: {$id}");
        }
        if (isset($this->resolving[$id])) {
            throw new ServiceFailed("Circular dependency while resolving: {$id}");
        }

        $this->resolving[$id] = true;
        try {
            return $this->instances[$id] = ($this->factories[$id])($this);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}

$container = new Container();
$container->set('config', static fn (): array => ['dsn' => 'sqlite::memory:']);
$container->set(
    \PDO::class,
    static fn (Container $c): \PDO => new \PDO($c->get('config')['dsn'], options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]),
);
$container->set('a', static fn (Container $c): mixed => $c->get('b'));
$container->set('b', static fn (Container $c): mixed => $c->get('a'));

assert($container->get(\PDO::class) === $container->get(\PDO::class)); // shared instance

try {
    $container->get('a');
    assert(false, 'cycle must be detected');
} catch (ServiceFailed) {
    echo "cycle detected\n";
}
```

Read environment configuration once, in the composition root (where the container is built), never from inside domain classes.

## Repository and Service Layer

Rules the example follows:

- The repository returns typed objects, not arrays, and lists its columns explicitly. Column names never come from caller-supplied array keys.
- Check-then-act is a race. `if (hasStock()) { decrement(); }` lets two requests both pass the check. Make the guard part of the write (`UPDATE ... WHERE stock >= :qty`) and check the affected row count.
- The use case owns the transaction.

```php
<?php
declare(strict_types=1);

// ❌ BAD: two concurrent purchases of the last item both pass hasStock()
if ($inventory->hasStock($productId, $quantity)) {
    $inventory->decrement($productId, $quantity);
}
```

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

namespace App\Catalog;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class Product
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly int $stock,
    ) {
    }
}

interface ProductRepository
{
    public function find(int $id): ?Product;

    /**
     * @param list<int> $ids
     * @return array<int, Product> keyed by product id
     */
    public function findByIds(array $ids): array;

    public function save(Product $product): Product;

    /** Atomically takes stock; returns false when there is not enough. */
    public function decrementStock(int $id, int $quantity): bool;
}

final class PdoProductRepository implements ProductRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function find(int $id): ?Product
    {
        $stmt = $this->db->prepare('SELECT id, name, price_cents, stock FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, name, price_cents, stock FROM products WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($ids));

        $products = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $product = $this->hydrate($row);
            $products[$product->id] = $product;
        }

        return $products;
    }

    public function save(Product $product): Product
    {
        if ($product->id === null) {
            $stmt = $this->db->prepare('INSERT INTO products (name, price_cents, stock) VALUES (:name, :price, :stock)');
            $stmt->execute(['name' => $product->name, 'price' => $product->priceCents, 'stock' => $product->stock]);
            $id = $this->db->lastInsertId();

            return new Product((int) $id, $product->name, $product->priceCents, $product->stock);
        }

        $stmt = $this->db->prepare('UPDATE products SET name = :name, price_cents = :price, stock = :stock WHERE id = :id');
        $stmt->execute([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->priceCents,
            'stock' => $product->stock,
        ]);

        return $product;
    }

    public function decrementStock(int $id, int $quantity): bool
    {
        // Two different placeholder names: native prepared statements forbid reusing one
        $stmt = $this->db->prepare('UPDATE products SET stock = stock - :qty WHERE id = :id AND stock >= :min');
        $stmt->execute(['qty' => $quantity, 'id' => $id, 'min' => $quantity]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Product
    {
        return new Product((int) $row['id'], (string) $row['name'], (int) $row['price_cents'], (int) $row['stock']);
    }
}

final class ProductNotFound extends RuntimeException
{
}

final class InsufficientStock extends RuntimeException
{
}

final class PurchaseService
{
    public function __construct(
        private readonly PDO $db,
        private readonly ProductRepository $products,
    ) {
    }

    /** @return int total price in cents */
    public function purchase(int $productId, int $quantity): int
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1');
        }

        $this->db->beginTransaction();
        try {
            $product = $this->products->find($productId) ?? throw new ProductNotFound("Product {$productId} not found");

            if (!$this->products->decrementStock($productId, $quantity)) {
                throw new InsufficientStock("Not enough stock for product {$productId}");
            }

            // Insert the order row here, inside the same transaction
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Dispatch domain events here, after the commit

        return $product->priceCents * $quantity;
    }
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, price_cents INTEGER NOT NULL, stock INTEGER NOT NULL)');

$repository = new PdoProductRepository($db);
$pen = $repository->save(new Product(null, 'Pen', 120, 5));
$service = new PurchaseService($db, $repository);

assert($service->purchase($pen->id, 3) === 360);

try {
    $service->purchase($pen->id, 3);
    assert(false, 'overselling must be rejected');
} catch (InsufficientStock) {
    // expected: only 2 left
}

assert($repository->find($pen->id)?->stock === 2);
assert(array_keys($repository->findByIds([$pen->id, 999])) === [$pen->id]);
```

## DTOs and Mapping

- Build a DTO from validated input at the HTTP boundary (`CreateProductData::fromArray($validated)`), then pass the DTO inward. Never pass `$_POST` or decoded JSON into the domain.
- Keep persistence mapping (`hydrate()`) in the infrastructure layer. If mapping grows beyond a few columns, use Doctrine ORM/DBAL rather than a bespoke mapper.

## PSR Map (what to reach for)

| Need | PSR | Typical package |
|---|---|---|
| Autoloading | PSR-4 | Composer |
| Coding style | PER Coding Style (successor of PSR-12) | PHP-CS-Fixer, PHP_CodeSniffer |
| Logging | PSR-3 | Monolog |
| Container | PSR-11 | PHP-DI, Symfony DI |
| HTTP messages / handlers / middleware | PSR-7, PSR-15, PSR-17 | nyholm/psr7, a PSR-15 router |
| HTTP client | PSR-18 | Guzzle, Symfony HttpClient |
| Cache | PSR-6, PSR-16 | symfony/cache |
| Clock | PSR-20 | symfony/clock |
