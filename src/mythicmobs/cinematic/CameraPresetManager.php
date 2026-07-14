<?php

declare(strict_types=1);

namespace mythicmobs\cinematic;

use pocketmine\network\mcpe\protocol\CameraPresetsPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraPreset;
use pocketmine\player\Player;

final class CameraPresetManager
{
    public const FIRST_PERSON = "minecraft:first_person";
    public const FREE = "minecraft:free";
    public const THIRD_PERSON = "minecraft:third_person";
    public const THIRD_PERSON_FRONT = "minecraft:third_person_front";

    /** @var list<CameraPreset> */
    private array $presets = [];
    /** @var array<string,int> */
    private array $indices = [];

    public function __construct()
    {
        foreach ([
            self::FIRST_PERSON,
            self::FREE,
            self::THIRD_PERSON,
            self::THIRD_PERSON_FRONT,
        ] as $name) {
            $this->register($this->create($name, ""));
        }
        $this->register($this->create("mythicmobs:cinematic"));
    }

    public function register(CameraPreset $preset): int
    {
        $name = $preset->getName();
        if (isset($this->indices[$name])) {
            return $this->indices[$name];
        }
        $index = count($this->presets);
        $this->presets[] = $preset;
        $this->indices[$name] = $index;
        return $index;
    }

    public function index(string $name): int
    {
        return $this->indices[$name]
            ?? throw new \InvalidArgumentException(
                "Unknown camera preset '$name'."
            );
    }

    public function send(Player $player): void
    {
        $player->getNetworkSession()->sendDataPacket(
            CameraPresetsPacket::create($this->presets)
        );
    }

    public function create(
        string $name,
        string $parent = self::FREE,
        ?float $x = null,
        ?float $y = null,
        ?float $z = null,
        ?float $pitch = null,
        ?float $yaw = null,
        ?bool $playerEffects = null,
        ?int $audioListenerType = null
    ): CameraPreset {
        return new CameraPreset(
            $name,
            $parent,
            $x,
            $y,
            $z,
            $pitch,
            $yaw,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $audioListenerType,
            $playerEffects,
            null,
            null
        );
    }
}
