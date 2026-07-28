<?php

declare(strict_types=1);

namespace Pam\Nitro\Tests\Fixtures;

use Pam\Nitro\Attributes\Children;
use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;
use Pam\Nitro\Relations\ChildrenRelation;

final class Post extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Field]
    public string $body;

    #[Children(Message::class, foreignKey: 'chat_id')]
    public ChildrenRelation $messages;

    public static function table(): string
    {
        return 'posts';
    }
}
