<?php

declare(strict_types=1);

namespace mythicmobs\entity;

interface MythicEntity
{
    /** @param array<string, mixed> $definition */
    public function configureMythic(string $internalName, array $definition, int $level, string $faction, float $damage): void;
    public function getMythicName(): string;
    /** @return array<string, mixed> */
    public function getMythicDefinition(): array;
    public function getMythicLevel(): int;
    public function getMythicFaction(): string;
    public function getMythicDamage(): float;
}
