<?php

declare(strict_types=1);

namespace mythicmobs;

use mythicmobs\drop\DropManager;
use mythicmobs\mob\MobManager;
use mythicmobs\skill\SkillEngine;
use mythicmobs\spawner\SpawnerManager;
use mythicmobs\entity\MythicSquid;
use mythicmobs\entity\MythicSkeleton;
use mythicmobs\entity\MythicVillager;
use mythicmobs\entity\MythicZombie;
use mythicmobs\entity\CustomEntityManager;
use mythicmobs\entity\SkeletalKnightEntity;
use mythicmobs\model\ModelManager;
use mythicmobs\bossbar\BossBarManager;
use pocketmine\block\MonsterSpawner;
use pocketmine\block\BlockTypeIds;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Location;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\event\entity\ProjectileHitBlockEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\item\Item;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\world\Position;
use pocketmine\world\World;

final class MythicMobs extends PluginBase implements Listener
{
    private MobManager $mobs;
    private SkillEngine $skills;
    private SpawnerManager $spawners;
    private Config $generalConfig;
    private Config $mobConfig;
    private ModelManager $models;
    private BossBarManager $bossBars;
    private DropManager $drops;
    /** @var array<int, array{mob:int,time:float}> */
    private array $lastMobDamager = [];
    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    protected function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->saveResource("config-general.yml");
        $this->saveResource("config-mobs.yml");
        foreach (["Mobs", "Skills", "Items", "DropTables", "Spawners", "Models"] as $directory) {
            @mkdir($this->getDataFolder() . $directory, 0777, true);
            $this->saveResource($directory . "/Example" . $directory . ".yml");
        }
        $this->registerEntityTypes();
        $this->models = new ModelManager($this);
        $this->bossBars = new BossBarManager($this);
        $this->drops = new DropManager($this);
        $this->mobs = new MobManager($this);
        $this->skills = new SkillEngine($this);
        $this->spawners = new SpawnerManager($this);
        $this->reloadMythic();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->mobs->tick();
            $this->skills->tickTimers();
            $this->bossBars->tick();
        }), max(1, (int) $this->general("Configuration.Clock.Main", $this->getConfig()->get("ai-period-ticks", 1))));
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn () => $this->spawners->tick()), max(1, (int) $this->general("Configuration.Clock.Spawners", $this->getConfig()->get("spawner-period-ticks", 2))));
        $this->getLogger()->info(
            "Loaded "
            . count($this->mobs->definitions())
            . " mobs, "
            . count($this->skills->definitions())
            . " skills, "
            . count($this->items)
            . " items and "
            . count($this->drops->definitions())
            . " drop tables.",
        );
    }

    protected function onDisable(): void
    {
        if (isset($this->bossBars)) {
            $this->bossBars->removeAll();
        }
        if (isset($this->mobs)) {
            $this->mobs->removeAll();
        }
    }

    private function registerEntityTypes(): void
    {
        $manager = CustomEntityManager::getInstance();
        $manager->register(MythicZombie::getNetworkTypeId(), MythicZombie::class, fn (World $world, CompoundTag $nbt): Entity => new MythicZombie(EntityDataHelper::parseLocation($nbt, $world), $nbt), ["MythicMobsZombie", "mythicmobs:zombie"]);
        $manager->register(MythicVillager::getNetworkTypeId(), MythicVillager::class, fn (World $world, CompoundTag $nbt): Entity => new MythicVillager(EntityDataHelper::parseLocation($nbt, $world), $nbt), ["MythicMobsVillager", "mythicmobs:villager"]);
        $manager->register(MythicSquid::getNetworkTypeId(), MythicSquid::class, fn (World $world, CompoundTag $nbt): Entity => new MythicSquid(EntityDataHelper::parseLocation($nbt, $world), $nbt), ["MythicMobsSquid", "mythicmobs:squid"]);
        $manager->register(MythicSkeleton::getNetworkTypeId(), MythicSkeleton::class, fn (World $world, CompoundTag $nbt): Entity => new MythicSkeleton(EntityDataHelper::parseLocation($nbt, $world), $nbt), ["MythicMobsSkeleton", "mythicmobs:skeleton"]);
        $manager->register(SkeletalKnightEntity::IDENTIFIER, SkeletalKnightEntity::class, fn (World $world, CompoundTag $nbt): Entity => new SkeletalKnightEntity(EntityDataHelper::parseLocation($nbt, $world), $nbt));
    }

    public function reloadMythic(): void
    {
        $this->reloadConfig();
        $this->generalConfig = new Config($this->getDataFolder() . "config-general.yml", Config::YAML);
        $this->mobConfig = new Config($this->getDataFolder() . "config-mobs.yml", Config::YAML);
        $this->models?->reload();
        $this->items = $this->loadDefinitions("Items");
        $this->drops?->reload();
        $this->skills?->reload();
        $this->mobs?->reload();
        $this->spawners?->reload();
        if (isset($this->spawners)) {
            $this->getScheduler()->scheduleDelayedTask(
                new ClosureTask(
                    fn () => $this->spawners->refreshDisplays()
                ),
                1
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function loadDefinitions(string $folder): array
    {
        $result = [];
        foreach (glob($this->getDataFolder() . $folder . DIRECTORY_SEPARATOR . "*.yml") ?: [] as $file) {
            try {
                $data = yaml_parse_file($file);
                if (is_array($data)) {
                    foreach ($data as $key => $definition) {
                        if (is_string($key) && is_array($definition)) {
                            $result[$key] = $this->normalizeYamlMap($definition);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->getLogger()->error("Could not load " . basename($file) . ": " . $e->getMessage());
            }
        }
        return in_array(strtolower($folder), ["mobs", "items"], true) ? $this->resolveTemplates($result, $folder) : $result;
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,array<string,mixed>>
     */
    private function resolveTemplates(array $definitions, string $folder): array
    {
        $resolved = [];
        $state = [];
        $stack = [];
        $reported = [];
        $find = static function (string $name) use ($definitions): ?string {
            if (isset($definitions[$name])) {
                return $name;
            }
            foreach (array_keys($definitions) as $candidate) {
                if (strcasecmp($candidate, $name) === 0) {
                    return $candidate;
                }
            }
            return null;
        };
        $resolve = function (string $name) use (&$resolve, &$resolved, &$state, &$stack, &$reported, $definitions, $find, $folder): array {
            if (($state[$name] ?? 0) === 2) {
                return $resolved[$name];
            }
            if (($state[$name] ?? 0) === 1) {
                $chain = implode(" -> ", [...$stack, $name]);
                if (!isset($reported["cycle:$chain"])) {
                    $reported["cycle:$chain"] = true;
                    $this->getLogger()->error("Circular $folder template chain: $chain");
                }
                return [];
            }
            $state[$name] = 1;
            $stack[] = $name;
            $raw = $this->canonicalizeTemplateFields($definitions[$name], $folder);
            $templateValue = $this->mapValue($raw, "Template");
            $exclude = $this->excludeNames($this->mapValue($raw, "Exclude"));
            $merged = [];
            foreach ($this->templateNames($templateValue) as $requested) {
                $templateName = $find($requested);
                if ($templateName === null) {
                    $id = "missing:$name:$requested";
                    if (!isset($reported[$id])) {
                        $reported[$id] = true;
                        $this->getLogger()->error("Unknown $folder template '$requested' used by '$name'.");
                    }
                    continue;
                }
                $inherited = $resolve($templateName);
                foreach (array_keys($inherited) as $field) {
                    if (in_array(strtolower((string) $field), $exclude, true)) {
                        unset($inherited[$field]);
                    }
                }
                $merged = $this->mergeTemplateMaps($merged, $inherited);
            }
            foreach (array_keys($raw) as $field) {
                if (strcasecmp((string) $field, "Template") === 0 || strcasecmp((string) $field, "Exclude") === 0) {
                    unset($raw[$field]);
                }
            }
            $resolved[$name] = $this->mergeTemplateMaps($merged, $raw, true);
            array_pop($stack);
            $state[$name] = 2;
            return $resolved[$name];
        };
        foreach (array_keys($definitions) as $name) {
            $resolve($name);
        }
        return $resolved;
    }
    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function canonicalizeTemplateFields(array $definition, string $folder): array
    {
        if (strcasecmp($folder, "Mobs") !== 0) {
            return $definition;
        }
        $result = [];
        foreach ($definition as $key => $value) {
            $canonical = strcasecmp((string) $key, "Equip") === 0 ? "Equipment" : $key;
            if (isset($result[$canonical]) && is_array($result[$canonical]) && is_array($value)) {
                $result[$canonical] = $this->mergeTemplateMaps($result[$canonical], $value);
            } else {
                $result[$canonical] = $value;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $map */
    private function mapValue(array $map, string $wanted): mixed
    {
        foreach ($map as $key => $value) {
            if (strcasecmp((string) $key, $wanted) === 0) {
                return $value;
            }
        }
        return null;
    }
    /** @return list<string> */
    private function templateNames(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $entry) {
            if (is_scalar($entry)) {
                foreach (explode(",", (string) $entry) as $name) {
                    if (($name = trim($name)) !== "") {
                        $result[] = $name;
                    }
                }
            }
        }
        return $result;
    }
    /** @return list<string> */
    private function excludeNames(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $result = [];
        foreach (is_array($value) ? $value : [$value] as $key => $entry) {
            if (is_string($key)) {
                $result[] = strtolower(trim($key));
                continue;
            }
            if (is_scalar($entry)) {
                foreach (explode(",", (string) $entry) as $name) {
                    if (($name = strtolower(trim($name))) !== "") {
                        $result[] = $name;
                    }
                }
            }
        }
        return array_values(array_unique($result));
    }
    /**
     * @param array<string|int,mixed> $base
     * @param array<string|int,mixed> $child
     * @return array<string|int,mixed>
     */
    private function mergeTemplateMaps(array $base, array $child, bool $topLevel = false): array
    {
        if (array_is_list($base) || array_is_list($child)) {
            return array_is_list($base) && array_is_list($child) ? array_merge($base, $child) : $child;
        }
        foreach ($child as $key => $value) {
            $existingKey = null;
            foreach (array_keys($base) as $candidate) {
                if (strcasecmp((string) $candidate, (string) $key) === 0) {
                    $existingKey = $candidate;
                    break;
                }
            }
            $replaceMap = $topLevel && in_array(strtolower((string) $key), ["bossbar", "disguise", "mount"], true);
            $current = $existingKey !== null ? ($base[$existingKey] ?? null) : null;
            if ($existingKey !== null && $existingKey !== $key) {
                unset($base[$existingKey]);
            }
            $base[$key] = !$replaceMap && is_array($current) && is_array($value) ? $this->mergeTemplateMaps($current, $value) : $value;
        }
        return $base;
    }
    /** @return array<mixed> */
    private function normalizeYamlMap(array $value): array
    {
        $isList = array_is_list($value);
        $result = [];
        foreach ($value as $key => $child) {
            $normalizedKey = !$isList && $key === 1 ? "Y" : $key;
            $result[$normalizedKey] = is_array($child) ? $this->normalizeYamlMap($child) : $child;
        }
        return $result;
    }

    public function getMobManager(): MobManager
    {
        return $this->mobs;
    }
    public function getSkillEngine(): SkillEngine
    {
        return $this->skills;
    }
    public function getSpawnerManager(): SpawnerManager
    {
        return $this->spawners;
    }
    public function getModelManager(): ModelManager
    {
        return $this->models;
    }
    public function getBossBarManager(): BossBarManager
    {
        return $this->bossBars;
    }
    public function getDropManager(): DropManager
    {
        return $this->drops;
    }
    public function general(string $path, mixed $default = null): mixed
    {
        return $this->generalConfig->getNested($path, $default);
    }
    public function mobSetting(string $path, mixed $default = null): mixed
    {
        return $this->mobConfig->getNested($path, $default);
    }
    public function isDebugMode(): bool
    {
        return (bool) $this->general("Configuration.General.DebugMode", false);
    }

    public function onDamage(EntityDamageEvent $event): void
    {
        $victim = $event->getEntity();
        if ($event instanceof EntityDamageByEntityEvent && $event->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK && $event->getDamager() instanceof Player) {
            $this->skills->triggerItem($event->getDamager(), $event->getDamager()->getInventory()->getItemInHand(), "onAttack", $victim);
        }
        if ($victim instanceof Player && $event->getCause() !== EntityDamageEvent::CAUSE_MAGIC) {
            $this->triggerEquippedItemSkills($victim, "onDamaged", $event instanceof EntityDamageByEntityEvent ? $event->getDamager() : $victim);
        }
        if ($this->mobs->isMythic($victim)) {
            $data = $this->mobs->data($victim);
            $defaultPrevent = (bool) $this->mobSetting("Configuration.DefaultMobOptions.PreventVanillaDamage", false);
            if ((bool) ($data["definition"]["Options"]["PreventVanillaDamage"] ?? $defaultPrevent) && !in_array($event->getCause(), [EntityDamageEvent::CAUSE_MAGIC, EntityDamageEvent::CAUSE_CUSTOM], true)) {
                $event->cancel();
                return;
            }
            $this->mobs->applyDamageModifier($event);
            if ($event->isCancelled()) {
                return;
            }
            if ((bool) $this->mobSetting("Configuration.Mobs.CancelDamageIfZero", false) && $event->getFinalDamage() <= 0) {
                $event->cancel();
            }
            $trigger = $event instanceof EntityDamageByEntityEvent ? $event->getDamager() : null;
            if ($trigger instanceof Projectile && $trigger->getOwningEntity() !== null) {
                $trigger = $trigger->getOwningEntity();
            }
            if ($trigger !== null) {
                $this->mobs->addThreat($victim, $trigger, max(0.0, $event->getFinalDamage()));
            }
            $this->skills->trigger($victim, "onDamaged", $trigger);
        }
        if ($event instanceof EntityDamageByEntityEvent) {
            $attacker = $event->getDamager();
            if ($attacker instanceof Projectile && ($owner = $attacker->getOwningEntity()) !== null && $this->mobs->isMythic($owner)) {
                $attacker = $owner;
            }
            if ($attacker !== null && $this->mobs->isMythic($attacker)) {
                $event->setBaseDamage($this->mobs->damage($attacker));
                if ($victim instanceof Player) {
                    $this->lastMobDamager[$victim->getId()] = ["mob" => $attacker->getId(), "time" => microtime(true)];
                }
                $this->skills->trigger($attacker, "onAttack", $victim);
            }
        }
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void
    {
        $player = $event->getPlayer();
        $killer = null;
        $cause = $player->getLastDamageCause();
        if ($cause instanceof EntityDamageByEntityEvent) {
            $damager = $cause->getDamager();
            if ($damager !== null && $this->mobs->isMythic($damager)) {
                $killer = $damager;
            }
        }
        $recent = $this->lastMobDamager[$player->getId()] ?? null;
        if ($killer === null && $recent !== null && microtime(true) - $recent["time"] <= 10) {
            $candidate = $this->getServer()->getWorldManager()->findEntity($recent["mob"]);
            if ($candidate !== null && $this->mobs->isMythic($candidate)) {
                $killer = $candidate;
            }
        }
        unset($this->lastMobDamager[$player->getId()]);
        if ($killer === null || ($message = $this->mobs->killMessage($killer, $player)) === null) {
            return;
        }
        $event->setDeathMessage($message);
        $event->setDeathScreenMessage($message);
    }

    public function onEntityInteract(PlayerEntityInteractEvent $event): void
    {
        $entity = $event->getEntity();
        if ($this->mobs->isMythic($entity)) {
            $this->skills->trigger($entity, "onInteract", $event->getPlayer());
        }
    }

    public function onItemUse(PlayerItemUseEvent $event): void
    {
        $this->skills->triggerItem($event->getPlayer(), $event->getItem(), "onUse", $event->getPlayer());
    }
    public function onItemDrop(PlayerDropItemEvent $event): void
    {
        $this->skills->triggerItem($event->getPlayer(), $event->getItem(), "onDrop", $event->getPlayer());
    }

    public function onItemPickup(EntityItemPickupEvent $event): void
    {
        $collector = $event->getEntity();
        if (!$collector instanceof Player) {
            return;
        }

        $owner = $event->getItem()->getNamedTag()->getString("MythicLootOwner", "");
        if ($owner !== "" && $owner !== $collector->getUniqueId()->toString()) {
            $event->cancel();
            return;
        }

        if ($owner !== "") {
            $item = $event->getItem();
            $tag = $item->getNamedTag();
            $tag->removeTag("MythicLootOwner");
            $item->setNamedTag($tag);
            $event->setItem($item);
        }
    }
    private function triggerEquippedItemSkills(Player $player, string $trigger, Entity $triggerEntity): void
    {
        $items = [$player->getInventory()->getItemInHand(), ...$player->getArmorInventory()->getContents()];
        $seen = [];
        foreach ($items as $item) {
            $hash = spl_object_id($item);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $this->skills->triggerItem($player, $item, $trigger, $triggerEntity);
        }
    }

    public function onDeath(EntityDeathEvent $event): void
    {
        $entity = $event->getEntity();
        $cause = $entity->getLastDamageCause();
        $killer = $cause instanceof EntityDamageByEntityEvent ? $cause->getDamager() : null;
        if ($killer instanceof Projectile) {
            $killer = $killer->getOwningEntity();
        }
        if ($killer !== null && $this->mobs->isMythic($killer)) {
            $this->skills->trigger($killer, "onKill", $entity);
            if ($entity instanceof Player) {
                $this->skills->trigger($killer, "onPlayerKill", $entity);
            }
        }
        if (!$this->mobs->isMythic($entity)) {
            return;
        }
        $this->skills->trigger($entity, "onDeath", $killer);
        $this->mobs->applyDrops($event);
        $this->bossBars->remove($entity->getId());
        $this->mobs->forget($entity);
    }

    public function onRegainHealth(EntityRegainHealthEvent $event): void
    {
        if ($this->mobs->isMythic($event->getEntity())) {
            $this->skills->trigger($event->getEntity(), "onHeal", $event->getEntity());
        }
    }
    public function onTeleport(EntityTeleportEvent $event): void
    {
        if ($this->mobs->isMythic($event->getEntity())) {
            $this->skills->trigger($event->getEntity(), "onTeleport", $event->getEntity());
        }
    }
    public function onProjectileLaunch(ProjectileLaunchEvent $event): void
    {
        $projectile = $event->getEntity();
        $owner = $projectile->getOwningEntity();
        if ($owner !== null && $this->mobs->isMythic($owner)) {
            $this->skills->trigger($owner, "onShoot", $projectile);
        }
    }
    public function onProjectileHit(ProjectileHitEntityEvent $event): void
    {
        $owner = $event->getEntity()->getOwningEntity();
        if ($owner !== null && $this->mobs->isMythic($owner)) {
            $this->skills->trigger($owner, "onProjectileHit", $event->getEntityHit());
            if ($event->getEntity() instanceof \pocketmine\entity\projectile\Arrow) {
                $this->skills->trigger($owner, "onBowHit", $event->getEntityHit());
            }
        }
    }
    public function onProjectileLand(ProjectileHitBlockEvent $event): void
    {
        $owner = $event->getEntity()->getOwningEntity();
        if ($owner !== null && $this->mobs->isMythic($owner)) {
            $this->skills->trigger($owner, "onProjectileLand", $event->getEntity());
        }
    }
    public function onExplode(EntityExplodeEvent $event): void
    {
        if ($this->mobs->isMythic($event->getEntity())) {
            $this->skills->trigger($event->getEntity(), "onExplode", $event->getEntity());
        }
    }

    public function makeItem(string $key, int $count = 1): ?Item
    {
        $definition = $this->items[$key] ?? null;
        if ($definition === null) {
            $item = StringToItemParser::getInstance()->parse(strtolower($key));
            return $item?->setCount(max(1, $count));
        }
        $item = StringToItemParser::getInstance()->parse((string) ($definition["Id"] ?? $definition["Material"] ?? "stone")) ?? VanillaItems::PAPER();
        $item->setCount(max(1, $count));
        if (isset($definition["Display"])) {
            $item->setCustomName(self::color((string) $definition["Display"]));
        }
        if (is_array($definition["Lore"] ?? null)) {
            $item->setLore(array_map(fn ($v) => self::color((string) $v), $definition["Lore"]));
        }
        foreach ((array) ($definition["Enchantments"] ?? []) as $entry) {
            [$name, $level] = array_pad(explode(":", (string) $entry, 2), 2, "1");
            if (($enchantment = StringToEnchantmentParser::getInstance()->parse(strtolower($name))) !== null) {
                $item->addEnchantment(new EnchantmentInstance($enchantment, max(1, (int) $level)));
            }
        }
        $skills = array_values(array_map("strval", (array) ($definition["Skills"] ?? [])));
        if ($skills !== []) {
            $skillTags = array_map(fn (string $line) => new StringTag($line), $skills);
            $mythicTag = CompoundTag::create()->setString("InternalName", $key)->setTag("Skills", new ListTag($skillTags));
            $tag = $item->getNamedTag();
            $tag->setTag("MythicMobs", $mythicTag);
            $item->setNamedTag($tag);
        }
        return $item;
    }

    /** @return list<string> */
    public function itemSkills(Item $item): array
    {
        $list = $item->getNamedTag()->getCompoundTag("MythicMobs")?->getListTag("Skills", StringTag::class);
        if ($list === null) {
            return [];
        }
        $result = [];
        foreach ($list as $tag) {
            if ($tag instanceof StringTag) {
                $result[] = $tag->getValue();
            }
        }
        return $result;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        $sub = strtolower((string) ($args[0] ?? "help"));
        $sub = ["m" => "mobs", "i" => "items", "item" => "items", "s" => "spawners", "r" => "reload", "d" => "debug"][$sub] ?? $sub;
        if ($sub === "help") {
            $this->sendHelp($sender, (int) ($args[1] ?? 1));
            return true;
        }
        if (!$sender->hasPermission("mythicmobs.admin")) {
            $sender->sendMessage(TextFormat::RED . "Missing permission mythicmobs.admin");
            return true;
        }
        if ($sub === "reload") {
            $this->reloadMythic();
            $sender->sendMessage(TextFormat::GREEN . "MythicMobs configurations reloaded.");
            return true;
        }
        if ($sub === "version") {
            $sender->sendMessage(TextFormat::GOLD . "MythicMobs " . $this->getDescription()->getVersion() . TextFormat::GRAY . " for Altay API 5");
            return true;
        }
        if ($sub === "debug") {
            $level = max(0, (int) ($args[1] ?? 0));
            $this->generalConfig->setNested("Configuration.General.DebugLevel", $level);
            $this->generalConfig->save();
            $sender->sendMessage(TextFormat::YELLOW . "Debug level: $level");
            return true;
        }
        if ($sub === "debugmode") {
            $value = filter_var($args[1] ?? "true", FILTER_VALIDATE_BOOLEAN);
            $this->generalConfig->setNested("Configuration.General.DebugMode", $value);
            $this->generalConfig->save();
            $sender->sendMessage(TextFormat::YELLOW . "Debug mode: " . ($value ? "ON" : "OFF"));
            return true;
        }
        if ($sub === "save") {
            $this->spawners->save();
            $sender->sendMessage(TextFormat::GREEN . "Spawner state saved.");
            return true;
        }
        if ($sub === "mobs") {
            return $this->mobCommand($sender, array_slice($args, 1));
        }
        if ($sub === "items") {
            return $this->itemCommand($sender, array_slice($args, 1));
        }
        if ($sub === "skills") {
            return $this->skillCommand($sender, array_slice($args, 1));
        }
        if ($sub === "spawners") {
            return $this->spawnerCommand($sender, array_slice($args, 1));
        }
        if ($sub === "models") {
            $action = strtolower((string) ($args[1] ?? "list"));
            if ($action === "build") {
                $this->models->reload();
                $sender->sendMessage(TextFormat::GREEN . "Model pack rebuilt. Restart the server before clients join.");
            } else {
                $sender->sendMessage(TextFormat::GOLD . "Models: " . TextFormat::WHITE . implode(", ", array_keys($this->models->definitions())));
            }
            return true;
        }
        if ($sub === "test" && strtolower((string) ($args[1] ?? "")) === "cast") {
            return $this->skillCommand($sender, ["cast", $args[2] ?? ""]);
        }
        $sender->sendMessage(TextFormat::RED . "Unknown subcommand. Use /mm help.");
        return true;
    }

    private function sendHelp(CommandSender $sender, int $page): void
    {
        $pages = [
            1 => [
                "/mm help [page]" => "Show this command list.",
                "/mm version" => "Show the installed plugin version.",
                "/mm mobs list" => "List all configured mobs.",
                "/mm mobs info <mob>" => "Show a mob's configuration.",
                "/mm mobs listactive" => "Show active custom mob counts.",
                "/mm mobs spawn <mob>:<level> [amount]" => "Spawn a custom mob.",
                "/mm mobs kill <mob>" => "Remove active mobs by name.",
                "/mm mobs killall" => "Remove every active custom mob.",
            ],
            2 => [
                "/mm items list" => "List all configured items.",
                "/mm items info <item>" => "Show an item's information.",
                "/mm items get <item> [amount]" => "Give yourself a custom item.",
                "/mm items give <player> <item> [amount]" => "Give a custom item to a player.",
                "/mm skills cast <skill>" => "Cast a configured metaskill.",
                "/mm models list" => "List all configured models.",
                "/mm models build" => "Rebuild the model resource pack.",
            ],
            3 => [
                "/mm spawners list" => "List all configured spawners.",
                "/mm spawners info <name>" => "Show a spawner's configuration.",
                "/mm spawners create <name> <mob> [seconds] [max]" => "Create a spawner.",
                "/mm spawners set <name> <setting> <value>" => "Change a spawner setting.",
                "/mm spawners give <name> [player]" => "Give an NBT spawner block.",
                "/mm spawners resettimers <name>" => "Reset a spawner timer.",
                "/mm spawners delete <name>" => "Delete a runtime spawner.",
            ],
            4 => [
                "/mm reload" => "Reload all MythicMobs files.",
                "/mm save" => "Save runtime spawner state.",
                "/mm debug <level>" => "Set the debug level.",
                "/mm debugmode <true|false>" => "Toggle debug mode.",
            ],
        ];

        $page = max(1, min(count($pages), $page));
        $sender->sendMessage(
            TextFormat::GOLD . "MythicMobs Help " .
            TextFormat::YELLOW . "($page/" . count($pages) . ")"
        );

        foreach ($pages[$page] as $command => $description) {
            $sender->sendMessage(
                TextFormat::YELLOW . $command .
                TextFormat::GRAY . " - " . $description
            );
        }

        if ($page < count($pages)) {
            $sender->sendMessage(
                TextFormat::GRAY . "Next page: " .
                TextFormat::WHITE . "/mm help " . ($page + 1)
            );
        }
    }

    private function mobCommand(CommandSender $sender, array $args): bool
    {
        $action = strtolower((string) ($args[0] ?? "list"));
        if ($action === "list") {
            $sender->sendMessage(TextFormat::GOLD . "Mobs: " . TextFormat::WHITE . implode(", ", array_keys($this->mobs->definitions())));
            return true;
        }
        if ($action === "info") {
            $key = (string) ($args[1] ?? "");
            $definition = $this->mobs->definitions()[$key] ?? null;
            $sender->sendMessage($definition === null ? TextFormat::RED . "Unknown mob: $key" : TextFormat::GOLD . "$key: " . TextFormat::WHITE . "type=" . ($definition["Type"] ?? "zombie") . ", health=" . ($definition["Health"] ?? 20) . ", damage=" . ($definition["Damage"] ?? 2) . ", faction=" . ($definition["Faction"] ?? "none"));
            return true;
        }
        if ($action === "listactive" || $action === "stats") {
            $counts = $this->mobs->activeCounts();
            $sender->sendMessage(TextFormat::GOLD . "Active mobs (" . array_sum($counts) . "): " . TextFormat::WHITE . implode(", ", array_map(fn ($k, $v) => "$k=$v", array_keys($counts), $counts)));
            return true;
        }
        if ($action === "killall") {
            $count = array_sum($this->mobs->activeCounts());
            $this->mobs->removeAll();
            $sender->sendMessage(TextFormat::YELLOW . "Removed $count mobs.");
            return true;
        }
        if ($action === "kill") {
            $count = ($args[1] ?? "") === "-f" ? $this->mobs->killByFaction((string) ($args[2] ?? "")) : $this->mobs->killByName((string) ($args[1] ?? ""));
            $sender->sendMessage(TextFormat::YELLOW . "Removed $count mobs.");
            return true;
        }
        if ($action === "spawn") {
            $silent = in_array("-s", $args, true);
            $args = array_values(array_filter($args, fn ($v) => !in_array($v, ["-s", "-n", "-t"], true)));
            $spec = (string) ($args[1] ?? "");
            [$key, $levelRaw] = array_pad(explode(":", $spec, 2), 2, "0");
            $level = max(0, (int) $levelRaw);
            $amount = min(50, max(1, (int) ($args[2] ?? 1)));
            $location = $sender instanceof Player ? $sender->getLocation() : null;
            if (isset($args[3]) && str_contains((string) $args[3], ",")) {
                $location = $this->parseLocation((string) $args[3]);
            }
            if ($location === null) {
                $sender->sendMessage(TextFormat::RED . "Usage: /mm mobs spawn <mob>:<level> <amount> <world,x,y,z,yaw,pitch>");
                return true;
            }
            for ($i = 0; $i < $amount; ++$i) {
                if ($this->mobs->spawn($key, $location, $level) === null) {
                    $sender->sendMessage(TextFormat::RED . "Unknown mob: " . $key);
                    return true;
                }
            }
            if (!$silent) {
                $sender->sendMessage(TextFormat::GREEN . "Spawned $amount x $key" . ($level > 0 ? " (level $level)" : "") . ".");
            }
            return true;
        }
        return true;
    }

    private function itemCommand(CommandSender $sender, array $args): bool
    {
        $action = strtolower((string) ($args[0] ?? "list"));
        if ($action === "list") {
            $sender->sendMessage(TextFormat::GOLD . "Items: " . TextFormat::WHITE . implode(", ", array_keys($this->items)));
            return true;
        }
        if ($action === "info") {
            $key = (string) ($args[1] ?? "");
            $item = $this->makeItem($key);
            if ($item === null) {
                $sender->sendMessage(TextFormat::RED . "Unknown item: $key");
                return true;
            }
            $sender->sendMessage(TextFormat::GOLD . "$key: " . TextFormat::WHITE . "item=" . $item->getName() . ", nbt-skills=" . count($this->itemSkills($item)));
            return true;
        }
        $target = $sender instanceof Player ? $sender : null;
        $offset = 1;
        $silent = in_array("-s", $args, true);
        $dropExcess = in_array("-d", $args, true);
        $args = array_values(array_filter($args, fn ($v) => !in_array($v, ["-s", "-d"], true)));
        if ($action === "give") {
            $target = $this->getServer()->getPlayerExact((string) ($args[1] ?? ""));
            $offset = 2;
        }
        if (!$target instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Player not found or in-game only.");
            return true;
        }
        $key = (string) ($args[$offset] ?? "");
        $item = $this->makeItem($key, (int) ($args[$offset + 1] ?? 1));
        if ($item === null) {
            $sender->sendMessage(TextFormat::RED . "Unknown item: $key");
            return true;
        }
        $leftovers = $target->getInventory()->addItem($item);
        if ($dropExcess) {
            foreach ($leftovers as $leftover) {
                $target->getWorld()->dropItem($target->getPosition(), $leftover);
            }
        }
        if (!$silent && (bool) $this->general("Configuration.Commands.SendGiveItemFeedback", true)) {
            $sender->sendMessage(TextFormat::GREEN . "Gave $key to " . $target->getName() . ".");
        }
        return true;
    }

    private function skillCommand(CommandSender $sender, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage("In-game only.");
            return true;
        }
        $key = (string) ($args[1] ?? "");
        if (!$this->skills->cast($key, $sender, $sender)) {
            $sender->sendMessage(TextFormat::RED . "Unknown or cooling-down skill: $key");
        }
        return true;
    }

    private function spawnerCommand(CommandSender $sender, array $args): bool
    {
        $action = strtolower((string) ($args[0] ?? "list"));
        if ($action === "list") {
            $sender->sendMessage(TextFormat::GOLD . "Spawners: " . implode(", ", array_keys($this->spawners->definitions())));
            return true;
        }
        if ($action === "info") {
            $name = (string) ($args[1] ?? "");
            $data = $this->spawners->definitions()[$name] ?? null;
            $sender->sendMessage($data === null ? TextFormat::RED . "Unknown spawner." : TextFormat::GOLD . "$name: " . TextFormat::WHITE . json_encode($data));
            return true;
        }
        if ($action === "set") {
            $ok = $this->spawners->set((string) ($args[1] ?? ""), (string) ($args[2] ?? ""), $args[3] ?? "");
            $sender->sendMessage($ok ? TextFormat::GREEN . "Spawner updated." : TextFormat::RED . "Unknown spawner.");
            return true;
        }
        if ($action === "give") {
            $name = (string) ($args[1] ?? "");
            $target = isset($args[2])
                ? $this->getServer()->getPlayerExact((string) $args[2])
                : ($sender instanceof Player ? $sender : null);
            $item = $this->spawners->createItem($name);
            if (!$target instanceof Player) {
                $sender->sendMessage(
                    TextFormat::RED .
                    "Usage: /mm spawners give <name> <player>"
                );
                return true;
            }
            if ($item === null) {
                $sender->sendMessage(TextFormat::RED . "Unknown spawner.");
                return true;
            }

            $leftovers = $target->getInventory()->addItem($item);
            foreach ($leftovers as $leftover) {
                $target->getWorld()->dropItem(
                    $target->getPosition(),
                    $leftover
                );
            }
            $sender->sendMessage(
                TextFormat::GREEN .
                "Gave spawner $name to " . $target->getName() . "."
            );
            return true;
        }
        if ($action === "resettimers") {
            $sender->sendMessage($this->spawners->resetTimer((string) ($args[1] ?? "")) ? TextFormat::GREEN . "Timer reset." : TextFormat::RED . "Unknown spawner.");
            return true;
        }
        if ($action === "create" && $sender instanceof Player) {
            $name = (string) ($args[1] ?? "");
            $mob = (string) ($args[2] ?? "");
            if ($name === "" || !isset($this->mobs->definitions()[$mob])) {
                $sender->sendMessage(TextFormat::RED . "Usage: /mm spawners create <name> <mob> [seconds] [max]");
                return true;
            }
            $this->spawners->create($name, $mob, $sender->getPosition(), max(1, (int) ($args[3] ?? 30)), max(1, (int) ($args[4] ?? 1)));
            $sender->sendMessage(TextFormat::GREEN . "Spawner $name created.");
            return true;
        }
        if ($action === "delete") {
            $this->spawners->delete((string) ($args[1] ?? ""));
            $sender->sendMessage(TextFormat::YELLOW . "Spawner deleted.");
            return true;
        }
        return true;
    }

    /**
     * @priority MONITOR
     */
    public function onSpawnerPlace(BlockPlaceEvent $event): void
    {
        foreach ($event->getTransaction()->getBlocks() as [, , , $block]) {
            if (!$block instanceof MonsterSpawner) {
                continue;
            }

            $position = Position::fromObject(
                $block->getPosition(),
                $block->getPosition()->getWorld()
            );
            $name = $this->spawners->placeItem(
                $event->getItem(),
                $position
            );
            if ($name !== null) {
                $this->getScheduler()->scheduleDelayedTask(
                    new ClosureTask(
                        fn () => $this->spawners->updateDisplay(
                            $name,
                            $position
                        )
                    ),
                    1
                );
                $event->getPlayer()->sendMessage(
                    TextFormat::GREEN . "Placed Mythic spawner $name."
                );
            }
        }
    }

    /**
     * @priority HIGHEST
     */
    public function onSpawnerBreak(BlockBreakEvent $event): void
    {
        if (
            $event->getBlock()->getTypeId() !==
            BlockTypeIds::MONSTER_SPAWNER
        ) {
            return;
        }

        $item = $this->spawners->removeAt(
            $event->getBlock()->getPosition()
        );
        if ($item === null) {
            return;
        }

        $event->setDrops([$item]);
        $event->setXpDropAmount(0);
        $event->getPlayer()->sendMessage(
            TextFormat::YELLOW . "Recovered Mythic spawner."
        );
    }

    public function onSpawnerChunkSent(PlayerPostChunkSendEvent $event): void
    {
        $player = $event->getPlayer();
        $chunkX = $event->getChunkX();
        $chunkZ = $event->getChunkZ();
        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(
                function () use ($player, $chunkX, $chunkZ): void {
                    if (!$player->isConnected()) {
                        return;
                    }

                    $this->spawners->sendDisplaysInChunk(
                        $player,
                        $chunkX,
                        $chunkZ
                    );
                }
            ),
            2
        );
    }

    public static function color(string $text): string
    {
        return str_replace("&", TextFormat::ESCAPE, $text);
    }
    private function parseLocation(string $input): ?Location
    {
        $parts = explode(",", $input);
        if (count($parts) < 4) {
            return null;
        }
        $manager = $this->getServer()->getWorldManager();
        if (!$manager->isWorldLoaded($parts[0])) {
            $manager->loadWorld($parts[0]);
        }
        $world = $manager->getWorldByName($parts[0]);
        return $world === null ? null : new Location((float) $parts[1], (float) $parts[2], (float) $parts[3], $world, (float) ($parts[4] ?? 0), (float) ($parts[5] ?? 0));
    }
}
