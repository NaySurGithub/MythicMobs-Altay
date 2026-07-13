<?php

declare(strict_types=1);

namespace mythicmobs\drop;

use mythicmobs\MythicMobs;
use pocketmine\item\Item;
use pocketmine\player\Player;

final class DropManager
{
    /** @var array<string,array<string,mixed>> */
    private array $tables = [];

    public function __construct(private MythicMobs $plugin)
    {
    }

    public function reload(): void
    {
        $this->tables = $this->plugin->loadDefinitions("DropTables");
    }

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        return $this->tables;
    }

    /**
     * @param list<mixed> $entries
     * @return list<Item>
     */
    public function roll(
        array $entries,
        int $level,
        ?Player $player = null,
        float $damagePercent = 0.0,
    ): array {
        return $this->rollEntries(
            $entries,
            $level,
            $player,
            $damagePercent,
            [],
            0,
        );
    }

    /**
     * @param list<mixed> $entries
     * @param array<string,true> $stack
     * @return list<Item>
     */
    private function rollEntries(
        array $entries,
        int $level,
        ?Player $player,
        float $damagePercent,
        array $stack,
        int $depth,
    ): array {
        if ($depth > 16) {
            $this->plugin->getLogger()->warning("Drop-table nesting exceeded 16 levels.");
            return [];
        }

        $drops = [];
        foreach ($entries as $rawEntry) {
            $entry = $this->normalizeEntry($rawEntry);
            if ($entry === null || !$this->passesConditions($entry, $level, $player, $damagePercent)) {
                continue;
            }

            $chance = $this->normalizeChance((float) ($entry["Chance"] ?? 1.0));
            if ($this->randomFloat() > $chance) {
                continue;
            }

            $drops = [
                ...$drops,
                ...$this->resolveEntry(
                    $entry,
                    $level,
                    $player,
                    $damagePercent,
                    $stack,
                    $depth,
                ),
            ];
        }

        return $drops;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,true> $stack
     * @return list<Item>
     */
    private function resolveEntry(
        array $entry,
        int $level,
        ?Player $player,
        float $damagePercent,
        array $stack,
        int $depth,
    ): array {
        $tableName = (string) ($entry["Table"] ?? $entry["DropTable"] ?? "");
        if ($tableName !== "") {
            return $this->rollTable(
                $tableName,
                max(1, (int) ($entry["Rolls"] ?? 1)),
                $level,
                $player,
                $damagePercent,
                $stack,
                $depth + 1,
            );
        }

        $itemName = (string) ($entry["Item"] ?? $entry["Id"] ?? $entry["Material"] ?? "");
        if ($itemName === "") {
            return [];
        }

        $amount = $this->rollAmount($entry["Amount"] ?? 1);
        $bonus = max(0.0, (float) ($entry["BonusLevelItems"] ?? 0));
        $amount += (int) floor(max(0, $level - 1) * $bonus);
        if ($amount <= 0) {
            return [];
        }

        $item = $this->plugin->makeItem($itemName, $amount);
        return $item === null ? [] : [$item];
    }

    /**
     * @param array<string,true> $stack
     * @return list<Item>
     */
    private function rollTable(
        string $requestedName,
        int $rollMultiplier,
        int $level,
        ?Player $player,
        float $damagePercent,
        array $stack,
        int $depth,
    ): array {
        $name = $this->findTable($requestedName);
        if ($name === null) {
            $this->plugin->getLogger()->warning("Unknown drop table '$requestedName'.");
            return [];
        }
        if (isset($stack[strtolower($name)])) {
            $this->plugin->getLogger()->warning("Circular drop table reference involving '$name'.");
            return [];
        }

        $stack[strtolower($name)] = true;
        $table = $this->tables[$name];
        if (!$this->passesConditions($table, $level, $player, $damagePercent)) {
            return [];
        }

        $entries = array_values((array) ($table["Drops"] ?? $table["Items"] ?? []));
        $configuredRolls = (int) ($table["Rolls"] ?? 0);
        if ($configuredRolls <= 0 && isset($table["MinItems"], $table["MaxItems"])) {
            $minimum = max(0, (int) $table["MinItems"]);
            $maximum = max($minimum, (int) $table["MaxItems"]);
            $configuredRolls = mt_rand($minimum, $maximum);
        }

        if ($configuredRolls <= 0) {
            $result = [];
            for ($roll = 0; $roll < $rollMultiplier; ++$roll) {
                $result = [
                    ...$result,
                    ...$this->rollEntries($entries, $level, $player, $damagePercent, $stack, $depth),
                ];
            }
            return $result;
        }

        $result = [];
        $rolls = min(256, $configuredRolls * $rollMultiplier);
        for ($roll = 0; $roll < $rolls; ++$roll) {
            $selected = $this->weightedEntry($entries);
            if ($selected === null) {
                break;
            }
            $result = [
                ...$result,
                ...$this->rollEntries([$selected], $level, $player, $damagePercent, $stack, $depth),
            ];
        }

        return $result;
    }

    /** @param list<mixed> $entries */
    private function weightedEntry(array $entries): mixed
    {
        $weighted = [];
        $total = 0.0;
        foreach ($entries as $entry) {
            $normalized = $this->normalizeEntry($entry);
            if ($normalized === null) {
                continue;
            }
            $weight = max(0.0, (float) ($normalized["Weight"] ?? 1.0));
            if ($weight <= 0.0) {
                continue;
            }
            $total += $weight;
            $weighted[] = [$entry, $total];
        }

        if ($weighted === [] || $total <= 0.0) {
            return null;
        }

        $needle = $this->randomFloat() * $total;
        foreach ($weighted as [$entry, $ceiling]) {
            if ($needle <= $ceiling) {
                return $entry;
            }
        }

        return $weighted[array_key_last($weighted)][0];
    }

    /** @return array<string,mixed>|null */
    private function normalizeEntry(mixed $rawEntry): ?array
    {
        if (is_array($rawEntry)) {
            return $rawEntry;
        }
        if (!is_string($rawEntry) || trim($rawEntry) === "") {
            return null;
        }

        $text = trim($rawEntry);
        $tableName = $this->findTable($text);
        if ($tableName !== null) {
            return ["Table" => $tableName];
        }
        if (preg_match('/^(?:table|droptable)[:\s]+([^\s]+)(?:\s+(\d+))?$/i', $text, $match)) {
            return [
                "Table" => $match[1],
                "Rolls" => isset($match[2]) ? (int) $match[2] : 1,
            ];
        }

        $parts = preg_split('/\s+/', $text) ?: [];
        return [
            "Item" => (string) ($parts[0] ?? ""),
            "Amount" => $parts[1] ?? 1,
            "Chance" => isset($parts[2]) ? (float) $parts[2] : 1.0,
        ];
    }

    /** @param array<string,mixed> $definition */
    private function passesConditions(
        array $definition,
        int $level,
        ?Player $player,
        float $damagePercent,
    ): bool {
        if ($level < (int) ($definition["MinLevel"] ?? 1)) {
            return false;
        }
        if (isset($definition["MaxLevel"]) && $level > (int) $definition["MaxLevel"]) {
            return false;
        }
        if (isset($definition["Permission"])) {
            if ($player === null || !$player->hasPermission((string) $definition["Permission"])) {
                return false;
            }
        }

        $minimumDamage = (float) ($definition["MinimumDamagePercent"] ?? 0.0);
        if ($minimumDamage > 1.0) {
            $minimumDamage /= 100.0;
        }
        if ($damagePercent < max(0.0, $minimumDamage)) {
            return false;
        }

        return true;
    }

    private function rollAmount(mixed $rawAmount): int
    {
        if (is_int($rawAmount) || is_float($rawAmount)) {
            return max(0, (int) $rawAmount);
        }

        $text = strtolower(trim((string) $rawAmount));
        if (preg_match('/^(\d+)\s*(?:-|to)\s*(\d+)$/', $text, $match)) {
            $minimum = min((int) $match[1], (int) $match[2]);
            $maximum = max((int) $match[1], (int) $match[2]);
            return mt_rand($minimum, $maximum);
        }

        return max(0, (int) $text);
    }

    private function normalizeChance(float $chance): float
    {
        if ($chance > 1.0) {
            $chance /= 100.0;
        }
        return max(0.0, min(1.0, $chance));
    }

    private function findTable(string $requestedName): ?string
    {
        foreach (array_keys($this->tables) as $name) {
            if (strcasecmp($name, trim($requestedName)) === 0) {
                return $name;
            }
        }
        return null;
    }

    private function randomFloat(): float
    {
        return mt_rand() / mt_getrandmax();
    }
}
