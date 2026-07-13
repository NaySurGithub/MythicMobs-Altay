<?php

declare(strict_types=1);

namespace mythicmobs\spawner;

use mythicmobs\MythicMobs;
use pocketmine\entity\Location;
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
    public function activate(string $name): bool
    {
        if (!isset($this->definitions[$name])) {
            return false;
        }
        $this->lastSpawn[$name] = 0;
        $this->tick();
        return true;
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
