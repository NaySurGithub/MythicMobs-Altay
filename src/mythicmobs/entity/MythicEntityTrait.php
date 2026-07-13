<?php

declare(strict_types=1);

namespace mythicmobs\entity;

trait MythicEntityTrait
{
    private string $mythicName = "";
    /** @var array<string, mixed> */
    private array $mythicDefinition = [];
    private int $mythicLevel = 1;
    private string $mythicFaction = "";
    private float $mythicDamage = 1.0;

    /** @param array<string, mixed> $definition */
    public function configureMythic(string $internalName, array $definition, int $level, string $faction, float $damage): void
    {
        $this->mythicName = $internalName;
        $this->mythicDefinition = $definition;
        $this->mythicLevel = $level;
        $this->mythicFaction = $faction;
        $this->mythicDamage = $damage;
    }

    public function getMythicName(): string
    {
        return $this->mythicName;
    }
    /** @return array<string, mixed> */
    public function getMythicDefinition(): array
    {
        return $this->mythicDefinition;
    }
    public function getMythicLevel(): int
    {
        return $this->mythicLevel;
    }
    public function getMythicFaction(): string
    {
        return $this->mythicFaction;
    }
    public function getMythicDamage(): float
    {
        return $this->mythicDamage;
    }
}
