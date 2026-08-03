<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

use Closure;
use InvalidArgumentException;
use Pam\Nitro\Model;
use Pam\Nitro\Nitro;
use Pam\Nitro\Schema\ModelSchema;

final class DeltaApplier
{
    private const MAX_CHANGES = 10_000;
    private const DELETE_CHUNK = 500;

    private function __construct()
    {
    }

    /** @param list<class-string<Model>> $models */
    public static function prepare(array $models, Closure $callback): int
    {
        if ($models === []) {
            throw new InvalidArgumentException('Delta preparation requires at least one model.');
        }

        return Nitro::prepare([...$models, SyncCursor::class], $callback);
    }

    /**
     * Atomically applies one server delta and advances its opaque cursor.
     *
     * @param class-string<Model> $model
     * @param list<Model> $upserts
     * @param list<string|int> $deletedIds
     */
    public static function apply(
        string $scope,
        string $model,
        array $upserts,
        array $deletedIds,
        string $cursor,
        ?int $now = null,
        ?Closure $callback = null,
    ): int {
        self::assertToken('scope', $scope, 256);
        self::assertToken('cursor', $cursor, 4_096);
        if (count($upserts) + count($deletedIds) > self::MAX_CHANGES) {
            throw new InvalidArgumentException('A delta accepts at most 10000 changes.');
        }

        $schema = ModelSchema::for($model);
        $statements = [];
        foreach (array_chunk($deletedIds, self::DELETE_CHUNK) as $ids) {
            $arguments = [];
            foreach ($ids as $id) {
                $value = trim((string) $id);
                if ($value === '' || strlen($value) > 512) {
                    throw new InvalidArgumentException(
                        'Deleted IDs must contain between 1 and 512 bytes.',
                    );
                }
                $arguments[] = $id;
            }
            $statements[] = [
                'sql' => 'DELETE FROM "'.$schema->table.'" WHERE "'.$schema->primary->name
                    .'" IN ('.implode(', ', array_fill(0, count($arguments), '?')).')',
                'arguments' => $arguments,
            ];
        }

        if ($upserts !== []) {
            $argumentSets = [];
            foreach ($upserts as $item) {
                if ($item::class !== $model) {
                    throw new InvalidArgumentException(
                        'Delta upserts must match the declared model class.',
                    );
                }
                $argumentSets[] = array_values($item->attributes());
            }
            $statements[] = [
                'sql' => self::upsertSql($schema),
                'argumentSets' => $argumentSets,
            ];
        }

        $statements[] = [
            'sql' => 'INSERT INTO "nitro_sync_cursors" ("scope", "cursor", "updated_at") '
                .'VALUES (?, ?, ?) ON CONFLICT("scope") DO UPDATE SET '
                .'"cursor" = excluded."cursor", "updated_at" = excluded."updated_at"',
            'arguments' => [$scope, $cursor, $now ?? time()],
        ];

        return Nitro::connection()->transaction($statements, $callback);
    }

    /** @param Closure(?string): void $callback */
    public static function cursor(string $scope, Closure $callback): int
    {
        self::assertToken('scope', $scope, 256);

        return Nitro::connection()->query(
            'SELECT "cursor" FROM "nitro_sync_cursors" WHERE "scope" = ? LIMIT 1',
            [$scope],
            static function (array $rows) use ($callback): void {
                $value = $rows[0]['cursor'] ?? null;
                $callback(is_string($value) ? $value : null);
            },
        );
    }

    private static function upsertSql(ModelSchema $schema): string
    {
        $columns = array_column($schema->columns, 'name');
        $updates = array_values(array_filter(
            $columns,
            static fn (string $column): bool => $column !== $schema->primary->name,
        ));
        $conflict = $updates === []
            ? 'DO NOTHING'
            : 'DO UPDATE SET '.implode(', ', array_map(
                static fn (string $column): string => '"'.$column.'" = excluded."'.$column.'"',
                $updates,
            ));

        return 'INSERT INTO "'.$schema->table.'" ("'
            .implode('", "', $columns).'") VALUES ('
            .implode(', ', array_fill(0, count($columns), '?')).') '
            .'ON CONFLICT("'.$schema->primary->name.'") '.$conflict;
    }

    private static function assertToken(string $name, string $value, int $maximum): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(
                "Delta {$name} must be non-empty UTF-8 with at most {$maximum} bytes.",
            );
        }
    }
}
