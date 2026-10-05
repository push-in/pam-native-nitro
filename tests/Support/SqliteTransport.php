<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests\Support;

use Closure;
use PDO;
use Pam\Native\Internal\Wire;
use Pam\Native\ModuleResultStatus;
use Pam\Native\Modules\NativeModuleTransport;
use Throwable;

/**
 * In-process emulation of the native `sqlite` module backed by PDO SQLite.
 *
 * Mirrors the Android module contract: query arguments bind as text, every
 * call is one round-trip, transactions roll back atomically on failure and
 * the PAM Native wire codec rejects requests and results above one MiB.
 */
final class SqliteTransport implements NativeModuleTransport
{
    public const int WIRE_LIMIT = 1_048_576;

    /** @var list<array{method: string, sql: string, bytes: int}> */
    public array $calls = [];

    /** Largest request or result payload seen, in bytes. */
    public int $largest = 0;

    /** @var (Closure(string, string, string): ?string)|null returns a failure message to inject */
    public ?Closure $fault = null;

    public PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function invoke(
        int $requestId,
        string $module,
        string $method,
        string $payload,
        Closure $complete,
    ): void {
        $this->largest = max($this->largest, strlen($payload));
        if (strlen($payload) > self::WIRE_LIMIT) {
            $this->calls[] = ['method' => $method, 'sql' => '', 'bytes' => strlen($payload)];
            $complete(ModuleResultStatus::Failure, 'Native module payload exceeds one MiB');

            return;
        }
        $values = Wire::decodeMap($payload);
        $sql = (string) ($values['sql'] ?? '');
        $encodedArguments = (string) ($values['arguments'] ?? '[]');
        $arguments = json_decode($encodedArguments, true, 512, JSON_THROW_ON_ERROR);
        $this->calls[] = ['method' => $method, 'sql' => $sql, 'bytes' => strlen($payload)];
        $fault = $this->fault === null ? null : ($this->fault)($method, $sql, $encodedArguments);
        if ($fault !== null) {
            $complete(ModuleResultStatus::Failure, $fault);

            return;
        }
        try {
            $result = match ($method) {
                'execute' => $this->run($sql, self::list($arguments)),
                'query' => $this->result($this->rows($sql, self::list($arguments))),
                'executeMany' => $this->atomic(function () use ($sql, $arguments): void {
                    if (count(self::list($arguments)) > 10_000) {
                        throw new \RuntimeException('SQLite executeMany requires between 1 and 10000 argument sets');
                    }
                    foreach (self::list($arguments) as $set) {
                        $this->run($sql, self::list($set));
                    }
                }),
                'transaction' => $this->atomic(function () use ($arguments): void {
                    if (count(self::list($arguments)) > 10_000) {
                        throw new \RuntimeException('SQLite transaction requires between 1 and 10000 statements');
                    }
                    foreach (self::list($arguments) as $statement) {
                        $statement = is_array($statement) ? $statement : [];
                        $sets = isset($statement['argumentSets'])
                            ? self::list($statement['argumentSets'])
                            : [$statement['arguments'] ?? []];
                        foreach ($sets as $set) {
                            $this->run(is_string($statement['sql'] ?? null) ? $statement['sql'] : '', self::list($set));
                        }
                    }
                }),
                default => throw new \RuntimeException("Unknown SQLite method {$method}"),
            };
        } catch (Throwable $error) {
            $complete(ModuleResultStatus::Failure, $error->getMessage());

            return;
        }
        $complete(ModuleResultStatus::Success, $result);
    }

    /**
     * Android encodes rows with org.json: raw UTF-8, escaped slashes, and a
     * 1000-row cap, inside a wire map limited to one MiB.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function result(array $rows): string
    {
        if (count($rows) > 1_000) {
            throw new \RuntimeException('SQLite query exceeded the 1000-row bridge limit; paginate the query');
        }
        $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::WIRE_LIMIT) {
            throw new \RuntimeException('Native module value is too large');
        }
        $result = Wire::map(['rows' => $json]);
        $this->largest = max($this->largest, strlen($result));

        return $result;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        /** @var list<array<string, mixed>> */
        return $statement === false ? [] : $statement->fetchAll();
    }

    /** @param list<mixed> $arguments */
    private function run(string $sql, array $arguments): string
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($arguments as $offset => $value) {
            $statement->bindValue(
                $offset + 1,
                is_bool($value) ? (int) $value : $value,
                match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value), is_bool($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $statement->execute();

        return '';
    }

    /**
     * @param list<mixed> $arguments
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $arguments): array
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($arguments as $offset => $value) {
            $statement->bindValue($offset + 1, match (true) {
                $value === null => '',
                is_bool($value) => $value ? '1' : '0',
                default => (string) (is_scalar($value) ? $value : ''),
            });
        }
        $statement->execute();

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    private function atomic(Closure $work): string
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();

            throw $error;
        }

        return '';
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
