<?php

declare(strict_types=1);

namespace mythicmobs\spawner;

use mythicmobs\MythicMobs;
use pocketmine\block\tile\MonsterSpawner as MonsterSpawnerTile;
use pocketmine\entity\Location;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\world\Position;

final class SpawnerManager
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];
    /** @var array<string, float> */
    private array $lastSpawn = [];
    /** @var array<string, list<int>> */
    private array $spawned = [];

    public function __construct(private MythicMobs $plugin)
    {
    }
    public function reload(): void
    {
        $this->definitions = $this->plugin->loadDefinitions("Spawners");
    }
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function tick(): void
    {
        if ($this->plugin->isDebugMode() || !(bool) $this->plugin->general("Configuration.Features.Spawners", true)) {
            return;
        }
        $now = microtime(true);
        foreach ($this->definitions as $name => $definition) {
            if (!(bool) ($definition["Enabled"] ?? true)) {
                continue;
            }
            $interval = max(1, (int) ($definition["Interval"] ?? 30));
            if ($now - ($this->lastSpawn[$name] ?? 0) < $interval) {
                continue;
            }
            $this->spawned[$name] = array_values(array_filter($this->spawned[$name] ?? [], fn (int $id) => ($entity = $this->plugin->getServer()->getWorldManager()->findEntity($id)) !== null && !$entity->isClosed()));
            if (count($this->spawned[$name]) >= max(1, (int) ($definition["MaxMobs"] ?? 1))) {
                continue;
            }
            $worldName = (string) ($definition["World"] ?? "world");
            $manager = $this->plugin->getServer()->getWorldManager();
            if (!$manager->isWorldLoaded($worldName)) {
                $manager->loadWorld($worldName);
            }
            $world = $manager->getWorldByName($worldName);
            if ($world === null) {
                continue;
            }
            $radius = max(0, (int) ($definition["Radius"] ?? 0));
            $location = new Location((float) ($definition["X"] ?? 0) + ($radius > 0 ? mt_rand(-$radius, $radius) : 0), (float) ($definition["Y"] ?? 64), (float) ($definition["Z"] ?? 0) + ($radius > 0 ? mt_rand(-$radius, $radius) : 0), $world, 0, 0);
            $level = array_key_exists("Level", $definition) ? $this->plugin->getMobManager()->rollLevel($definition["Level"], "spawner $name") : 0;
            $entity = $this->plugin->getMobManager()->spawn((string) ($definition["MobName"] ?? $definition["Mob"] ?? ""), $location, $level, (bool) ($definition["UseWorldScaling"] ?? false));
            if ($entity !== null) {
                $this->spawned[$name][] = $entity->getId();
                $this->lastSpawn[$name] = $now;
            }
        }
    }

    public function create(string $name, string $mob, Position $position, int $interval, int $max): void
    {
        $this->definitions[$name] = ["MobName" => $mob, "World" => $position->getWorld()->getFolderName(), "X" => round($position->x, 2), "Y" => round($position->y, 2), "Z" => round($position->z, 2), "Radius" => 2, "Interval" => $interval, "MaxMobs" => $max, "Enabled" => true];
        $this->saveRuntime();
    }

    public function delete(string $name): void
    {
        unset($this->definitions[$name], $this->lastSpawn[$name], $this->spawned[$name]);
        $this->saveRuntime();
    }
    public function resetTimer(string $name): bool
    {
        if (!isset($this->definitions[$name])) {
            return false;
        }
        $this->lastSpawn[$name] = 0;
        return true;
    }
    public function createItem(string $name): ?Item
    {
        $definition = $this->definitions[$name] ?? null;
        if ($definition === null) {
            return null;
        }

        $serialized = json_encode($definition, JSON_UNESCAPED_SLASHES);
        $item = StringToItemParser::getInstance()->parse("monster_spawner");
        if ($serialized === false || $item === null) {
            return null;
        }

        $mob = (string) ($definition["MobName"] ?? $definition["Mob"] ?? "unknown");
        $interval = max(1, (int) ($definition["Interval"] ?? 30));
        $maximum = max(1, (int) ($definition["MaxMobs"] ?? 1));
        $spawnerTag = CompoundTag::create()
            ->setString("Name", $name)
            ->setString("Definition", $serialized)
            ->setString("MobName", $mob)
            ->setInt("Interval", $interval)
            ->setInt("MaxMobs", $maximum);

        $tag = $item->getNamedTag();
        $tag->setTag("MythicSpawner", $spawnerTag);
        $item->setNamedTag($tag);
        $item->setCustomName(MythicMobs::color("&6Mythic Spawner: &f$name"));
        $item->setLore([
            MythicMobs::color("&7Mob: &f$mob"),
            MythicMobs::color("&7Interval: &f{$interval}s"),
            MythicMobs::color("&7Maximum mobs: &f$maximum"),
            MythicMobs::color("&8Place to activate this spawner."),
        ]);

        return $item;
    }

    public function placeItem(Item $item, Position $position): ?string
    {
        $stored = $this->readItem($item);
        if ($stored === null) {
            return null;
        }

        $definition = $stored["definition"];
        $mob = (string) ($definition["MobName"] ?? $definition["Mob"] ?? "");
        if ($mob === "" || !isset($this->plugin->getMobManager()->definitions()[$mob])) {
            return null;
        }

        $name = $this->uniqueName($stored["name"]);
        $definition["MobName"] = $mob;
        unset($definition["Mob"]);
        $definition["World"] = $position->getWorld()->getFolderName();
        $definition["X"] = $position->getFloorX();
        $definition["Y"] = $position->getFloorY();
        $definition["Z"] = $position->getFloorZ();
        $definition["Enabled"] = true;

        $this->definitions[$name] = $definition;
        $this->lastSpawn[$name] = microtime(true);
        $this->spawned[$name] = [];
        $this->saveRuntime();

        return $name;
    }

    public function removeAt(Position $position): ?Item
    {
        foreach ($this->definitions as $name => $definition) {
            if (!$this->isAt($definition, $position)) {
                continue;
            }

            $item = $this->createItem($name);
            $this->delete($name);
            return $item;
        }

        return null;
    }

    public function updateDisplay(string $name, Position $position): bool
    {
        $definition = $this->definitions[$name] ?? null;
        if ($definition === null) {
            return false;
        }

        $mobName = (string) (
            $definition["MobName"] ??
            $definition["Mob"] ??
            ""
        );
        $mob = $this->plugin->getMobManager()->definitions()[$mobName] ?? null;
        if ($mob === null) {
            return false;
        }

        $tile = $position->getWorld()->getTile($position);
        if (!$tile instanceof MonsterSpawnerTile) {
            return false;
        }

        $type = strtolower((string) (
            $mob["NetworkType"] ??
            $mob["Type"] ??
            "zombie"
        ));
        $identifier = str_contains($type, ":")
            ? $type
            : "minecraft:" . $type;
        [$defaultWidth, $defaultHeight] = $this->displaySize($type);
        $options = (array) ($mob["Options"] ?? []);
        $width = max(
            0.05,
            (float) ($definition["DisplayEntityWidth"] ?? $defaultWidth)
        );
        $height = max(
            0.05,
            (float) ($definition["DisplayEntityHeight"] ?? $defaultHeight)
        );
        $scale = max(
            0.05,
            (float) (
                $definition["DisplayEntityScale"] ??
                $options["Scale"] ??
                1.0
            )
        );

        $tile->readSaveData(
            CompoundTag::create()
                ->setString("EntityIdentifier", $identifier)
                ->setFloat("DisplayEntityWidth", $width)
                ->setFloat("DisplayEntityHeight", $height)
                ->setFloat("DisplayEntityScale", $scale)
        );
        $tile->clearSpawnCompoundCache();

        $packet = BlockActorDataPacket::create(
            BlockPosition::fromVector3($position),
            $tile->getSerializedSpawnCompound()
        );
        $position->getWorld()->broadcastPacketToViewers($position, $packet);

        return true;
    }

    public function refreshDisplays(): void
    {
        $worldManager = $this->plugin->getServer()->getWorldManager();
        foreach ($this->definitions as $name => $definition) {
            $worldName = (string) ($definition["World"] ?? "");
            $world = $worldManager->getWorldByName($worldName);
            if ($world === null) {
                continue;
            }

            $position = new Position(
                (float) ($definition["X"] ?? 0),
                (float) ($definition["Y"] ?? 0),
                (float) ($definition["Z"] ?? 0),
                $world
            );
            $this->updateDisplay($name, $position);
        }
    }

    /** @return array{float,float} */
    private function displaySize(string $type): array
    {
        return match ($type) {
            "skeleton", "stray", "wither_skeleton" => [0.6, 1.99],
            "villager", "villager_v2" => [0.6, 1.95],
            "squid" => [0.8, 0.8],
            default => [0.6, 1.95],
        };
    }

    /** @return array{name:string,definition:array<string,mixed>}|null */
    private function readItem(Item $item): ?array
    {
        $tag = $item->getNamedTag()->getCompoundTag("MythicSpawner");
        if ($tag === null) {
            return null;
        }

        $name = trim($tag->getString("Name", ""));
        $definition = json_decode(
            $tag->getString("Definition", ""),
            true
        );
        if ($name === "" || !is_array($definition)) {
            return null;
        }

        return [
            "name" => $name,
            "definition" => $definition,
        ];
    }

    private function uniqueName(string $base): string
    {
        if (!isset($this->definitions[$base])) {
            return $base;
        }

        $suffix = 1;
        do {
            $name = $base . "_" . $suffix;
            ++$suffix;
        } while (isset($this->definitions[$name]));

        return $name;
    }

    /** @param array<string,mixed> $definition */
    private function isAt(array $definition, Position $position): bool
    {
        return (string) ($definition["World"] ?? "") === $position->getWorld()->getFolderName()
            && (int) ($definition["X"] ?? PHP_INT_MIN) === $position->getFloorX()
            && (int) ($definition["Y"] ?? PHP_INT_MIN) === $position->getFloorY()
            && (int) ($definition["Z"] ?? PHP_INT_MIN) === $position->getFloorZ();
    }
    public function set(string $name, string $setting, mixed $value): bool
    {
        if (!isset($this->definitions[$name])) {
            return false;
        }
        $map = ["mob" => "MobName", "mobname" => "MobName", "interval" => "Interval", "maxmobs" => "MaxMobs", "radius" => "Radius", "level" => "Level", "useworldscaling" => "UseWorldScaling", "enabled" => "Enabled", "x" => "X", "y" => "Y", "z" => "Z", "world" => "World"];
        $key = $map[strtolower($setting)] ?? $setting;
        $this->definitions[$name][$key] = is_numeric($value) ? (float) $value : (in_array(strtolower((string) $value), ["true", "false"], true) ? strtolower((string) $value) === "true" : $value);
        $this->saveRuntime();
        return true;
    }

    private function saveRuntime(): void
    {
        $file = $this->plugin->getDataFolder() . "Spawners" . DIRECTORY_SEPARATOR . "runtime.yml";
        yaml_emit_file($file, $this->definitions, YAML_UTF8_ENCODING, YAML_LN_BREAK);
    }
    public function save(): void
    {
        $this->saveRuntime();
    }
}
