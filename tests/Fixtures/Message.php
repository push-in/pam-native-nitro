<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests\Fixtures;

use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;

final class Message extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Field(indexed: true)]
    public string $chatId;

    #[Field]
    public string $body;

    #[Field]
    public MessageType $type;

    #[Field(indexed: true)]
    public int $createdAt;

    #[Field]
    public bool $pending;

    public static function table(): string
    {
        return 'messages';
    }
}
