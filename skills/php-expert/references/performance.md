# Performance and Memory

**Profile before optimizing.** Find the slow request or job first, then the slow function, then change one thing and measure again. Most PHP latency is database queries and network calls, not PHP syntax.

## Measuring

| Question | Tool |
|---|---|
| Which function is slow / which calls dominate? | Xdebug profiler (`xdebug.mode=profile`, open the cachegrind file in QCachegrind or Webgrind), SPX, XHProf / Tideways, Blackfire |
| Is it the database? | slow query log, `EXPLAIN` / `EXPLAIN ANALYZE`, a per-request query count in your logger |
| Is it memory? | `memory_get_peak_usage(true)`, Xdebug trace, `php -d memory_limit=64M` to force the problem |
| Is OPcache effective? | `opcache_get_status()` (hit rate, memory used, free slots) |
| Production behaviour over time | APM / OpenTelemetry traces |

Xdebug slows execution several times over. Compare relative cost between functions, not absolute times.

A manual timer is still useful for a quick before/after check around one operation:

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

final class Profiler
{
    /** @var array<string, array{int, int}> label => [start time (ns), start memory] */
    private array $marks = [];

    public function start(string $label): void
    {
        $this->marks[$label] = [hrtime(true), memory_get_usage()];
    }

    /** @return array{duration_ms: float, memory_bytes: int, memory_readable: string} */
    public function end(string $label): array
    {
        if (!isset($this->marks[$label])) {
            throw new LogicException("Timer not started: {$label}");
        }

        [$startedAt, $startMemory] = $this->marks[$label];
        unset($this->marks[$label]);
        $memory = memory_get_usage() - $startMemory;

        return [
            'duration_ms' => round((hrtime(true) - $startedAt) / 1e6, 2),
            'memory_bytes' => $memory,
            'memory_readable' => self::formatBytes($memory),
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = abs($bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($bytes < 0 ? '-' : '') . round($value, 2) . ' ' . $units[$unit];
    }
}

$profiler = new Profiler();
$profiler->start('import');
usleep(1000);
$stats = $profiler->end('import');

assert($stats['duration_ms'] >= 0.5);
assert(Profiler::formatBytes(0) === '0 B');
assert(Profiler::formatBytes(1536) === '1.5 KB');
assert(Profiler::formatBytes(-2048) === '-2 KB');
assert(Profiler::formatBytes(1024 ** 2) === '1 MB');
assert(Profiler::formatBytes(1024 ** 5) === '1024 TB'); // clamps at the largest unit instead of reading past the array
```

## Generators for Large Data

Keep memory flat by yielding one item at a time. Chain generators for ETL pipelines. For database results, see [database](database.md): the driver must stream rows or you must read in keyset batches, otherwise a generator does not reduce memory.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

/** @return Generator<int, list<string|null>> */
function extractCsv(string $path): Generator
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Cannot open {$path}");
    }

    try {
        // escape: '' follows RFC 4180; relying on the default escape character is deprecated since 8.4
        fgetcsv($handle, escape: ''); // skip header
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            yield $row;
        }
    } finally {
        fclose($handle); // also runs when the consumer stops early
    }
}

/**
 * @param iterable<list<string|null>> $rows
 * @return Generator<int, array{sku: string, price: int, stock: int}>
 */
function transform(iterable $rows): Generator
{
    foreach ($rows as $row) {
        yield [
            'sku' => trim((string) ($row[0] ?? '')),
            'price' => (int) ($row[1] ?? 0),
            'stock' => max(0, (int) ($row[2] ?? 0)),
        ];
    }
}

/**
 * @template T
 * @param iterable<T> $items
 * @return Generator<int, list<T>>
 */
function chunked(iterable $items, int $size): Generator
{
    $batch = [];
    foreach ($items as $item) {
        $batch[] = $item;
        if (count($batch) === $size) {
            yield $batch;
            $batch = [];
        }
    }
    if ($batch !== []) {
        yield $batch;
    }
}

$path = tempnam(sys_get_temp_dir(), 'csv');
file_put_contents($path, "sku,price,stock\nA1,100,5\nA2,250,-3\nA3,75\n");

$rows = iterator_to_array(transform(extractCsv($path)), false);
unlink($path);

assert($rows[0] === ['sku' => 'A1', 'price' => 100, 'stock' => 5]);
assert($rows[1]['stock'] === 0);  // negative stock clamped
assert($rows[2]['stock'] === 0);  // missing column tolerated
assert(array_map('count', iterator_to_array(chunked($rows, 2), false)) === [2, 1]);
```

## SPL Data Structures

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

// SplQueue: FIFO
$queue = new SplQueue();
$queue->enqueue('task-1');
$queue->enqueue('task-2');
$processed = [];
while (!$queue->isEmpty()) {
    $processed[] = $queue->dequeue();
}
assert($processed === ['task-1', 'task-2']);

// SplHeap: priority queue (SplPriorityQueue is the simpler choice for plain priorities)
final class TaskHeap extends SplMaxHeap
{
    protected function compare(mixed $value1, mixed $value2): int
    {
        return $value1['priority'] <=> $value2['priority'];
    }
}

$heap = new TaskHeap();
$heap->insert(['name' => 'low', 'priority' => 1]);
$heap->insert(['name' => 'high', 'priority' => 9]);
assert($heap->extract()['name'] === 'high');

// WeakMap: cache keyed by object that does not keep the object alive (8.0)
$cache = new WeakMap();
$key = new stdClass();
$cache[$key] = 'computed';
unset($key);
assert(count($cache) === 0);

// SplFixedArray: lower memory for large fixed-size lists. Since PHP 7 optimized arrays,
// the speed benefit is minimal; use it only under real memory pressure.
$fixed = new SplFixedArray(10000);
assert($fixed->getSize() === 10000);
```

## OPcache and JIT (production)

```ini
; php.ini: measure with opcache_get_status() before tuning
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; deploys must reload PHP-FPM or call opcache_reset()
; opcache.jit = tracing and opcache.jit_buffer_size = 64M help CPU-bound code;
; they rarely speed up I/O-bound web requests. Benchmark before enabling.
```

- Deploy with `composer install --no-dev --optimize-autoloader --classmap-authoritative`.
- `opcache.preload` needs a restart per deploy and brings little once the autoloader is a classmap; try it last.

## Caching

- Per-process or per-host data: APCu. Shared data: Redis or Memcached via PSR-6/PSR-16.
- Cache the result of expensive pure computations by input hash, with an explicit TTL and a way to invalidate.
- HTTP: send `Cache-Control` / `ETag` for cacheable responses and handle `If-None-Match`.

## Long-Running Workers (FrankenPHP worker mode, RoadRunner, Swoole, queue workers)

State survives between requests. Do not keep request data in static properties or singletons, reset per-request services, close or ping database connections, and watch `memory_get_usage()` with a restart threshold.

## Cheap Wins Worth Checking

- A lookup list searched with `in_array()` inside a loop: flip it once into keys and use `isset()`.
- A query inside a loop: batch it (see [database](database.md)).
- `$all = array_merge($all, $part)` in a loop copies the growing array each time: collect the parts and call `array_merge(...$parts)` once, or append with `$all[] = $item`.
- Reading a whole file with `file()` / `file_get_contents()` for line processing: use `SplFileObject` or a generator.
