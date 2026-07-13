<?php

declare(strict_types=1);

namespace mythicmobs\mob;

use mythicmobs\MythicMobs;
use mythicmobs\entity\MythicEntity;
use mythicmobs\entity\MythicSquid;
use mythicmobs\entity\MythicSkeleton;
use mythicmobs\entity\MythicVillager;
use mythicmobs\entity\MythicZombie;
use mythicmobs\entity\PersonalLootEntity;
use mythicmobs\entity\CustomEntityManager;
use mythicmobs\ai\AiController;
use pocketmine\entity\Entity;
use pocketmine\entity\Attribute;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class MobManager
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];
    /** @var array<int, array{entity:Living, key:string, definition:array<string,mixed>, level:int, damage:float, armor:float, power:float, faction:string, threat:array<int,float>, lastAttack:float, spawned:float}> */
    private array $active = [];
    /** @var array<string,true> */
    private array $invalidLevelWarnings = [];
    private AiController $ai;

    public function __construct(private MythicMobs $plugin)
    {
        $this->ai = new AiController();
    }

    public function reload(): void
    {
        $this->definitions = $this->plugin->loadDefinitions("Mobs");
        $this->invalidLevelWarnings = [];
    }
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function spawn(string $key, Location $location, int $level = 0, bool $useWorldScaling = false): ?Living
    {
        $definition = $this->definitions[$key] ?? null;
        if ($definition === null) {
            return null;
        }
        $type = strtolower((string) ($definition["Type"] ?? "zombie"));
        $networkType = strtolower((string) ($definition["NetworkType"] ?? ""));
        $customClass = $networkType !== "" ? CustomEntityManager::getInstance()->getEntityClass($networkType) : null;
        $entity = $customClass !== null ? new $customClass($location) : match($type) {
            "skeleton", "stray", "wither_skeleton" => new MythicSkeleton($location),
            "villager", "villager_v2" => new MythicVillager($location),
            "squid" => new MythicSquid($location),
            default => new MythicZombie($location),
        };
        if (!$entity instanceof Living || !$entity instanceof MythicEntity) {
            throw new \LogicException("Registered class for $networkType must be a Mythic Living entity");
        }
        $level = $level > 0 ? $level : ($useWorldScaling ? $this->worldLevel($location) : $this->configuredLevel($key, $definition));
        $health = max(1.0, $this->scaledValue($definition, "Health", (float) ($definition["Health"] ?? 20), $level));
        $faction = strtolower((string) ($definition["Faction"] ?? ""));
        $damage = max(0.0, $this->scaledValue($definition, "Damage", (float) ($definition["Damage"] ?? 2), $level));
        $armor = max(0.0, $this->scaledValue($definition, "Armor", (float) ($definition["Armor"] ?? ($definition["Options"]["Armor"] ?? 0)), $level));
        $power = max(0.0, $this->scaledValue($definition, "Power", (float) ($definition["Power"] ?? ($definition["Options"]["Power"] ?? 1)), $level));
        $entity->configureMythic($key, $definition, $level, $faction, $damage);
        $entity->setMaxHealth((int) ceil($health));
        $entity->setHealth($health);
        $defaults = $this->plugin->mobSetting("Configuration.DefaultMobOptions", []);
        $options = array_merge(is_array($defaults) ? $defaults : [], is_array($definition["Options"] ?? null) ? $definition["Options"] : []);
        $entity->setMovementSpeed(max(0.0, $this->scaledValue($definition, "MovementSpeed", (float) ($options["MovementSpeed"] ?? 0.2), $level)), true);
        $entity->setScale(max(0.05, $this->scaledValue($definition, "Scale", (float) ($options["Scale"] ?? 1.0), $level)));
        $knockback = max(0.0, min(1.0, $this->scaledValue($definition, "KnockbackResistance", (float) ($options["KnockbackResistance"] ?? 0), $level)));
        $entity->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue($knockback, true);
        $display = (string) ($definition["Display"] ?? $key);
        $display = str_replace(["<caster.level>", "<mob.level>", "<caster.name>"], [(string) $level, (string) $level, $key], $display);
        $entity->setNameTag(MythicMobs::color($display));
        $entity->setNameTagAlwaysVisible((bool) ($options["AlwaysShowName"] ?? true));
        $entity->setCanSaveWithChunk(false);
        foreach ((array) ($definition["Equipment"] ?? []) as $equipment) {
            $parts = preg_split('/\s+/', trim((string) $equipment));
            if (!$parts || !isset($parts[1]) || ($item = $this->plugin->makeItem($parts[0])) === null) {
                continue;
            }
            switch (strtoupper($parts[1])) {
                case "HEAD":
                    $entity->getArmorInventory()->setHelmet($item);
                    break;
                case "CHEST":
                    $entity->getArmorInventory()->setChestplate($item);
                    break;
                case "LEGS":
                    $entity->getArmorInventory()->setLeggings($item);
                    break;
                case "FEET":
                    $entity->getArmorInventory()->setBoots($item);
                    break;
            }
        }
        $this->active[$entity->getId()] = [
            "entity" => $entity,
            "key" => $key, "definition" => $definition, "level" => $level,
            "damage" => $damage, "armor" => $armor, "power" => $power,
            "faction" => $faction, "threat" => [],
            "lastAttack" => 0.0, "spawned" => microtime(true), "lastTarget" => null,
        ];
        $entity->spawnToAll();
        if (is_array($definition["BossBar"] ?? null)) {
            $this->plugin->getBossBarManager()->attach($entity, $definition["BossBar"], $key, $level);
        }
        if ((int) $this->plugin->general("Configuration.General.DebugLevel", 0) >= 2) {
            $this->plugin->getLogger()->debug("Spawned $key level $level (health=$health, damage=$damage, armor=$armor, power=$power) as " . $entity::class . " using network type " . $entity::getNetworkTypeId());
        }
        $this->plugin->getSkillEngine()->trigger($entity, "onSpawn", null);
        $this->plugin->getSkillEngine()->trigger($entity, "onSpawnOrLoad", null);
        $this->plugin->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function () use ($entity): void {
            if (!$entity->isClosed() && $this->isMythic($entity)) {
                $this->plugin->getSkillEngine()->trigger($entity, "onReady", null);
            }
        }), 1);
        return $entity;
    }

    public function isMythic(Entity $entity): bool
    {
        return $entity instanceof MythicEntity && isset($this->active[$entity->getId()]);
    }
    /** @return array{entity:Living, key:string, definition:array<string,mixed>, level:int, damage:float, armor:float, power:float, faction:string, threat:array<int,float>, lastAttack:float, spawned:float}|null */
    public function data(Entity $entity): ?array
    {
        return $this->active[$entity->getId()] ?? null;
    }
    public function damage(Entity $entity): float
    {
        return $entity instanceof MythicEntity ? $entity->getMythicDamage() : 1.0;
    }
    public function power(Entity $entity): float
    {
        return $this->active[$entity->getId()]["power"] ?? 1.0;
    }
    /** @return list<Entity> */
    public function threatTargets(Entity $mob): array
    {
        $threat = $this->active[$mob->getId()]["threat"] ?? [];
        arsort($threat);
        $result = [];
        foreach (array_keys($threat) as $id) {
            $entity = $this->active[$id]["entity"] ?? $this->plugin->getServer()->getWorldManager()->findEntity($id);
            if ($entity !== null && !$entity->isClosed()) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /** @return array<int,array{player:Player,damage:float}> */
    public function participants(Entity $mob): array
    {
        $threat = $this->active[$mob->getId()]["threat"] ?? [];
        $result = [];
        foreach ($threat as $entityId => $damage) {
            $entity = $this->plugin->getServer()->getWorldManager()->findEntity($entityId);
            if (!$entity instanceof Player || !$entity->isConnected()) {
                continue;
            }

            $result[$entityId] = [
                "player" => $entity,
                "damage" => max(0.0, (float) $damage),
            ];
        }

        uasort(
            $result,
            static fn (array $left, array $right): int => $right["damage"] <=> $left["damage"],
        );
        return $result;
    }
    public function killMessage(Entity $mob, Player $target): ?string
    {
        $data = $this->active[$mob->getId()] ?? null;
        $messages = $data["definition"]["KillMessages"] ?? [];
        if (!is_array($messages) || $messages === []) {
            return null;
        }
        $message = (string) $messages[array_rand($messages)];
        $message = str_replace(["<target.name>", "<target.display_name>", "<caster.name>", "<mob.name>", "<caster.level>", "<&sq>"], [$target->getName(), $target->getDisplayName(), $mob->getNameTag() !== "" ? $mob->getNameTag() : $data["key"], $data["key"], (string) $data["level"], "'"], $message);
        return MythicMobs::color((string) $this->plugin->mobSetting("Configuration.Mobs.KillMessagePrefix", "") . $message);
    }
    public function forget(Entity $entity): void
    {
        $this->plugin->getBossBarManager()->remove($entity->getId());
        $this->ai->forget($entity->getId());
        unset($this->active[$entity->getId()]);
    }
    public function removeAll(): void
    {
        $this->plugin->getBossBarManager()->removeAll();
        foreach ($this->activeEntities() as $entity) {
            $this->ai->forget($entity->getId());
            $entity->close();
        }
        $this->active = [];
    }
    /** @return array<string,int> */
    public function activeCounts(): array
    {
        $result = [];
        foreach ($this->active as $data) {
            $result[$data["key"]] = ($result[$data["key"]] ?? 0) + 1;
        }
        ksort($result);
        return $result;
    }

    public function countInChunk(
        string $key,
        Position $position
    ): int {
        $chunkX = $position->getFloorX() >> 4;
        $chunkZ = $position->getFloorZ() >> 4;
        $count = 0;
        foreach ($this->active as $data) {
            $entity = $data["entity"];
            if (
                $entity->isClosed() ||
                strcasecmp($data["key"], $key) !== 0 ||
                $entity->getWorld() !== $position->getWorld() ||
                ($entity->getPosition()->getFloorX() >> 4) !== $chunkX ||
                ($entity->getPosition()->getFloorZ() >> 4) !== $chunkZ
            ) {
                continue;
            }

            ++$count;
        }

        return $count;
    }

    public function hasNearbyMob(
        string $key,
        Position $position,
        float $radius = 1.0
    ): bool {
        $maximumDistance = $radius * $radius;
        foreach ($this->active as $data) {
            $entity = $data["entity"];
            if (
                $entity->isClosed() ||
                strcasecmp($data["key"], $key) !== 0 ||
                $entity->getWorld() !== $position->getWorld()
            ) {
                continue;
            }
            if (
                $entity->getPosition()->distanceSquared($position) <=
                $maximumDistance
            ) {
                return true;
            }
        }

        return false;
    }
    public function killByName(string $key): int
    {
        return $this->killMatching(fn (array $data) => strcasecmp($data["key"], $key) === 0);
    }
    public function killByFaction(string $faction): int
    {
        return $this->killMatching(fn (array $data) => strcasecmp($data["faction"], $faction) === 0);
    }
    private function killMatching(callable $filter): int
    {
        $count = 0;
        foreach (array_keys($this->active) as $id) {
            if (!$filter($this->active[$id])) {
                continue;
            }
            $entity = $this->active[$id]["entity"];
            $this->ai->forget($id);
            if (!$entity->isClosed()) {
                $entity->close();
            }
            unset($this->active[$id]);
            ++$count;
        }
        return $count;
    }

    /** @param array<string,mixed> $definition */
    private function configuredLevel(string $key, array $definition): int
    {
        $value = $definition["Level"] ?? $definition["MobLevel"] ?? ($definition["Options"]["Level"] ?? 1);
        return $this->rollLevel($value, "mob $key");
    }

    public function rollLevel(mixed $value, string $context = "level"): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value))) {
            return max(1, (int) $value);
        }
        if (is_string($value) && preg_match('/^\s*(\d+)\s*(?:to|-)\s*(\d+)\s*$/i', $value, $match)) {
            $min = max(1, (int) $match[1]);
            $max = max(1, (int) $match[2]);
            return mt_rand(min($min, $max), max($min, $max));
        }
        $warning = $context . ":" . json_encode($value);
        if (!isset($this->invalidLevelWarnings[$warning])) {
            $this->invalidLevelWarnings[$warning] = true;
            $this->plugin->getLogger()->error("Invalid level '" . (is_scalar($value) ? (string) $value : get_debug_type($value)) . "' for $context; using 1.");
        }
        return 1;
    }

    /** @param array<string,mixed> $definition */
    private function scaledValue(array $definition, string $attribute, float $base, int $level): float
    {
        $modifiers = is_array($definition["LevelModifiers"] ?? null) ? $definition["LevelModifiers"] : [];
        foreach ($modifiers as $name => $modifier) {
            if (strcasecmp((string) $name, $attribute) === 0 && is_numeric($modifier)) {
                return $base + (float) $modifier * ($level - 1);
            }
        }
        return $base * $this->scale($attribute, $level);
    }

    private function scale(string $attribute, int $level): float
    {
        $equation = (string) $this->plugin->mobSetting("Configuration.MobLeveling.ScalingEquations.$attribute", "");
        if (trim(strtoupper($equation)) === "V") {
            return 1.0;
        }
        if (preg_match('/\(\(([0-9.]+)\)\^\(L-1\)\)/', $equation, $match)) {
            return (float) ((float) $match[1] ** ($level - 1));
        }
        $modifier = (float) $this->plugin->mobSetting("Configuration.MobLeveling.DefaultLevelModifiers.$attribute", 0.0);
        return 1 + ($level - 1) * $modifier;
    }

    public function setLevel(Living $entity, int $level): bool
    {
        $id = $entity->getId();
        if (!isset($this->active[$id])) {
            return false;
        }
        $level = max(1, $level);
        $data = &$this->active[$id];
        $definition = $data["definition"];
        $oldMax = max(1, $entity->getMaxHealth());
        $healthRatio = $entity->getHealth() / $oldMax;
        $health = max(1.0, $this->scaledValue($definition, "Health", (float) ($definition["Health"] ?? 20), $level));
        $data["level"] = $level;
        $data["damage"] = max(0.0, $this->scaledValue($definition, "Damage", (float) ($definition["Damage"] ?? 2), $level));
        $data["armor"] = max(0.0, $this->scaledValue($definition, "Armor", (float) ($definition["Armor"] ?? ($definition["Options"]["Armor"] ?? 0)), $level));
        $data["power"] = max(0.0, $this->scaledValue($definition, "Power", (float) ($definition["Power"] ?? ($definition["Options"]["Power"] ?? 1)), $level));
        $options = is_array($definition["Options"] ?? null) ? $definition["Options"] : [];
        $entity->setMaxHealth((int) ceil($health));
        $entity->setHealth(min($health, max(0.01, $health * $healthRatio)));
        $entity->setMovementSpeed(max(0.0, $this->scaledValue($definition, "MovementSpeed", (float) ($options["MovementSpeed"] ?? 0.2), $level)), true);
        $entity->setScale(max(0.05, $this->scaledValue($definition, "Scale", (float) ($options["Scale"] ?? 1), $level)));
        $knockback = max(0.0, min(1.0, $this->scaledValue($definition, "KnockbackResistance", (float) ($options["KnockbackResistance"] ?? 0), $level)));
        $entity->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)?->setValue($knockback, true);
        $entity->configureMythic($data["key"], $definition, $level, $data["faction"], $data["damage"]);
        $display = str_replace(["<caster.level>", "<mob.level>", "<caster.name>"], [(string) $level, (string) $level, $data["key"]], (string) ($definition["Display"] ?? $data["key"]));
        $entity->setNameTag(MythicMobs::color($display));
        $this->plugin->getBossBarManager()->updateLevel($entity, $level);
        return true;
    }
    private function worldLevel(Location $location): int
    {
        $worldName = $location->getWorld()->getFolderName();
        $config = $this->plugin->mobSetting("Configuration.MobLeveling.WorldScaling.$worldName", $this->plugin->mobSetting("Configuration.MobLeveling.WorldScaling.Default", []));
        if (!is_array($config) || !(bool) ($config["Enabled"] ?? false)) {
            return 1;
        }
        $blocks = max(1, (int) ($config["PerBlocksFromSpawn"] ?? 250));
        return max(1, 1 + (int) floor(sqrt($location->distanceSquared($location->getWorld()->getSpawnLocation())) / $blocks));
    }
    /** @return list<Living> */
    public function activeEntities(): array
    {
        $result = [];
        foreach ($this->active as $data) {
            $entity = $data["entity"];
            if ($entity instanceof Living && !$entity->isClosed()) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    public function addThreat(Entity $mob, Entity $source, float $amount): void
    {
        if (
            !isset($this->active[$mob->getId()]) ||
            $source->isClosed() ||
            ($source instanceof Player && !$this->isAggroTarget($source))
        ) {
            return;
        }
        $id = $mob->getId();
        $this->active[$id]["threat"][$source->getId()] = ($this->active[$id]["threat"][$source->getId()] ?? 0.0) + max(0.01, $amount);
        $mob->setTargetEntity($source);
    }

    public function tick(): void
    {
        $this->ai->beginTick();
        foreach (array_keys($this->active) as $id) {
            $entity = $this->active[$id]["entity"];
            if (!$entity instanceof Living || $entity->isClosed() || !$entity->isAlive()) {
                $this->ai->forget($id);
                unset($this->active[$id]);
                continue;
            }
            $data = &$this->active[$id];
            $target = $this->selectTarget($entity, $data);
            $entity->setTargetEntity($target);
            $previous = $data["lastTarget"] ?? null;
            $current = $target?->getId();
            if ($previous === null && $current !== null) {
                $this->plugin->getSkillEngine()->trigger($entity, "onEnterCombat", $target);
                $this->plugin->getSkillEngine()->trigger($entity, "onCombat", $target);
            } elseif ($previous !== null && $current === null) {
                $this->plugin->getSkillEngine()->trigger($entity, "onDropCombat", null);
            } elseif ($previous !== $current && $current !== null) {
                $this->plugin->getSkillEngine()->trigger($entity, "onChangeTarget", $target);
            }
            $data["lastTarget"] = $current;
            $this->ai->tick($entity, $data, $target);
        }
    }

    /** @param array<string,mixed> $data */
    private function selectTarget(Living $mob, array &$data): ?Entity
    {
        $follow = max(1.0, (float) ($data["definition"]["Options"]["FollowRange"] ?? 16));
        foreach ($data["threat"] as $id => $threat) {
            $entity = $this->active[$id]["entity"] ?? $this->plugin->getServer()->getWorldManager()->findEntity($id);
            if (
                $entity === null ||
                $entity->isClosed() ||
                ($entity instanceof Player && !$this->isAggroTarget($entity)) ||
                $entity->getWorld() !== $mob->getWorld() ||
                $entity->getPosition()->distanceSquared(
                    $mob->getPosition()
                ) > $follow * $follow
            ) {
                unset($data["threat"][$id]);
            }
        }
        $selectors = $this->targetSelectors((array) ($data["definition"]["AITargetSelectors"] ?? ["players"]));
        foreach ($selectors as [$name, $argument]) {
            if (in_array($name, ["hurtbytarget", "attacker", "attackers"], true) && $data["threat"] !== []) {
                arsort($data["threat"]);
                $targetId = (int)array_key_first($data["threat"]);
                $target = $this->active[$targetId]["entity"] ?? $this->plugin->getServer()->getWorldManager()->findEntity($targetId);
                if ($target !== null) {
                    return $target;
                }
            }
            $best = null;
            $bestDistance = INF;
            if (in_array($name, ["players","nearestplayer"], true)) {
                foreach ($this->plugin->getServer()->getOnlinePlayers() as $candidate) {
                    if (
                        !$this->isAggroTarget($candidate) ||
                        $candidate->getWorld() !== $mob->getWorld() ||
                        (
                            $data["faction"] !== "" &&
                            $candidate->hasPermission(
                                "faction." . $data["faction"]
                            )
                        )
                    ) {
                        continue;
                    }
                    $distance = $candidate->getPosition()->distanceSquared($mob->getPosition());
                    if ($distance <= $follow * $follow && $distance < $bestDistance) {
                        $best = $candidate;
                        $bestDistance = $distance;
                    }
                }
            }
            if ($best !== null) {
                return $best;
            }
            foreach ($this->active as $otherId => $other) {
                if ($otherId === $mob->getId()) {
                    continue;
                }
                $candidate = $other["entity"];
                if ($candidate->isClosed() || !$candidate->isAlive() || $candidate->getWorld() !== $mob->getWorld()) {
                    continue;
                }
                $matches = match($name) {
                    "otherfactionmonsters" => $other["faction"] !== $data["faction"],
                    "specificfactionmonsters", "specifictargetfaction" => $other["faction"] === strtolower($argument),
                    "monsters", "nearestmonster" => true,
                    "specificmob" => strcasecmp($other["key"], $argument) === 0,
                    default => false,
                };
                $distance = $candidate->getPosition()->distanceSquared($mob->getPosition());
                if ($matches && $distance <= $follow * $follow && $distance < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }
        return null;
    }

    private function isAggroTarget(Player $player): bool
    {
        return $player->isConnected()
            && $player->isAlive()
            && !$player->isCreative(true)
            && !$player->isSpectator()
            && !$player->isInvisible();
    }

    /** @param list<mixed> $lines @return list<array{string,string}> */
    private function targetSelectors(array $lines): array
    {
        $result = [];
        $order = 0;
        foreach ($lines as $line) {
            $text = trim((string)$line);
            if ($text === "") {
                continue;
            }
            $priority = 1000 + $order;
            if (preg_match('/^(\d+)\s+(.+)$/', $text, $match)) {
                $priority = (int)$match[1];
                $text = $match[2];
            } [$name,$argument] = array_pad(preg_split('/\s+/', strtolower($text), 2) ?: [], 2, "");
            if ($name === "clear") {
                $result = [];
                ++$order;
                continue;
            }
            $result[] = ["priority" => $priority,"order" => $order++,"name" => $name,"argument" => $argument];
        }
        usort($result, fn (array $a, array $b) => [$a["priority"],$a["order"]] <=> [$b["priority"],$b["order"]]);
        return array_map(fn (array $entry) => [$entry["name"],$entry["argument"]], $result);
    }

    public function applyDamageModifier(EntityDamageEvent $event): void
    {
        $data = $this->active[$event->getEntity()->getId()] ?? null;
        if ($data === null) {
            return;
        }
        if ($data["armor"] > 0) {
            $current = $event->getModifier(EntityDamageEvent::MODIFIER_ARMOR);
            $extra = -$event->getFinalDamage() * min(20.0, $data["armor"]) * 0.04;
            $event->setModifier($current + $extra, EntityDamageEvent::MODIFIER_ARMOR);
        }
        $raw = $data["definition"]["DamageModifiers"] ?? [];
        if (!is_array($raw)) {
            return;
        }
        $modifiers = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $modifiers[strtoupper(trim($key))] = (float) $value;
            } elseif (is_string($value)) {
                $parts = preg_split('/[\s:]+/', trim($value), 2);
                if (isset($parts[0], $parts[1])) {
                    $modifiers[strtoupper($parts[0])] = (float) $parts[1];
                }
            }
        }
        $name = match($event->getCause()) {
            EntityDamageEvent::CAUSE_CONTACT => "CONTACT",
            EntityDamageEvent::CAUSE_ENTITY_ATTACK => "ENTITY_ATTACK",
            EntityDamageEvent::CAUSE_PROJECTILE => "PROJECTILE",
            EntityDamageEvent::CAUSE_SUFFOCATION => "SUFFOCATION",
            EntityDamageEvent::CAUSE_FALL => "FALL",
            EntityDamageEvent::CAUSE_FIRE => "FIRE",
            EntityDamageEvent::CAUSE_FIRE_TICK => "FIRE_TICK",
            EntityDamageEvent::CAUSE_LAVA => "LAVA",
            EntityDamageEvent::CAUSE_DROWNING => "DROWNING",
            EntityDamageEvent::CAUSE_BLOCK_EXPLOSION => "BLOCK_EXPLOSION",
            EntityDamageEvent::CAUSE_ENTITY_EXPLOSION => "ENTITY_EXPLOSION",
            EntityDamageEvent::CAUSE_VOID => "VOID",
            EntityDamageEvent::CAUSE_SUICIDE => "SUICIDE",
            EntityDamageEvent::CAUSE_MAGIC => "MAGIC",
            EntityDamageEvent::CAUSE_CUSTOM => "CUSTOM",
            EntityDamageEvent::CAUSE_STARVATION => "STARVATION",
            EntityDamageEvent::CAUSE_FALLING_BLOCK => "FALLING_BLOCK",
            default => "CUSTOM",
        };
        $aliases = match($name) {
            "MAGIC" => ["MAGIC", "POISON", "WITHER", "DRAGON_BREATH", "SONIC_BOOM"],
            "CONTACT" => ["CONTACT", "CAMPFIRE", "HOT_FLOOR", "CRAMMING", "FLY_INTO_WALL", "FREEZE", "WORLD_BORDER", "DRYOUT", "MELTING", "LIGHTNING"],
            "ENTITY_ATTACK" => ["ENTITY_ATTACK", "ENTITY_SWEEP_ATTACK", "THORNS"],
            "SUICIDE" => ["SUICIDE", "KILL"],
            "BLOCK_EXPLOSION" => ["BLOCK_EXPLOSION", "EXPLOSION"],
            "ENTITY_EXPLOSION" => ["ENTITY_EXPLOSION", "EXPLOSION"],
            default => [$name],
        };
        $modifier = null;
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $modifiers)) {
                $modifier = $modifiers[$alias];
                break;
            }
        }
        if ($modifier === null) {
            return;
        }
        $damage = $event->getOriginalBaseDamage();
        if ($modifier < 0) {
            $event->cancel();
            if ($event->getEntity() instanceof Living) {
                $event->getEntity()->heal(new EntityRegainHealthEvent($event->getEntity(), $damage * abs($modifier), EntityRegainHealthEvent::CAUSE_CUSTOM));
            }
            return;
        }
        $event->setBaseDamage($damage * $modifier);
        if ($modifier === 0.0) {
            foreach (array_keys($event->getModifiers()) as $type) {
                $event->setModifier(0.0, $type);
            }
        }
    }

    public function applyDrops(EntityDeathEvent $event): void
    {
        $mob = $event->getEntity();
        $data = $this->active[$mob->getId()] ?? null;
        if ($data === null) {
            return;
        }

        $options = is_array($data["definition"]["Options"] ?? null) ? $data["definition"]["Options"] : [];
        $drops = (bool) ($options["PreventOtherDrops"] ?? false) ? [] : $event->getDrops();
        $entries = array_values((array) ($data["definition"]["Drops"] ?? []));
        $perPlayer = (bool) (
            $data["definition"]["DropsPerPlayer"]
            ?? $options["DoPerPlayerDrops"]
            ?? $this->plugin->mobSetting(
                "Configuration.MobDrops.DoPerPlayerDropsByDefault",
                false,
            )
        );

        if ($perPlayer) {
            $this->dropForParticipants($mob, $data, $entries, $options);
        } else {
            $drops = [
                ...$drops,
                ...$this->plugin->getDropManager()->roll(
                    $entries,
                    (int) $data["level"],
                ),
            ];
        }

        $event->setDrops($drops);
        if (isset($data["definition"]["Experience"])) {
            $event->setXpDropAmount(max(0, (int) $data["definition"]["Experience"]));
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param list<mixed> $entries
     * @param array<string,mixed> $options
     */
    private function dropForParticipants(
        Living $mob,
        array $data,
        array $entries,
        array $options,
    ): void {
        $minimum = (float) (
            $data["definition"]["MinimumDamagePercentForDrops"]
            ?? $options["MinimumDamagePercentForDrops"]
            ?? $this->plugin->mobSetting(
                "Configuration.MobDrops.MinimumDamagePercentForDrops",
                0.0,
            )
        );
        if ($minimum > 1.0) {
            $minimum /= 100.0;
        }
        $minimum = max(0.0, min(1.0, $minimum));

        $clientSide = (bool) (
            $data["definition"]["ClientsideDrops"]
            ?? $options["DoClientsideDrops"]
            ?? $this->plugin->mobSetting(
                "Configuration.MobDrops.DoClientsideDropsByDefault",
                false,
            )
        );

        foreach ($this->participants($mob) as $participant) {
            $player = $participant["player"];
            $damagePercent = min(
                1.0,
                $participant["damage"] / max(1.0, (float) $mob->getMaxHealth()),
            );
            if ($damagePercent < $minimum) {
                continue;
            }

            $items = $this->plugin->getDropManager()->roll(
                $entries,
                (int) $data["level"],
                $player,
                $damagePercent,
            );
            foreach ($items as $item) {
                $this->spawnPersonalDrop($mob, $player, $item, $clientSide);
            }
        }
    }

    private function spawnPersonalDrop(
        Living $mob,
        Player $player,
        \pocketmine\item\Item $item,
        bool $clientSide,
    ): void {
        $remaining = $item->getCount();
        while ($remaining > 0) {
            $stack = clone $item;
            $count = min($remaining, $stack->getMaxStackSize());
            $stack->setCount($count);
            $remaining -= $count;

            $ownerUuid = $player->getUniqueId()->toString();
            $tag = $stack->getNamedTag();
            $tag->setString("MythicLootOwner", $ownerUuid);
            $stack->setNamedTag($tag);

            if ($clientSide) {
                $entity = new PersonalLootEntity(
                    Location::fromObject($mob->getPosition(), $mob->getWorld()),
                    $stack,
                    $ownerUuid,
                );
                $entity->setOwner($ownerUuid);
                $entity->setPickupDelay(10);
                $entity->spawnTo($player);
                continue;
            }

            $entity = $mob->getWorld()->dropItem($mob->getPosition(), $stack);
            $entity?->setOwner($ownerUuid);
        }
    }
}
