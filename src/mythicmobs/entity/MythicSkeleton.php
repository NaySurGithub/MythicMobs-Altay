<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

final class MythicSkeleton extends MythicLiving
{
    public static function getNetworkTypeId(): string
    {
        return EntityIds::SKELETON;
    }
}
