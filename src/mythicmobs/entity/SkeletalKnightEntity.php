<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\EntitySizeInfo;

final class SkeletalKnightEntity extends BuiltEntity
{
    public const IDENTIFIER = "mythicmobs:skeletal_knight";
    public static function getNetworkTypeId(): string
    {
        return self::IDENTIFIER;
    }
    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(1.99, 0.6);
    }
}
