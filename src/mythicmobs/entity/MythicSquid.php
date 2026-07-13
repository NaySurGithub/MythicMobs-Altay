<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\Squid;

final class MythicSquid extends Squid implements MythicEntity
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
