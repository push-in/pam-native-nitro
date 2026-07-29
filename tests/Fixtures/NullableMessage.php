<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests\Fixtures;

use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;

final class NullableMessage extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Field]
    public ?MessageType $deliveryState = null;

    public static function table(): string
    {
        return 'nullable_messages';
    }
}
