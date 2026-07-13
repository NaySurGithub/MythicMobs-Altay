<?php

declare(strict_types=1);

namespace mythicmobs\ui;

use mythicmobs\MythicMobs;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

final class ConfigFormManager
{
    /** @var array<string,string> */
    private const DEFINITION_FOLDERS = [
        "Mobs" => "Mobs",
        "Items" => "Items",
        "Skills" => "Skills",
        "Drop Tables" => "DropTables",
        "Spawners" => "Spawners",
        "Models" => "Models",
    ];

    public function __construct(private MythicMobs $plugin)
    {
    }

    public function open(Player $player): void
    {
        $buttons = [
            ["text" => "General Configuration"],
            ["text" => "Mob Configuration"],
        ];
        foreach (array_keys(self::DEFINITION_FOLDERS) as $label) {
            $buttons[] = ["text" => $label];
        }
        $buttons[] = ["text" => "Reload Everything"];
        $player->sendForm(new CallbackForm(
            [
                "type" => "form",
                "title" => "MythicMobs Configuration",
                "content" => "Create and edit all MythicMobs content.",
                "buttons" => $buttons,
            ],
            function (Player $player, mixed $choice): void {
                if (!is_int($choice)) {
                    return;
                }
                if ($choice === 0) {
                    $this->openConfigSections($player, "config-general.yml");
                    return;
                }
                if ($choice === 1) {
                    $this->openConfigSections($player, "config-mobs.yml");
                    return;
                }
                $labels = array_keys(self::DEFINITION_FOLDERS);
                $index = $choice - 2;
                if (isset($labels[$index])) {
                    $this->openDefinitionList(
                        $player,
                        self::DEFINITION_FOLDERS[$labels[$index]],
                        $labels[$index]
                    );
                    return;
                }
                if ($choice === count($labels) + 2) {
                    $this->plugin->reloadMythic();
                    $player->sendMessage(
                        TextFormat::GREEN . "MythicMobs reloaded."
                    );
                }
            }
        ));
    }

    private function openDefinitionList(
        Player $player,
        string $folder,
        string $label
    ): void {
        $definitions = $this->rawDefinitions($folder);
        $names = array_keys($definitions);
        natcasesort($names);
        $names = array_values($names);
        $buttons = [["text" => "+ Create New $label"]];
        foreach ($names as $name) {
            $buttons[] = ["text" => $name];
        }
        $buttons[] = ["text" => "← Back"];
        $player->sendForm(new CallbackForm(
            [
                "type" => "form",
                "title" => $label,
                "content" => count($names) . " configured",
                "buttons" => $buttons,
            ],
            function (Player $player, mixed $choice) use (
                $folder,
                $label,
                $names,
                $definitions
            ): void {
                if (!is_int($choice)) {
                    return;
                }
                if ($choice === 0) {
                    $this->openDefinitionEditor(
                        $player,
                        $folder,
                        $label,
                        "",
                        $this->defaultDefinition($folder, $player)
                    );
                    return;
                }
                if ($choice === count($names) + 1) {
                    $this->open($player);
                    return;
                }
                $name = $names[$choice - 1] ?? null;
                if ($name !== null) {
                    $this->openDefinitionEditor(
                        $player,
                        $folder,
                        $label,
                        $name,
                        $definitions[$name]
                    );
                }
            }
        ));
    }

    /** @param array<string,mixed> $definition */
    private function openDefinitionEditor(
        Player $player,
        string $folder,
        string $label,
        string $originalName,
        array $definition
    ): void {
        $keys = array_keys($definition);
        $content = [
            [
                "type" => "input",
                "text" => "Internal name",
                "placeholder" => "Required",
                "default" => $originalName,
            ],
        ];
        foreach ($keys as $key) {
            $content[] = [
                "type" => "input",
                "text" => (string) $key,
                "default" => $this->encodeValue($definition[$key]),
            ];
        }
        $content[] = [
            "type" => "input",
            "text" => "Add field name",
            "placeholder" => "Example: Options",
            "default" => "",
        ];
        $content[] = [
            "type" => "input",
            "text" => "Add field value",
            "placeholder" => "Text, number, true/false, or JSON",
            "default" => "",
        ];
        $content[] = [
            "type" => "toggle",
            "text" => "Delete this definition",
            "default" => false,
        ];
        $player->sendForm(new CallbackForm(
            [
                "type" => "custom_form",
                "title" => ($originalName === "" ? "Create " : "Edit ") . $label,
                "content" => $content,
            ],
            function (Player $player, mixed $response) use (
                $folder,
                $label,
                $originalName,
                $definition,
                $keys
            ): void {
                if (!is_array($response)) {
                    return;
                }
                $name = trim((string) ($response[0] ?? ""));
                if ($name === "") {
                    $player->sendMessage(TextFormat::RED . "Internal name is required.");
                    return;
                }
                $offset = 1;
                $updated = $definition;
                foreach ($keys as $key) {
                    $raw = (string) ($response[$offset++] ?? "");
                    if ($raw === "") {
                        unset($updated[$key]);
                        continue;
                    }
                    $updated[$key] = $this->decodeValue($raw);
                }
                $newField = trim((string) ($response[$offset++] ?? ""));
                $newValue = (string) ($response[$offset++] ?? "");
                $delete = (bool) ($response[$offset] ?? false);
                if ($newField !== "") {
                    $updated[$newField] = $this->decodeValue($newValue);
                }
                if ($delete) {
                    if ($originalName !== "") {
                        $this->deleteDefinition($folder, $originalName);
                    }
                    $message = "Deleted $originalName.";
                } else {
                    $this->saveDefinition(
                        $folder,
                        $originalName,
                        $name,
                        $updated
                    );
                    $message = "Saved $name.";
                }
                $this->plugin->reloadMythic();
                $player->sendMessage(TextFormat::GREEN . $message);
                $this->openDefinitionList($player, $folder, $label);
            }
        ));
    }

    private function openConfigSections(Player $player, string $file): void
    {
        $path = $this->plugin->getDataFolder() . $file;
        $data = yaml_parse_file($path);
        $configuration = is_array($data["Configuration"] ?? null)
            ? $data["Configuration"]
            : [];
        $sections = array_keys($configuration);
        $buttons = [];
        foreach ($sections as $section) {
            $buttons[] = ["text" => (string) $section];
        }
        $buttons[] = ["text" => "← Back"];
        $player->sendForm(new CallbackForm(
            [
                "type" => "form",
                "title" => $file,
                "content" => "Choose a configuration section.",
                "buttons" => $buttons,
            ],
            function (Player $player, mixed $choice) use (
                $file,
                $configuration,
                $sections
            ): void {
                if (!is_int($choice)) {
                    return;
                }
                if ($choice === count($sections)) {
                    $this->open($player);
                    return;
                }
                $section = $sections[$choice] ?? null;
                if ($section !== null) {
                    $this->openConfigEditor(
                        $player,
                        $file,
                        (string) $section,
                        (array) $configuration[$section]
                    );
                }
            }
        ));
    }

    /** @param array<string,mixed> $values */
    private function openConfigEditor(
        Player $player,
        string $file,
        string $section,
        array $values
    ): void {
        $keys = array_keys($values);
        $content = [];
        foreach ($keys as $key) {
            $content[] = [
                "type" => "input",
                "text" => (string) $key,
                "default" => $this->encodeValue($values[$key]),
            ];
        }
        $content[] = [
            "type" => "input",
            "text" => "Add setting name",
            "default" => "",
        ];
        $content[] = [
            "type" => "input",
            "text" => "Add setting value",
            "default" => "",
        ];
        $player->sendForm(new CallbackForm(
            [
                "type" => "custom_form",
                "title" => $section,
                "content" => $content,
            ],
            function (Player $player, mixed $response) use (
                $file,
                $section,
                $values,
                $keys
            ): void {
                if (!is_array($response)) {
                    return;
                }
                $updated = $values;
                $offset = 0;
                foreach ($keys as $key) {
                    $raw = (string) ($response[$offset++] ?? "");
                    if ($raw !== "") {
                        $updated[$key] = $this->decodeValue($raw);
                    }
                }
                $newKey = trim((string) ($response[$offset++] ?? ""));
                $newValue = (string) ($response[$offset] ?? "");
                if ($newKey !== "") {
                    $updated[$newKey] = $this->decodeValue($newValue);
                }
                $path = $this->plugin->getDataFolder() . $file;
                $data = yaml_parse_file($path);
                $data = is_array($data) ? $data : [];
                $data["Configuration"] = is_array(
                    $data["Configuration"] ?? null
                ) ? $data["Configuration"] : [];
                $data["Configuration"][$section] = $updated;
                yaml_emit_file($path, $data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
                $this->plugin->reloadMythic();
                $player->sendMessage(TextFormat::GREEN . "Configuration saved.");
                $this->openConfigSections($player, $file);
            }
        ));
    }

    /** @return array<string,array<string,mixed>> */
    private function rawDefinitions(string $folder): array
    {
        $result = [];
        foreach ($this->definitionFiles($folder) as $file) {
            $data = yaml_parse_file($file);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $name => $definition) {
                if (is_string($name) && is_array($definition)) {
                    $result[$name] = $definition;
                }
            }
        }
        return $result;
    }

    /** @return list<string> */
    private function definitionFiles(string $folder): array
    {
        return array_values(glob(
            $this->plugin->getDataFolder()
            . $folder
            . DIRECTORY_SEPARATOR
            . "*.yml"
        ) ?: []);
    }

    /** @param array<string,mixed> $definition */
    private function saveDefinition(
        string $folder,
        string $originalName,
        string $name,
        array $definition
    ): void {
        $target = $this->plugin->getDataFolder()
            . $folder
            . DIRECTORY_SEPARATOR
            . "form-created.yml";
        foreach ($this->definitionFiles($folder) as $file) {
            $data = yaml_parse_file($file);
            if (is_array($data) && isset($data[$originalName])) {
                $target = $file;
                break;
            }
        }
        $data = is_file($target) ? yaml_parse_file($target) : [];
        $data = is_array($data) ? $data : [];
        if ($originalName !== "" && $originalName !== $name) {
            unset($data[$originalName]);
        }
        $data[$name] = $definition;
        yaml_emit_file($target, $data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
    }

    private function deleteDefinition(string $folder, string $name): void
    {
        foreach ($this->definitionFiles($folder) as $file) {
            $data = yaml_parse_file($file);
            if (!is_array($data) || !array_key_exists($name, $data)) {
                continue;
            }
            unset($data[$name]);
            yaml_emit_file($file, $data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
        }
    }

    /** @return array<string,mixed> */
    private function defaultDefinition(
        string $folder,
        Player $player
    ): array
    {
        return match ($folder) {
            "Mobs" => [
                "Type" => "zombie",
                "Display" => "&cNew Mob",
                "Health" => 20,
                "Damage" => 2,
                "Options" => ["MovementSpeed" => 0.2],
                "Skills" => [],
                "Drops" => [],
            ],
            "Items" => ["Material" => "stone", "Display" => "&fNew Item", "Skills" => []],
            "Skills" => ["Cooldown" => 0, "Skills" => []],
            "DropTables" => ["Drops" => []],
            "Spawners" => [
                "MobName" => "",
                "World" => $player->getWorld()->getFolderName(),
                "X" => $player->getPosition()->getFloorX(),
                "Y" => $player->getPosition()->getFloorY(),
                "Z" => $player->getPosition()->getFloorZ(),
                "Radius" => 2,
                "Interval" => 30,
                "MaxMobs" => 1,
                "Enabled" => true,
            ],
            "Models" => ["Identifier" => "mythicmobs:new_model"],
            default => [],
        };
    }

    private function encodeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? "true" : "false";
        }
        if (is_array($value)) {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) ?: "[]";
        }
        return (string) $value;
    }

    private function decodeValue(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === "true" || $trimmed === "false") {
            return $trimmed === "true";
        }
        if ($trimmed === "null") {
            return null;
        }
        if (is_numeric($trimmed)) {
            return str_contains($trimmed, ".")
                ? (float) $trimmed
                : (int) $trimmed;
        }
        if (
            (str_starts_with($trimmed, "[") && str_ends_with($trimmed, "]")) ||
            (str_starts_with($trimmed, "{") && str_ends_with($trimmed, "}"))
        ) {
            try {
                return json_decode(
                    $trimmed,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
            }
        }
        return $value;
    }
}
