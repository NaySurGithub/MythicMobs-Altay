<?php

declare(strict_types=1);

namespace mythicmobs\bossbar;

use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\PlayerFogPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\BossBarOverlay;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\player\Player;

final class BossBar
{
    /** @var array<int,Player> */
    private array $viewers = [];
    private int $entityId = 0;

    private function __construct(
        private string $title,
        private float $percentage,
        private int $color,
        private int $overlay,
        private bool $entityBound,
        ?Entity $entity,
        private bool $createFog = false,
        private bool $darkenSky = false,
        private bool $playMusic = false,
        private string $fog = "minecraft:fog_hell",
        private string $music = "music.game",
    ) {
        $this->percentage = self::clamp($percentage);
        if ($entity !== null) {
            $this->entityId = $entity->getId();
        }
    }

    public static function create(string $title = "", float $percentage = 1.0, int $color = BossBarColor::PURPLE, int $overlay = BossBarOverlay::PROGRESS): self
    {
        return new self($title, $percentage, $color, $overlay, false, null);
    }
    public static function forEntity(Entity $entity, string $title = "", float $percentage = 1.0, int $color = BossBarColor::PURPLE, int $overlay = BossBarOverlay::PROGRESS, bool $fog = false, bool $sky = false, bool $music = false, string $fogId = "minecraft:fog_hell", string $musicId = "music.game"): self
    {
        return new self($title, $percentage, $color, $overlay, true, $entity, $fog, $sky, $music, $fogId, $musicId);
    }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
    private function bossId(Player $player): int
    {
        return $this->entityBound ? $this->entityId : $player->getId();
    }
    public function getTitle(): string
    {
        return $this->title;
    }
    public function getPercentage(): float
    {
        return $this->percentage;
    }
    /** @return array<int,Player> */
    public function getViewers(): array
    {
        return $this->viewers;
    }
    public function hasViewer(Player $player): bool
    {
        return isset($this->viewers[spl_object_id($player)]);
    }

    public function setTitle(string $title): self
    {
        if ($title === $this->title) {
            return $this;
        }
        $this->title = $title;
        foreach ($this->viewers as $player) {
            if ($player->isConnected()) {
                $packet = BossEventPacket::title($this->bossId($player), $title);
                $player->getNetworkSession()->sendDataPacket(
                    $this->completePacket($packet, $player)
                );
            }
        }
        return $this;
    }
    public function setPercentage(float $percentage): self
    {
        $percentage = self::clamp($percentage);
        if (abs($percentage - $this->percentage) < 0.0001) {
            return $this;
        }
        $this->percentage = $percentage;
        foreach ($this->viewers as $player) {
            if ($player->isConnected()) {
                $packet = BossEventPacket::healthPercent(
                    $this->bossId($player),
                    $percentage
                );
                $player->getNetworkSession()->sendDataPacket(
                    $this->completePacket($packet, $player)
                );
            }
        }
        return $this;
    }
    public function setAppearance(int $color, int $overlay): self
    {
        $this->color = $color;
        $this->overlay = $overlay;
        foreach ($this->viewers as $player) {
            if ($player->isConnected()) {
                $packet = BossEventPacket::properties(
                    $this->bossId($player),
                    $color,
                    $overlay
                );
                $player->getNetworkSession()->sendDataPacket(
                    $this->completePacket($packet, $player)
                );
            }
        }
        return $this;
    }

    public function addPlayer(Player $player): void
    {
        $id = spl_object_id($player);
        if (isset($this->viewers[$id]) || !$player->isConnected()) {
            return;
        }
        $session = $player->getNetworkSession();
        $packet = BossEventPacket::show(
            $this->bossId($player),
            $this->title,
            $this->percentage,
            $this->color,
            $this->overlay
        );
        $session->sendDataPacket($this->completePacket($packet, $player));
        if ($this->createFog) {
            $session->sendDataPacket(PlayerFogPacket::create([$this->fog]));
        }
        if ($this->darkenSky) {
            $session->sendDataPacket(LevelEventPacket::create(LevelEvent::START_RAIN, 65535, null));
            $session->sendDataPacket(LevelEventPacket::create(LevelEvent::START_THUNDER, 65535, null));
        }
        if ($this->playMusic) {
            $p = $player->getPosition();
            $session->sendDataPacket(PlaySoundPacket::create($this->music, $p->x, $p->y, $p->z, 1.0, 1.0, null));
        }
        $this->viewers[$id] = $player;
    }
    public function removePlayer(Player $player): void
    {
        $id = spl_object_id($player);
        if (!isset($this->viewers[$id])) {
            return;
        }
        if ($player->isConnected()) {
            $session = $player->getNetworkSession();
            $packet = BossEventPacket::hide($this->bossId($player));
            $session->sendDataPacket($this->completePacket($packet, $player));
            if ($this->createFog) {
                $session->sendDataPacket(PlayerFogPacket::create([]));
            }
            if ($this->darkenSky) {
                $session->sendDataPacket(LevelEventPacket::create(LevelEvent::STOP_RAIN, 0, null));
                $session->sendDataPacket(LevelEventPacket::create(LevelEvent::STOP_THUNDER, 0, null));
            }
            if ($this->playMusic) {
                $session->sendDataPacket(StopSoundPacket::create($this->music, false, true));
            }
        }
        unset($this->viewers[$id]);
    }

    private function completePacket(
        BossEventPacket $packet,
        Player $player
    ): BossEventPacket {
        if (!isset($packet->playerActorUniqueId)) {
            $packet->playerActorUniqueId = $player->getId();
        }
        if (!isset($packet->title)) {
            $packet->title = $this->title;
        }
        if (!isset($packet->filteredTitle)) {
            $packet->filteredTitle = $this->title;
        }
        if (!isset($packet->healthPercent)) {
            $packet->healthPercent = $this->percentage;
        }
        if (!isset($packet->color)) {
            $packet->color = $this->color;
        }
        if (!isset($packet->overlay)) {
            $packet->overlay = $this->overlay;
        }

        return $packet;
    }

    /** @param list<Player> $players */
    public function setViewers(array $players): void
    {
        $wanted = [];
        foreach ($players as $player) {
            $wanted[spl_object_id($player)] = $player;
        }
        foreach ($this->viewers as $id => $player) {
            if (!isset($wanted[$id])) {
                $this->removePlayer($player);
            }
        }
        foreach ($wanted as $player) {
            $this->addPlayer($player);
        }
    }
    public function removeAll(): void
    {
        foreach (array_values($this->viewers) as $player) {
            $this->removePlayer($player);
        }
    }
}
