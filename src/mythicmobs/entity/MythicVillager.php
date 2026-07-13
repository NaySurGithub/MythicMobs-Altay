<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\Villager;

final class MythicVillager extends Villager implements MythicEntity
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
