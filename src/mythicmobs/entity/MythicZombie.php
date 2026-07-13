<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\Zombie;

final class MythicZombie extends Zombie implements MythicEntity
{
    use MythicEntityTrait;

    public static function getNetworkTypeId(): string
    {
        return parent::getNetworkTypeId();
    }
    public function getName(): string
    {
        return $this->getMythicName() !== "" ? $this->getMythicName() : parent::getName();
    }
}
