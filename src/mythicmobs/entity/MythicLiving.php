<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;

abstract class MythicLiving extends Living implements MythicEntity
{
    use MythicEntityTrait;

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(1.8, 0.6);
    }
    public function getName(): string
    {
        return $this->getMythicName() !== "" ? $this->getMythicName() : "Mythic Mob";
    }
}
