<?php

declare(strict_types=1);

namespace Pam\Nitro\Internal;

/**
 * Packs pre-encoded transaction statements into native `transaction` payloads
 * that each stay under the bridge budget and the native statement/row limits.
 *
 * Statement order and argument-set order are preserved; one statement whose
 * argument sets do not fit is continued in the next payload.
 *
 * @internal
 */
final class ChunkBuilder
{
    /** `{"sql":` + `,"arguments":[],"argumentSets":[` + `]}` + separating comma. */
    public const int STATEMENT_FRAMING = 42;

    /** @var list<string> */
    private array $chunks = [];

    /** @var list<array{sql: string, sets: list<string>, many: bool}> */
    private array $statements = [];

    private int $bytes;

    private bool $open = false;

    public function __construct(private readonly int $base)
    {
        $this->bytes = $base + 2;
    }

    /**
     * @param list<string> $sets JSON-encoded argument lists
     * @param bool $many keep the `argumentSets` form even for a single set
     * @throws OversizedPayload when one argument set alone exceeds the bridge limit
     */
    public function push(string $sql, array $sets, bool $many = false): void
    {
        $head = self::STATEMENT_FRAMING + strlen(Bridge::string($sql));
        $this->open = false;
        foreach ($sets as $set) {
            $cost = strlen($set) + 1;
            $last = array_key_last($this->statements);
            if ($this->open
                && $last !== null
                && count($this->statements[$last]['sets']) < Bridge::MAX_ARGUMENT_SETS
                && $this->bytes + $cost <= Bridge::BUDGET) {
                $this->statements[$last]['sets'][] = $set;
                $this->bytes += $cost;

                continue;
            }
            if ($this->statements !== []
                && (count($this->statements) >= Bridge::MAX_STATEMENTS
                    || $this->bytes + $head + $cost > Bridge::BUDGET)) {
                $this->flush();
            }
            if ($this->base + 2 + $head + $cost > Bridge::LIMIT) {
                throw new OversizedPayload(
                    'Nitro row exceeds the PAM Native bridge limit of 1 MiB ('
                    .strlen($set).' bytes of arguments).',
                );
            }
            $this->statements[] = ['sql' => $sql, 'sets' => [$set], 'many' => $many];
            $this->bytes += $head + $cost;
            $this->open = true;
        }
    }

    /** @return list<string> encoded `transaction` arguments, one per native call */
    public function chunks(): array
    {
        if ($this->statements !== []) {
            $this->flush();
        }

        return $this->chunks;
    }

    private function flush(): void
    {
        $rendered = [];
        foreach ($this->statements as $statement) {
            $sql = Bridge::string($statement['sql']);
            $rendered[] = count($statement['sets']) === 1 && !$statement['many']
                ? '{"sql":'.$sql.',"arguments":'.$statement['sets'][0].'}'
                : '{"sql":'.$sql.',"arguments":[],"argumentSets":['
                    .implode(',', $statement['sets']).']}';
        }
        $this->chunks[] = '['.implode(',', $rendered).']';
        $this->statements = [];
        $this->bytes = $this->base + 2;
        $this->open = false;
    }
}
