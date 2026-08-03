<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;

final class OutboxMutation extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Field(indexed: true)]
    public int $entityKind;

    #[Field(indexed: true)]
    public string $entityId;

    #[Field]
    public MutationOperation $operation;

    #[Field]
    public string $payload;

    #[Field(indexed: true)]
    public MutationState $state;

    #[Field]
    public int $attempts = 0;

    #[Field(indexed: true)]
    public int $availableAt;

    #[Field]
    public int $createdAt;

    #[Field]
    public int $updatedAt;

    #[Field]
    public ?string $lastError = null;

    public static function table(): string
    {
        return 'nitro_outbox_mutations';
    }
}
