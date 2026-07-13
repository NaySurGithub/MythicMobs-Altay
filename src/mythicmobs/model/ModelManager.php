<?php

declare(strict_types=1);

namespace mythicmobs\model;

use pocketmine\entity\Entity;
use mythicmobs\entity\CustomEntityManager;
use mythicmobs\MythicMobs;
use pocketmine\utils\Config;
use Ramsey\Uuid\Uuid;

final class ModelManager
{
    private const PACK_PREFIX = "MythicMobsModels-";
    /** @var array<string, array<string,mixed>> */
    private array $definitions = [];

    public function __construct(private MythicMobs $plugin)
    {
    }
    /** @return array<string, array<string,mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function reload(bool $buildPack = true): void
    {
        $this->definitions = $this->plugin->loadDefinitions("Models");
        foreach ($this->definitions as $name => $definition) {
            $identifier = strtolower((string) ($definition["Identifier"] ?? ""));
            if ($identifier === "") {
                continue;
            }
            try {
                CustomEntityManager::getInstance()->registerDynamic($identifier, (bool) ($definition["Summonable"] ?? true));
            } catch (\Throwable $e) {
                $this->plugin->getLogger()->error("Model $name: " . $e->getMessage());
            }
        }
        if ($buildPack) {
            $this->buildPack();
        }
    }

    public function buildPack(): bool
    {
        if ($this->definitions === []) {
            return false;
        }
        $contentHash = $this->contentHash();
        $packName = self::PACK_PREFIX . $contentHash . ".mcpack";
        $packPath = $this->plugin->getServer()->getResourcePackManager()->getPath() . $packName;
        if (!is_file($packPath)) {
            $zip = new \ZipArchive();
            if ($zip->open($packPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException("Unable to create $packPath");
            }
            $packUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "mythicmobs-model-pack:" . $contentHash)->toString();
            $moduleUuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, "mythicmobs-model-module:" . $contentHash)->toString();
            $this->addJson($zip, "manifest.json", [
                "format_version" => 2,
                "header" => [
                    "name" => "MythicMobs Generated Models",
                    "description" => "Generated from plugin_data/MythicMobs/Models",
                    "uuid" => $packUuid,
                    "version" => [1, 0, 0],
                    "min_engine_version" => [1, 20, 0],
                ],
                "modules" => [[
                    "type" => "resources",
                    "uuid" => $moduleUuid,
                    "version" => [1, 0, 0],
                ]],
            ]);
            foreach ($this->definitions as $name => $definition) {
                $this->addModel($zip, $name, $definition);
            }
            $zip->close();
        }
        $configPath = $this->plugin->getServer()->getResourcePackManager()->getPath() . "resource_packs.yml";
        $config = new Config($configPath, Config::YAML);
        $stack = $config->get("resource_stack", []);
        if (!is_array($stack)) {
            $stack = [];
        }
        $stack = array_values(array_filter(
            $stack,
            fn ($entry) => !is_string($entry)
                || $entry === $packName
                || (
                    !str_starts_with($entry, self::PACK_PREFIX)
                    && $entry !== "MythicMobsModels.mcpack"
                ),
        ));
        if (!in_array($packName, $stack, true)) {
            $stack[] = $packName;
            $config->set("resource_stack", $stack);
            $config->save();
            $this->plugin->getLogger()->warning("Generated model pack added to resource stack. Restart once before clients can render it.");
        }
        return true;
    }

    private function contentHash(): string
    {
        $context = hash_init("sha256");
        hash_update($context, serialize($this->definitions));
        $base = $this->plugin->getDataFolder() . "Models";
        $files = [];
        if (is_dir($base)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        foreach ($files as $file) {
            hash_update($context, str_replace("\\", "/", substr($file, strlen($base))));
            hash_update_file($context, $file);
        }
        return substr(hash_final($context), 0, 12);
    }

    public function resolveAnimation(Entity $entity, string $name): string
    {
        foreach ($this->definitions as $definition) {
            if (strtolower((string) ($definition["Identifier"] ?? "")) !== strtolower($entity::getNetworkTypeId())) {
                continue;
            }
            $value = $definition["Animations"][$name] ?? null;
            if (is_array($value)) {
                return (string) ($value["Identifier"] ?? $name);
            }
            if (is_string($value)) {
                return $value;
            }
        }
        return $name;
    }

    /** @param array<string,mixed> $definition */
    private function addModel(\ZipArchive $zip, string $name, array $definition): void
    {
        $identifier = strtolower((string) ($definition["Identifier"] ?? ""));
        if ($identifier === "") {
            return;
        }
        $safe = preg_replace('/[^a-z0-9_.-]+/', "_", str_replace(":", ".", $identifier));
        $geometry = $this->assetMap($zip, $definition["Geometry"] ?? ["default" => "geometry.humanoid"], "models/entity", "geo.json");
        $textures = $this->assetMap($zip, $definition["Textures"] ?? ["default" => "textures/entity/skeleton/skeleton"], "textures/entity", "png");
        $animations = $this->namedAssets($zip, $definition["Animations"] ?? [], "animations", "animation.json");
        $controllers = $this->namedAssets($zip, $definition["AnimationControllers"] ?? [], "animation_controllers", "animation_controllers.json");
        $client = ["format_version" => "1.10.0", "minecraft:client_entity" => ["description" => [
            "identifier" => $identifier, "materials" => $definition["Materials"] ?? ["default" => "entity_alphatest"],
            "textures" => $textures, "geometry" => $geometry, "animations" => array_merge($animations, $controllers),
            "scripts" => $definition["Scripts"] ?? ["animate" => array_keys($controllers)],
            "render_controllers" => $definition["RenderControllers"] ?? ["controller.render.default"],
        ]]];
        $this->addJson($zip, "entity/$safe.entity.json", $client);
    }

    /** @return array<string,string> */
    private function assetMap(\ZipArchive $zip, mixed $raw, string $folder, string $extension): array
    {
        $result = [];
        if (!is_array($raw)) {
            return $result;
        }
        foreach ($raw as $alias => $value) {
            if (is_array($value)) {
                $result[(string) $alias] = (string) ($value["Identifier"] ?? "");
                if (isset($value["File"])) {
                    $this->copyAsset($zip, (string) $value["File"], "$folder/" . basename((string) $value["File"]));
                }
            } else {
                $result[(string) $alias] = (string) $value;
            }
        }
        return $result;
    }
    /** @return array<string,string> */
    private function namedAssets(\ZipArchive $zip, mixed $raw, string $folder, string $extension): array
    {
        return $this->assetMap($zip, $raw, $folder, $extension);
    }
    private function copyAsset(\ZipArchive $zip, string $relative, string $destination): void
    {
        $base = realpath($this->plugin->getDataFolder() . "Models");
        $source = realpath($this->plugin->getDataFolder() . "Models" . DIRECTORY_SEPARATOR . $relative);
        if ($base === false || $source === false || !str_starts_with($source, $base . DIRECTORY_SEPARATOR) || !is_file($source)) {
            $this->plugin->getLogger()->warning("Missing/unsafe model asset: $relative");
            return;
        }
        $zip->addFile($source, str_replace("\\", "/", $destination));
    }
    /** @param array<mixed> $data */
    private function addJson(\ZipArchive $zip, string $path, array $data): void
    {
        $zip->addFromString($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
