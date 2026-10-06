<?php

declare(strict_types=1);

namespace App;

use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;

final class Note extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $id;

    #[Field(indexed: true)]
    public string $folder = 'inbox';

    #[Field]
    public string $body = '';

    #[Field]
    public NoteColor $color = NoteColor::Plain;

    #[Field]
    public bool $pinned = false;

    #[Field(indexed: true)]
    public int $createdAt = 0;

    public static function table(): string
    {
        return 'notes';
    }
}
