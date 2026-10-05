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
 * call is one round-trip and transactions roll back atomically on failure.
 */
final class SqliteTransport implements NativeModuleTransport
{
    /** @var list<array{method: string, sql: string}> */
    public array $calls = [];

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
        $values = Wire::decodeMap($payload);
        $sql = (string) ($values['sql'] ?? '');
        $arguments = json_decode((string) ($values['arguments'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $this->calls[] = ['method' => $method, 'sql' => $sql];
        try {
            $result = match ($method) {
                'execute' => $this->run($sql, self::list($arguments)),
                'query' => Wire::map(['rows' => json_encode(
                    $this->rows($sql, self::list($arguments)),
                    JSON_THROW_ON_ERROR,
                )]),
                'executeMany' => $this->atomic(function () use ($sql, $arguments): void {
                    foreach (self::list($arguments) as $set) {
                        $this->run($sql, self::list($set));
                    }
                }),
                'transaction' => $this->atomic(function () use ($arguments): void {
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
