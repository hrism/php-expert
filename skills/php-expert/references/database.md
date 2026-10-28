# Database Access (PDO)

## Connection

```php
<?php
declare(strict_types=1);

function connect(string $dsn, string $user, string $password): PDO
{
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements, native int/float types
    ]);
}

// MySQL DSN: always set the charset
// connect('mysql:host=db;dbname=app;charset=utf8mb4', $user, $password);
```

- `ERRMODE_EXCEPTION` is the default since PHP 8.0, but set it explicitly so the intent is visible. In older or legacy code a silent or warning mode hides failures; check for it first.
- Build the connection once in the composition root and inject it. Never create a `PDO` inside a repository method.

## Prepared Statements

- Values go through placeholders, always. String-building SQL with request data is the bug this section exists to prevent.
- Identifiers (table, column, `ORDER BY` direction) cannot be bound. Map user input to a fixed allowlist:

```php
<?php
declare(strict_types=1);

function orderByClause(string $requested): string
{
    $columns = ['name' => 'name', 'price' => 'price_cents', 'newest' => 'created_at'];

    return $columns[$requested] ?? 'created_at'; // unknown input falls back to a safe default
}
```

- Bind `LIMIT` / `OFFSET` as integers: `$stmt->bindValue('limit', $limit, PDO::PARAM_INT)`. With emulated prepares, `execute([$n])` sends a string and MySQL rejects `LIMIT '21'`.
- Do not reuse a named placeholder twice in one statement when `ATTR_EMULATE_PREPARES` is `false`; use two names.
- `IN (...)` lists need one placeholder per value (see `findByIds()` in [architecture](architecture.md)).
- `LIKE` with user text: escape `%` and `_` in the value, then bind it.

## Transactions and Savepoints

Wrap use cases in one helper so nesting, rollback, and savepoint names are handled in one place. Savepoint names come from a counter, never from user-controlled keys.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

final class Transactions
{
    private int $depth = 0;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $outer = $this->depth === 0;
        $savepoint = 'sp_' . $this->depth;

        if ($outer) {
            $this->db->beginTransaction();
        } else {
            $this->db->exec("SAVEPOINT {$savepoint}");
        }

        $this->depth++;
        try {
            $result = $work();
            if ($outer) {
                $this->db->commit();
            } else {
                $this->db->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            return $result;
        } catch (Throwable $e) {
            if ($outer) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } else {
                $this->db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }
}

final class ItemRejected extends RuntimeException
{
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE items (sku TEXT PRIMARY KEY)');
$tx = new Transactions($db);

/** Bulk import: a rejected item rolls back only itself, the rest is committed. */
$import = function (array $skus) use ($db, $tx): array {
    return $tx->run(function () use ($skus, $db, $tx): array {
        $imported = 0;
        $failed = [];

        foreach ($skus as $sku) {
            try {
                $tx->run(function () use ($sku, $db): void {
                    $db->prepare('INSERT INTO items (sku) VALUES (?)')->execute([$sku]);
                    if (str_starts_with($sku, 'BAD')) {
                        throw new ItemRejected("Rejected {$sku}");
                    }
                });
                $imported++;
            } catch (ItemRejected $e) {
                $failed[] = $e->getMessage();
            }
        }

        return [$imported, $failed];
    });
};

[$imported, $failed] = $import(['A1', 'BAD1', 'A2']);
assert($imported === 2 && $failed === ['Rejected BAD1']);
assert((int) $db->query('SELECT COUNT(*) FROM items')->fetchColumn() === 2);
assert(!$db->inTransaction());
```

- Keep transactions short. No HTTP calls, emails, or file uploads inside one.
- MySQL: DDL (`CREATE`, `ALTER`) commits implicitly; PDO then reports "no active transaction" on rollback. Run migrations outside use-case transactions.
- Concurrent read-modify-write needs one of: a guarded `UPDATE ... WHERE` (preferred, see [architecture](architecture.md)), `SELECT ... FOR UPDATE` inside a transaction, or an optimistic `version` column checked in the `WHERE` clause.
- Retry deadlocks (MySQL error 1213, PostgreSQL SQLSTATE `40P01`) a bounded number of times around the whole transaction.

## N+1 Queries

```php
<?php
declare(strict_types=1);

// ❌ BAD: one query per order
foreach ($orders as &$order) {
    $order['customer'] = $customerRepo->find($order['customer_id']);
}
```

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

$db = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO customers VALUES (1, 'Ada'), (2, 'Grace')");

$orders = [['id' => 10, 'customer_id' => 1], ['id' => 11, 'customer_id' => 2], ['id' => 12, 'customer_id' => 1]];

// ✅ GOOD: one batched query, indexed in memory
$ids = array_values(array_unique(array_column($orders, 'customer_id')));
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT id, name FROM customers WHERE id IN ({$placeholders})");
$stmt->execute($ids);
$customers = array_column($stmt->fetchAll(), null, 'id');

foreach ($orders as &$order) {
    $order['customer'] = $customers[$order['customer_id']] ?? null;
}
unset($order); // break the reference left by foreach-by-reference

assert($orders[2]['customer']['name'] === 'Ada');
```

Always `unset()` the loop variable after `foreach (... as &$x)`; a dangling reference silently overwrites the last element in a later loop. Prefer building a new array instead of mutating by reference.

## Cursor (Keyset) Pagination

`OFFSET` gets slower the deeper the page and skips or repeats rows when data changes. A keyset cursor stays fast and stable. Requirements: a deterministic order with a unique tie-breaker (`created_at, id`) and a composite index that matches it.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

function encodeCursor(string $createdAt, int $id): string
{
    $json = json_encode(['created_at' => $createdAt, 'id' => $id], JSON_THROW_ON_ERROR);

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/** @return array{string, int} */
function decodeCursor(string $cursor): array
{
    $json = base64_decode(strtr($cursor, '-_', '+/'), true);
    $data = $json === false ? null : json_decode($json, true);

    // json_decode() and base64_decode() are fallible: never trust the shape
    if (!is_array($data) || !is_string($data['created_at'] ?? null) || !is_int($data['id'] ?? null)) {
        throw new InvalidArgumentException('Invalid cursor'); // map to HTTP 400
    }

    return [$data['created_at'], $data['id']];
}

/** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
function paginateCursor(PDO $db, ?string $cursor, int $limit = 20): array
{
    $limit = max(1, min($limit, 100));
    $sql = 'SELECT id, name, created_at FROM products';
    if ($cursor !== null) {
        $sql .= ' WHERE (created_at, id) < (:created_at, :id)';
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit';

    $stmt = $db->prepare($sql);
    if ($cursor !== null) {
        [$createdAt, $id] = decodeCursor($cursor);
        $stmt->bindValue('created_at', $createdAt);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
    }
    $stmt->bindValue('limit', $limit + 1, PDO::PARAM_INT); // one extra row tells us whether a next page exists
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nextCursor = null;
    if (count($rows) > $limit) {
        array_pop($rows);
        $last = $rows[array_key_last($rows)];
        $nextCursor = encodeCursor($last['created_at'], (int) $last['id']);
    }

    return ['data' => $rows, 'next_cursor' => $nextCursor];
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT)');
$db->exec('CREATE INDEX products_created_id ON products (created_at DESC, id DESC)');
for ($i = 1; $i <= 5; $i++) {
    $db->exec("INSERT INTO products (id, name, created_at) VALUES ({$i}, 'p{$i}', '2026-01-0{$i}')");
}

$page1 = paginateCursor($db, null, 2);
$page2 = paginateCursor($db, $page1['next_cursor'], 2);
$page3 = paginateCursor($db, $page2['next_cursor'], 2);

assert(array_column($page1['data'], 'id') === [5, 4]);
assert(array_column($page2['data'], 'id') === [3, 2]);
assert(array_column($page3['data'], 'id') === [1] && $page3['next_cursor'] === null);

try {
    paginateCursor($db, 'not-a-cursor!!');
    assert(false, 'a malformed cursor must be rejected');
} catch (InvalidArgumentException) {
    echo "bad cursor rejected\n";
}
```

The row-value comparison `(a, b) < (?, ?)` works in MySQL 8, PostgreSQL, and SQLite 3.15+. On engines without it, expand it to `a < ? OR (a = ? AND b < ?)`.

## Reading Large Result Sets

A generator over `$stmt->fetch()` only saves memory if the driver streams rows. PDO MySQL buffers the whole result client-side by default, and `pdo_pgsql` also loads the complete result set. Two portable options:

1. **Keyset batches** (works everywhere, bounded memory, no long-held cursor):

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

/** @return Generator<int, array<string, mixed>> */
function iterateProducts(PDO $db, int $batchSize = 1000): Generator
{
    $lastId = 0;
    $stmt = $db->prepare('SELECT id, name FROM products WHERE id > :last ORDER BY id LIMIT :limit');

    do {
        $stmt->bindValue('last', $lastId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            yield $row;
            $lastId = (int) $row['id'];
        }
    } while (count($rows) === $batchSize);
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
for ($i = 1; $i <= 7; $i++) {
    $db->exec("INSERT INTO products (id, name) VALUES ({$i}, 'p{$i}')");
}

assert(count(iterator_to_array(iterateProducts($db, 3), false)) === 7);
```

2. **Unbuffered query on a dedicated connection** (MySQL): create that connection with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false`. No other query can run on the same connection until the cursor is exhausted or `closeCursor()` is called, so do not mix it with the connection used for writes.

Pass `false` as the second argument of `iterator_to_array()` for generators; the default `true` keeps keys and silently overwrites rows when a generator yields duplicate keys (for example through `yield from`).

## Query Hygiene

- List columns instead of `SELECT *`; it breaks when columns are added and fetches data you do not use.
- Add indexes for the `WHERE` and `ORDER BY` columns of hot queries, in the same order. Verify with `EXPLAIN` (`EXPLAIN ANALYZE` on PostgreSQL / MySQL 8.0.18+) and the slow query log, not by guessing.
- Avoid `COUNT(*)` on big tables for pagination UIs; return `has_next` instead.
- Schema changes go through a migration tool (Phinx, Doctrine Migrations, or the framework's), reviewed like code.
