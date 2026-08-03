<?php

declare(strict_types=1);

namespace Pam\Nitro\Sync;

use Pam\Nitro\Attributes\Field;
use Pam\Nitro\Attributes\PrimaryKey;
use Pam\Nitro\Model;

final class SyncCursor extends Model
{
    #[PrimaryKey]
    #[Field]
    public string $scope;

    #[Field]
    public string $cursor;

    #[Field]
    public int $updatedAt;

    public static function table(): string
    {
        return 'nitro_sync_cursors';
    }
}
