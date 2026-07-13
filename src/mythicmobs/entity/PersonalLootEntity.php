<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

final class PersonalLootEntity extends ItemEntity
{
    public function __construct(
        Location $location,
        Item $item,
        private string $viewerUuid,
        ?CompoundTag $nbt = null,
    ) {
        parent::__construct($location, $item, $nbt);
        $this->setCanSaveWithChunk(false);
    }

    public function spawnTo(Player $player): void
    {
        if ($player->getUniqueId()->toString() !== $this->viewerUuid) {
            return;
        }

        parent::spawnTo($player);
    }

    public function onCollideWithPlayer(Player $player): void
    {
        if ($player->getUniqueId()->toString() !== $this->viewerUuid) {
            return;
        }

        parent::onCollideWithPlayer($player);
    }

    public function isMergeable(ItemEntity $entity): bool
    {
        return $entity instanceof self
            && $entity->viewerUuid === $this->viewerUuid
            && parent::isMergeable($entity);
    }
}
