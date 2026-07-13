<?php

declare(strict_types=1);

namespace mythicmobs\bossbar;

use mythicmobs\MythicMobs;
use pocketmine\entity\Living;
use pocketmine\network\mcpe\protocol\types\BossBarColor;
use pocketmine\network\mcpe\protocol\types\BossBarOverlay;

final class BossBarManager
{
    /** @var array<int,array{entity:Living,bar:BossBar,range:float,template:string,mobName:string,level:int}> */
    private array $bars = [];
    public function __construct(private MythicMobs $plugin)
    {
    }

    /** @param array<string,mixed> $config */
    public function attach(Living $entity, array $config, string $mobName, int $level): void
    {
        if (!(bool) ($config["Enabled"] ?? false)) {
            return;
        }
        $template = (string) ($config["Title"] ?? ($entity->getNameTag() !== "" ? $entity->getNameTag() : $mobName));
        $title = $this->renderTitle($template, $entity, $mobName, $level);
        $color = $this->color((string) ($config["Color"] ?? "PURPLE"));
        $overlay = $this->style((string) ($config["Style"] ?? "SOLID"));
        $bar = BossBar::forEntity(
            $entity,
            $title,
            $entity->getHealth() / max(1, $entity->getMaxHealth()),
            $color,
            $overlay,
            (bool) ($config["CreateFog"] ?? false),
            (bool) ($config["DarkenSky"] ?? false),
            (bool) ($config["PlayMusic"] ?? false),
            (string) ($config["Fog"] ?? "minecraft:fog_hell"),
            (string) ($config["Music"] ?? "music.game"),
        );
        $this->bars[$entity->getId()] = [
            "entity" => $entity,
            "bar" => $bar,
            "range" => max(1.0, (float) ($config["Range"] ?? 40)),
            "template" => $template,
            "mobName" => $mobName,
            "level" => $level,
        ];
    }
    public function updateLevel(Living $entity, int $level): void
    {
        $id = $entity->getId();
        if (!isset($this->bars[$id])) {
            return;
        }
        $this->bars[$id]["level"] = $level;
        $data = $this->bars[$id];
        $data["bar"]->setTitle($this->renderTitle($data["template"], $entity, $data["mobName"], $level));
    }
    public function tick(): void
    {
        foreach ($this->bars as $id => $data) {
            $entity = $data["entity"];
            if ($entity->isClosed() || !$entity->isAlive()) {
                $this->remove($id);
                continue;
            }
            $data["bar"]->setPercentage($entity->getHealth() / max(1, $entity->getMaxHealth()));
            $viewers = [];
            $rangeSquared = $data["range"] ** 2;
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                if ($player->isAlive() && $player->getWorld() === $entity->getWorld() && $player->getPosition()->distanceSquared($entity->getPosition()) <= $rangeSquared) {
                    $viewers[] = $player;
                }
            }
            $data["bar"]->setViewers($viewers);
        }
    }
    public function remove(int $entityId): void
    {
        if (isset($this->bars[$entityId])) {
            $this->bars[$entityId]["bar"]->removeAll();
            unset($this->bars[$entityId]);
        }
    }
    public function removeAll(): void
    {
        foreach (array_keys($this->bars) as $id) {
            $this->remove($id);
        }
    }
    private function renderTitle(string $template, Living $entity, string $mobName, int $level): string
    {
        return MythicMobs::color(str_replace(
            ["<mob.name>", "<caster.name>", "<caster.level>", "<mob.level>"],
            [$mobName, $entity->getNameTag() ?: $mobName, (string) $level, (string) $level],
            $template,
        ));
    }
    private function color(string $name): int
    {
        return match(strtoupper($name)) {
            "PINK" => BossBarColor::PINK,
            "BLUE" => BossBarColor::BLUE,
            "RED" => BossBarColor::RED,
            "GREEN" => BossBarColor::GREEN,
            "YELLOW" => BossBarColor::YELLOW,
            "WHITE" => BossBarColor::WHITE,
            default => BossBarColor::PURPLE,
        };
    }
    private function style(string $name): int
    {
        return match(strtoupper($name)) {
            "SEGMENTED_6" => BossBarOverlay::NOTCHED_6,
            "SEGMENTED_10" => BossBarOverlay::NOTCHED_10,
            "SEGMENTED_12" => BossBarOverlay::NOTCHED_12,
            "SEGMENTED_20" => BossBarOverlay::NOTCHED_20,
            default => BossBarOverlay::PROGRESS,
        };
    }
}
