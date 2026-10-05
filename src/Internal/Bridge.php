<?php

declare(strict_types=1);

namespace Pam\Nitro\Internal;

use Closure;
use InvalidArgumentException;
use JsonException;
use Pam\Native\ModuleResultStatus;
use Pam\Native\Modules\NativeModuleResult;
use Pam\Native\Modules\NativeModules;
use Pam\Nitro\Nitro;
use Throwable;

/**
 * Size-aware access to the native `sqlite` module.
 *
 * PAM Native rejects any module payload (request or result) larger than one
 * MiB. Every Nitro call is measured here before it crosses the bridge, and
 * every native failure is delivered to a failure callback instead of being
 * thrown from inside a module result callback.
 *
 * @internal
 */
final class Bridge
{
    /** Hard PAM Native wire limit for one module payload. */
    public const int LIMIT = 1_048_576;

    /** Packing budget for one Nitro call, leaving headroom below LIMIT. */
    public const int BUDGET = 786_432;

    /** Native SQLite limits on statements per transaction and rows per statement or result. */
    public const int MAX_STATEMENTS = 10_000;
    public const int MAX_ARGUMENT_SETS = 10_000;
    public const int MAX_QUERY_ROWS = 1_000;

    /** Wire map framing for the database, sql and arguments keys. */
    private const int FRAMING = 43;

    private function __construct()
    {
    }

    /** Exact wire size of one sqlite module request. */
    public static function size(string $database, string $sql, string $arguments): int
    {
        return self::FRAMING + strlen($database) + strlen($sql) + strlen($arguments);
    }

    /** @param array<mixed> $value */
    public static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'SQLite arguments must be JSON scalars.',
                0,
                $error,
            );
        }
    }

    public static function string(string $value): string
    {
        return substr(self::encode([$value]), 1, -1);
    }

    /**
     * Native messages produced when a payload or a result crosses the wire limit.
     */
    public static function isOversize(string $message): bool
    {
        return preg_match(
            '/too large|too big|exceeds one MiB|exceed one megabyte|bridge limit of 1 MiB/i',
            $message,
        ) === 1;
    }

    /**
     * @param Closure(string): void|null $failure
     * @return Closure(string): void
     */
    public static function failure(?Closure $failure): Closure
    {
        return $failure ?? static function (string $message): void {
            Nitro::reportFailure($message);
        };
    }

    /**
     * Sends one request. Oversized requests and native failures reach $failure.
     *
     * @param Closure(NativeModuleResult): void $success
     * @param Closure(string): void $failure
     */
    public static function send(
        string $database,
        string $method,
        string $sql,
        string $arguments,
        Closure $success,
        Closure $failure,
    ): int {
        if (self::size($database, $sql, $arguments) > self::LIMIT) {
            $failure('Nitro native payload exceeds the PAM Native bridge limit of 1 MiB.');

            return 0;
        }

        return NativeModules::call(
            'sqlite',
            $method,
            ['database' => $database, 'sql' => $sql, 'arguments' => $arguments],
            static function (NativeModuleResult $result) use ($success, $failure): void {
                if ($result->status === ModuleResultStatus::Failure) {
                    $failure($result->payload !== '' ? $result->payload : 'SQLite operation failed');

                    return;
                }
                $success($result);
            },
        );
    }

    /**
     * Sends requests one after another; a failure stops the sequence.
     *
     * @param list<array{string, string, string}> $calls method, sql, encoded arguments
     * @param Closure(): void $done
     * @param Closure(string): void $failure
     */
    public static function sequence(
        string $database,
        array $calls,
        Closure $done,
        Closure $failure,
    ): int {
        $run = static function (int $index) use (&$run, $database, $calls, $done, $failure): int {
            $call = $calls[$index] ?? null;
            if ($call === null) {
                $done();

                return 0;
            }

            return self::send(
                $database,
                $call[0],
                $call[1],
                $call[2],
                static function () use (&$run, $index): void {
                    $run($index + 1);
                },
                $failure,
            );
        };

        return $run(0);
    }

    /**
     * Decodes a `query` result into scalar rows.
     *
     * @return list<array<string, string|int|float|bool|null>>|string rows, or an error message
     */
    public static function rows(NativeModuleResult $result): array|string
    {
        try {
            $rows = json_decode(
                (string) ($result->values()['rows'] ?? '[]'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $error) {
            return 'Nitro could not decode the native query result: '.$error->getMessage();
        }
        $list = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($row as $column => $value) {
                if (is_string($column) && (is_scalar($value) || $value === null)) {
                    $values[$column] = $value;
                }
            }
            $list[] = $values;
        }

        return $list;
    }
}
