<?php

declare(strict_types=1);

namespace mythicmobs\skill;

use mythicmobs\MythicMobs;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\math\Vector3;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\CriticalParticle;
use pocketmine\world\particle\ExplodeParticle;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\particle\SmokeParticle;
use pocketmine\world\sound\BlazeShootSound;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\network\mcpe\protocol\AnimateEntityPacket;

final class SkillEngine
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];
    /** @var array<string, float> */
    private array $cooldowns = [];
    /** @var array<string,true> */
    private array $oneShot = [];
    /** @var array<string,true> */
    private array $unknownComponents = [];
    private int $ticks = 0;

    public function __construct(private MythicMobs $plugin)
    {
    }
    public function reload(): void
    {
        $this->definitions = $this->plugin->loadDefinitions("Skills");
        $this->cooldowns = [];
        $this->oneShot = [];
        $this->unknownComponents = [];
    }
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function triggerItem(Player $caster, Item $item, string $trigger, ?Entity $triggerEntity = null): void
    {
        foreach ($this->plugin->itemSkills($item) as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === null || strcasecmp((string) ($parsed["trigger"] ?? ""), $trigger) !== 0 || !$this->passesLine($parsed, $caster, $triggerEntity)) {
                continue;
            }
            $inherited = $triggerEntity !== null ? [$triggerEntity] : [$caster];
            $this->executeParsed($parsed, $caster, $inherited, $triggerEntity ?? $caster, new SkillContext());
        }
    }

    public function trigger(Entity $caster, string $trigger, ?Entity $triggerEntity, ?string $triggerArgument = null): void
    {
        $data = $this->plugin->getMobManager()->data($caster);
        if ($data === null) {
            return;
        }
        foreach ((array) ($data["definition"]["Skills"] ?? []) as $line) {
            $parsed = $this->parseLine((string) $line);
            if ($parsed === null || strcasecmp((string) ($parsed["trigger"] ?? ""), $trigger) !== 0 || (($parsed["triggerArgument"] ?? null) !== null && strcasecmp((string)$parsed["triggerArgument"], (string)$triggerArgument) !== 0) || !$this->passesLine($parsed, $caster, $triggerEntity)) {
                continue;
            }
            $this->executeParsed($parsed, $caster, $triggerEntity !== null ? [$triggerEntity] : [], $triggerEntity, new SkillContext());
        }
    }

    public function tickTimers(): void
    {
        $this->ticks += max(1, (int) $this->plugin->general("Configuration.Clock.Main", 1));
        foreach ($this->plugin->getMobManager()->activeEntities() as $caster) {
            $data = $this->plugin->getMobManager()->data($caster);
            foreach ((array) ($data["definition"]["Skills"] ?? []) as $line) {
                $parsed = $this->parseLine((string) $line);
                $interval = (int) ($parsed["timer"] ?? 0);
                $target = $caster->getTargetEntity();
                if ($parsed !== null && strcasecmp((string) ($parsed["trigger"] ?? ""), "onTimer") === 0 && $interval > 0 && $this->ticks % $interval === 0 && $this->passesLine($parsed, $caster, $target)) {
                    $this->executeParsed($parsed, $caster, $target !== null ? [$target] : [], $target, new SkillContext());
                }
            }
        }
    }

    public function cast(string $key, Entity $caster, ?Entity $target = null): bool
    {
        return $this->castWithTargets($key, $caster, $target !== null ? [$target] : [], $target, new SkillContext());
    }

    /** @param list<Entity> $targets */
    private function castWithTargets(string $key, Entity $caster, array $targets, ?Entity $trigger, SkillContext $context, bool $ignoreControls = false): bool
    {
        $resolvedKey = isset($this->definitions[$key]) ? $key : null;
        if ($resolvedKey === null) {
            foreach (array_keys($this->definitions) as $candidate) {
                if (strcasecmp($candidate, $key) === 0) {
                    $resolvedKey = $candidate;
                    break;
                }
            }
        }
        $definition = $resolvedKey !== null ? $this->definitions[$resolvedKey] : null;
        if ($definition === null) {
            return false;
        }
        $cooldownKey = $caster->getId() . ":" . strtolower($resolvedKey);
        if (!$ignoreControls && ($this->cooldowns[$cooldownKey] ?? 0) > microtime(true)) {
            $this->executeFallback($definition["OnCooldownSkill"] ?? $definition["OnCooldownSkills"] ?? null, $caster, $targets, $trigger, $context);
            return false;
        }
        $targets = array_values(array_filter($targets, fn ($entity) => $entity instanceof Entity && !$entity->isClosed()));
        if (!$ignoreControls) {
            $triggerConditions = (array) ($definition["TriggerConditions"] ?? []);
            $conditionsPass = $this->passesMetaConditions((array) ($definition["Conditions"] ?? []), $caster, $caster)
                && ($triggerConditions === [] || ($trigger !== null && $this->passesMetaConditions($triggerConditions, $caster, $trigger)));
            $targetConditions = (array) ($definition["TargetConditions"] ?? []);
            $hadTargets = $targets !== [];
            $targets = array_values(array_filter($targets, fn (Entity $target) => $this->passesMetaConditions($targetConditions, $caster, $target)));
            if (!$conditionsPass) {
                $this->executeFallback($definition["FailedConditionsSkill"] ?? $definition["OnFailSkill"] ?? null, $caster, $targets, $trigger, $context);
                return false;
            }
            if ($targetConditions !== [] && $hadTargets && $targets === []) {
                $this->executeFallback($definition["FailedConditionsSkill"] ?? $definition["OnFailSkill"] ?? null, $caster, $targets, $trigger, $context);
                return false;
            }
            if ((bool) ($definition["CancelIfNoTargets"] ?? true) && $targets === []) {
                return false;
            }
            $this->cooldowns[$cooldownKey] = microtime(true) + max(0.0, (float) ($definition["Cooldown"] ?? 0));
        }
        if (isset($definition["Skill"])) {
            foreach ($this->templateNames($definition["Skill"]) as $parent) {
                $this->castWithTargets($parent, $caster, $targets, $trigger, $context, true);
            }
        }
        $this->executeSequence(array_values((array) ($definition["Skills"] ?? [])), 0, $caster, $targets, $trigger, $context);
        return true;
    }

    private function executeLine(string $line, Entity $caster, ?Entity $target): void
    {
        $this->executeSequence([$line], 0, $caster, $target !== null ? [$target] : [], $target, new SkillContext());
    }

    /** @param list<mixed> $lines @param list<Entity> $inheritedTargets */
    private function executeSequence(array $lines, int $index, Entity $caster, array $inheritedTargets, ?Entity $trigger, SkillContext $context): void
    {
        for ($i = $index, $count = count($lines); $i < $count; ++$i) {
            if ($caster->isClosed()) {
                return;
            }
            $line = $this->replaceSkillParameters((string) $lines[$i], $context, $caster);
            $parsed = $this->parseLine($line);
            if ($parsed === null || !$this->passesLine($parsed, $caster, $trigger)) {
                continue;
            }
            if (strcasecmp((string) $parsed["mechanic"], "delay") === 0) {
                $ticks = max(1, (int) ($parsed["params"]["ticks"] ?? $parsed["params"]["t"] ?? $parsed["argument"] ?? 1));
                $next = $i + 1;
                $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(fn () => $this->executeSequence($lines, $next, $caster, $inheritedTargets, $trigger, $context)), $ticks);
                return;
            }
            $this->executeParsed($parsed, $caster, $inheritedTargets, $trigger, $context);
        }
    }

    /** @param array<string,mixed> $parsed @param list<Entity> $inheritedTargets */
    private function executeParsed(array $parsed, Entity $caster, array $inheritedTargets, ?Entity $trigger, SkillContext $context): void
    {
        $mechanic = strtolower((string) $parsed["mechanic"]);
        $params = $parsed["params"];
        $delay = max(0, (int)($params["delay"] ?? 0));
        if ($delay > 0) {
            unset($parsed["params"]["delay"]);
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($caster, $parsed, $inheritedTargets, $trigger, $context): void {
                if (!$caster->isClosed()) {
                    $this->executeParsed($parsed, $caster, $inheritedTargets, $trigger, $context);
                }
            }), $delay);
            return;
        }
        $repeat = max(0, min(100, (int)($params["repeat"] ?? 0)));
        if ($repeat > 0) {
            $interval = max(1, (int)($params["repeatinterval"] ?? $params["repeati"] ?? 1));
            unset($parsed["params"]["repeat"],$parsed["params"]["repeatinterval"],$parsed["params"]["repeati"]);
            $this->executeParsed($parsed, $caster, $inheritedTargets, $trigger, $context);
            for ($i = 1; $i <= $repeat; ++$i) {
                $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($caster, $parsed, $inheritedTargets, $trigger, $context): void {
                    if (!$caster->isClosed()) {
                        $this->executeParsed($parsed, $caster, $inheritedTargets, $trigger, $context);
                    }
                }), $interval * $i);
            }
            return;
        }
        if (str_starts_with($mechanic, "skill:") && !isset($params["s"], $params["skill"])) {
            $params["s"] = substr($mechanic, 6);
        }
        $isLineTargeter = str_starts_with(strtolower((string) $parsed["targeter"]), "@line");
        $targets = (bool) $parsed["explicitTargeter"] ? ($isLineTargeter ? $inheritedTargets : $this->targets((string) $parsed["targeter"], $caster, $trigger)) : $inheritedTargets;
        if (in_array($mechanic, ["skill", "s", "metaskill", "meta", "variableskill"], true)) {
            $key = (string) ($params["s"] ?? $params["skill"] ?? "");
            $this->applySkillParameters($context, $params);
            if (str_starts_with(trim($key), "[")) {
                $this->executeSequence($this->inlineSkills($key), 0, $caster, $targets, $trigger, $context);
                return;
            }
            $this->castWithTargets($key, $caster, $targets, $trigger, $context);
            return;
        }
        if (str_starts_with($mechanic, "skill:")) {
            $this->applySkillParameters($context, $params);
            $this->castWithTargets((string) ($params["s"] ?? ""), $caster, $targets, $trigger, $context);
            return;
        }
        if ($mechanic === "setvariable") {
            $name = strtolower((string) ($params["var"] ?? $params["variable"] ?? ""));
            $name = preg_replace('/^(?:skill\.)?(?:var\.)?/i', '', $name) ?? $name;
            $subject = $targets[0] ?? $trigger ?? $caster;
            $value = $this->replace((string) ($params["value"] ?? $params["val"] ?? $params["v"] ?? ""), $caster, $subject);
            if ($name !== "") {
                $context->variables[$name] = $value;
            }
            return;
        }
        if ($mechanic === "setskillcooldown") {
            $key = strtolower((string) ($params["skill"] ?? $params["s"] ?? ""));
            $seconds = max(0.0, (float) ($params["seconds"] ?? $params["duration"] ?? $params["d"] ?? $params["cooldown"] ?? $params["cd"] ?? 0));
            if ($key !== "") {
                $this->cooldowns[$caster->getId() . ":" . $key] = microtime(true) + $seconds;
            }
            return;
        }
        if ($isLineTargeter && in_array($mechanic, ["particles", "effect:particles"], true)) {
            foreach ($targets as $resolved) {
                $this->lineParticles($caster, $resolved, $params, (string) $parsed["targeter"]);
            }
            return;
        }
        if ($targets === [] && in_array($mechanic, ["command", "particles", "effect:particles", "sound", "effect:sound", "animation", "animate"], true)) {
            $targets = [$caster];
        }
        foreach ($targets as $resolved) {
            $this->apply($mechanic, $params, $caster, $resolved);
        }
    }

    /** @return list<string> */
    private function inlineSkills(string $value): array
    {
        $text = trim($value);
        if (str_starts_with($text, "[") && str_ends_with($text, "]")) {
            $text = substr($text, 1, -1);
        }
        $text = str_replace(["<#>-", "<&nm>-"], ["- <#>", "- <&nm>"], $text);
        $result = [];
        $start = null;
        $curly = 0;
        $square = 0;
        $quote = null;
        $escaped = false;
        for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === "\\" && $quote !== null) {
                $escaped = true;
                continue;
            }
            if (($char === '"' || $char === "'") && ($quote === null || $quote === $char)) {
                $quote = $quote === null ? $char : null;
                continue;
            }
            if ($quote !== null) {
                continue;
            }
            if ($char === "{") {
                ++$curly;
            } elseif ($char === "}") {
                --$curly;
            } elseif ($char === "[") {
                ++$square;
            } elseif ($char === "]") {
                --$square;
            }
            if ($char === "-" && $curly === 0 && $square === 0 && ($i === 0 || ctype_space($text[$i - 1]))) {
                if ($start !== null && ($entry = trim(substr($text, $start, $i - $start))) !== "") {
                    $result[] = $entry;
                }
                $start = $i + 1;
            }
        }
        if ($start !== null && ($entry = trim(substr($text, $start))) !== "") {
            $result[] = $entry;
        }
        return $result;
    }

    /** @param list<Entity> $targets */
    private function executeFallback(mixed $fallback, Entity $caster, array $targets, ?Entity $trigger, SkillContext $context): void
    {
        if ($fallback === null || $fallback === "") {
            return;
        }
        if ($targets === []) {
            $targets = [$caster];
        }
        if (is_string($fallback)) {
            if ($this->hasDefinition($fallback)) {
                $this->castWithTargets($fallback, $caster, $targets, $trigger, $context);
            } else {
                $this->executeSequence([$fallback], 0, $caster, $targets, $trigger, $context);
            }
            return;
        }
        if (is_array($fallback)) {
            $this->executeSequence(array_values($fallback), 0, $caster, $targets, $trigger, $context);
        }
    }

    private function hasDefinition(string $key): bool
    {
        foreach (array_keys($this->definitions) as $candidate) {
            if (strcasecmp($candidate, trim($key)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function templateNames(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
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

    /** @param array<string,string> $incoming */
    private function applySkillParameters(SkillContext $context, array $incoming): void
    {
        $reserved = ["skill", "s", "meta", "m", "mechanics", "cooldown", "cd", "delay", "repeat", "targetinterval", "targeti", "repeatinterval", "repeati", "power", "powersplitbetweentargets", "powersplit", "splitpower", "forcesync", "sync", "targetisorigin", "sourceisorigin", "castfromorigin", "fromorigin", "fo", "origin", "branch", "fork", "snapshotcasterstats", "snapshotstats", "scs", "snapshottriggerstats", "sts", "targetcreative"];
        foreach ($incoming as $name => $value) {
            if (!in_array(strtolower($name), $reserved, true)) {
                $context->parameters[strtolower($name)] = $value;
            }
        }
    }

    private function replaceSkillParameters(string $line, SkillContext $context, Entity $caster): string
    {
        foreach ($context->parameters as $name => $value) {
            $line = str_ireplace("<skill.$name>", $value, $line);
        }
        foreach ($context->variables as $name => $value) {
            $line = str_ireplace("<skill.var.$name>", $value, $line);
        }
        $line = preg_replace_callback('/<caster\.skill\.([^.>]+)\.cooldown>/i', function (array $match) use ($caster): string {
            $remaining = ($this->cooldowns[$caster->getId() . ":" . strtolower($match[1])] ?? 0) - microtime(true);
            return (string) max(0.0, round($remaining, 2));
        }, $line) ?? $line;
        return $line;
    }

    /** @param list<mixed> $conditions */
    private function passesMetaConditions(array $conditions, Entity $caster, Entity $subject): bool
    {
        foreach ($conditions as $raw) {
            $line = trim((string) $raw);
            if ($line === "") {
                continue;
            }
            $expected = true;
            if (preg_match('/\s+(true|false)\s*$/i', $line, $match)) {
                $expected = strcasecmp($match[1], "true") === 0;
                $line = trim(substr($line, 0, -strlen($match[0])));
            }
            if ($this->evaluateMetaCondition($line, $caster, $subject) !== $expected) {
                return false;
            }
        }
        return true;
    }

    private function evaluateMetaCondition(string $line, Entity $caster, Entity $subject): bool
    {
        if (!preg_match('/^([A-Za-z]+)(?:\{([^}]*)\})?/', $line, $match)) {
            return false;
        }
        $name = strtolower($match[1]);
        $params = $this->parameters($match[2] ?? "");
        $time = $subject->getWorld()->getTimeOfDay();
        if ($name === "day") {
            return $time < \pocketmine\world\World::TIME_NIGHT || $time >= \pocketmine\world\World::TIME_SUNRISE;
        }
        if ($name === "night") {
            return $time >= \pocketmine\world\World::TIME_NIGHT && $time < \pocketmine\world\World::TIME_SUNRISE;
        }
        if ($name === "distance") {
            if ($caster->getWorld() !== $subject->getWorld()) {
                return false;
            }
            return $this->compareNumber(sqrt($caster->getPosition()->distanceSquared($subject->getPosition())), (string) ($params["distance"] ?? $params["d"] ?? "0"));
        }
        if (in_array($name, ["health", "healthpercent"], true) && $subject instanceof Living) {
            $value = $name === "healthpercent" ? $subject->getHealth() / max(1, $subject->getMaxHealth()) * 100 : $subject->getHealth();
            return $this->compareNumber($value, (string) ($params["health"] ?? $params["h"] ?? $params["amount"] ?? $params["a"] ?? "0"));
        }
        if ($name === "skilloncooldown") {
            $key = strtolower((string) ($params["skill"] ?? $params["s"] ?? ""));
            return ($this->cooldowns[$caster->getId() . ":" . $key] ?? 0) > microtime(true);
        }
        return $this->evaluateCondition($name, $params, $caster, $subject);
    }

    private function compareNumber(float $actual, string $expression): bool
    {
        $expression = trim(str_replace("%", "", $expression));
        if (preg_match('/^(<=|>=|<|>|=)?\s*(-?\d+(?:\.\d+)?)$/', $expression, $match)) {
            $value = (float) $match[2];
            return match($match[1] ?: "=") {
                "<" => $actual < $value, ">" => $actual > $value, "<=" => $actual <= $value, ">=" => $actual >= $value, default => abs($actual - $value) < 0.00001,
            };
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function parseLine(string $line): ?array
    {
        $raw = trim($line);
        if ($raw === "" || str_starts_with($raw, "#") || str_starts_with($raw, "<#>") || str_starts_with($raw, "<&nm>")) {
            return null;
        }
        $parts = $this->mechanicParts($raw);
        if ($parts === null) {
            return null;
        } [$mechanic, $parameterText, $work] = $parts;
        $chance = 1.0;
        $health = null;
        $trigger = null;
        $triggerArgument = null;
        $timer = null;
        $targeter = "@target";
        $explicitTargeter = false;
        $conditions = [];
        if (preg_match_all('/\?([A-Za-z]+)(?:\{([^}]*)\})?/', $work, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $conditions[] = ["name" => strtolower($match[1]), "params" => $this->parameters($match[2] ?? "")];
                $work = str_replace($match[0], "", $work);
            }
        }
        if (preg_match('/(?:^|\s)(0(?:\.\d+)?|1(?:\.0+)?)\s*$/', $work, $match)) {
            $chance = (float) $match[1];
            $work = trim(substr($work, 0, -strlen($match[0])));
        }
        if (preg_match('/(?:^|\s)([=<>]\d+(?:\.\d+)?%?(?:-\d+(?:\.\d+)?%?)?)(?=\s|$)/', $work, $match)) {
            $health = $match[1];
            $work = trim(str_replace($match[0], " ", $work));
        }
        if (preg_match('/~([A-Za-z]+)(?::([A-Za-z0-9_.-]+))?/i', $work, $match)) {
            $trigger = $match[1];
            $triggerArgument = isset($match[2]) ? $match[2] : null;
            $timer = strcasecmp($trigger, "onTimer") === 0 && isset($match[2]) ? max(1, (int) $match[2]) : null;
            $work = trim(str_replace($match[0], "", $work));
        }
        if (preg_match('/(@[A-Za-z]+(?:\{[^}]*\})?)/', $work, $match)) {
            $targeter = $match[1];
            $explicitTargeter = true;
            $work = trim(str_replace($match[0], "", $work));
        }
        return ["raw" => $raw, "mechanic" => $mechanic, "params" => $this->parameters($parameterText), "argument" => trim($work), "targeter" => $targeter, "explicitTargeter" => $explicitTargeter, "trigger" => $trigger, "triggerArgument" => $triggerArgument, "timer" => $timer, "health" => $health, "chance" => max(0.0, min(1.0, $chance)), "conditions" => $conditions];
    }

    /** @return array{string,string,string}|null */
    private function mechanicParts(string $line): ?array
    {
        if (!preg_match('/^([A-Za-z][A-Za-z0-9_:.-]*)/', $line, $match)) {
            return null;
        }
        $name = $match[1];
        $offset = strlen($name);
        while (isset($line[$offset]) && ctype_space($line[$offset])) {
            ++$offset;
        }
        if (($line[$offset] ?? "") !== "{") {
            return [$name, "", trim(substr($line, $offset))];
        }
        $end = $this->matchingDelimiter($line, $offset, "{", "}");
        if ($end === null) {
            return null;
        }
        return [$name, substr($line, $offset + 1, $end - $offset - 1), trim(substr($line, $end + 1))];
    }

    private function matchingDelimiter(string $text, int $start, string $open, string $close): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($i = $start, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === "\\" && $quote !== null) {
                $escaped = true;
                continue;
            }
            if (($char === '"' || $char === "'") && ($quote === null || $quote === $char)) {
                $quote = $quote === null ? $char : null;
                continue;
            }
            if ($quote !== null) {
                continue;
            }
            if ($char === $open) {
                ++$depth;
            } elseif ($char === $close && --$depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $parsed */
    private function passesLine(array $parsed, Entity $caster, ?Entity $target): bool
    {
        $lineCooldown = max(0.0, (float)($parsed["params"]["cooldown"] ?? $parsed["params"]["cd"] ?? 0));
        $lineKey = $caster->getId().":line:".sha1((string)$parsed["raw"]);
        if ($lineCooldown > 0 && ($this->cooldowns[$lineKey] ?? 0) > microtime(true)) {
            return false;
        }
        $health = $parsed["health"];
        if (is_string($health) && !$this->passesHealth($caster, $health)) {
            return false;
        }
        foreach ($parsed["conditions"] as $condition) {
            if (!$this->passesCondition($condition["name"], $condition["params"], $caster, $target)) {
                return false;
            }
        }
        $oneShotKey = null;
        if (is_string($health) && str_starts_with($health, "=")) {
            $oneShotKey = $caster->getId() . ":" . sha1((string) $parsed["raw"]);
            if (isset($this->oneShot[$oneShotKey])) {
                return false;
            }
        }
        if (mt_rand() / mt_getrandmax() > (float) $parsed["chance"]) {
            return false;
        }
        if ($oneShotKey !== null) {
            $this->oneShot[$oneShotKey] = true;
        }
        if ($lineCooldown > 0) {
            $this->cooldowns[$lineKey] = microtime(true) + $lineCooldown;
        }
        return true;
    }

    private function passesHealth(Entity $caster, string $modifier): bool
    {
        if (!$caster instanceof Living) {
            return false;
        }
        $percent = str_contains($modifier, "%");
        $value = $percent ? ($caster->getHealth() / max(1, $caster->getMaxHealth()) * 100) : $caster->getHealth();
        $clean = str_replace("%", "", $modifier);
        $operator = $clean[0];
        $range = substr($clean, 1);
        if (str_contains($range, "-")) {
            [$min, $max] = array_map("floatval", explode("-", $range, 2));
            return $value >= min($min, $max) && $value <= max($min, $max);
        }
        $threshold = (float) $range;
        return match($operator) {
            "<" => $value < $threshold, ">" => $value > $threshold, "=" => $value <= $threshold, default => true,
        };
    }

    /** @param array<string,string> $params */
    private function passesCondition(string $name, array $params, Entity $caster, ?Entity $target): bool
    {
        $distance = $target !== null && !$target->isClosed() && $target->getWorld() === $caster->getWorld() ? sqrt($caster->getPosition()->distanceSquared($target->getPosition())) : INF;
        $limit = max(0.0, (float) ($params["distance"] ?? $params["d"] ?? $params["radius"] ?? $params["r"] ?? 0));
        if ($name === "targetwithin") {
            return $distance <= $limit;
        }
        if ($name === "targetnotwithin") {
            return $distance > $limit;
        }
        return $this->evaluateCondition($name, $params, $caster, $target ?? $caster);
    }

    /** @param array<string,string> $params */
    private function evaluateCondition(string $name, array $params, Entity $caster, Entity $subject): bool
    {
        $name = strtolower($name);
        $data = $this->plugin->getMobManager()->data($subject);
        $value = (string) ($params["value"] ?? $params["v"] ?? $params["type"] ?? $params["t"] ?? $params["mob"] ?? $params["m"] ?? "");
        return match($name) {
            "true" => true,
            "false" => false,
            "isplayer", "player" => $subject instanceof Player,
            "isliving", "living" => $subject instanceof Living,
            "ismythicmob", "mythicmob" => $data !== null,
            "mythicmobtype", "mobtype" => $data !== null && strcasecmp((string)($data["key"] ?? ""), $value) === 0,
            "entitytype" => strcasecmp($subject->getNetworkTypeId(), $value) === 0 || strcasecmp($subject->getName(), $value) === 0,
            "faction" => $data !== null && strcasecmp((string)($data["faction"] ?? ""), $value) === 0,
            "world" => strcasecmp($subject->getWorld()->getFolderName(), (string)($params["name"] ?? $params["n"] ?? $value)) === 0,
            "onground" => $subject->isOnGround(),
            "burning", "isonfire" => $subject->isOnFire(),
            "targetexists", "hastarget", "incombat" => $subject->getTargetEntity() !== null,
            "haspermission", "permission" => $subject instanceof Player && $subject->hasPermission((string)($params["permission"] ?? $params["p"] ?? $value)),
            "haspotioneffect", "potion" => $subject instanceof Living && ($effect = StringToEffectParser::getInstance()->parse($value)) !== null && $subject->getEffects()->has($effect),
            "altitude", "y" => $this->compareNumber($subject->getPosition()->y, (string)($params["y"] ?? $params["height"] ?? $params["h"] ?? $value)),
            "level", "mlevel" => $this->compareNumber((float)($data["level"] ?? 1), (string)($params["level"] ?? $params["l"] ?? $value)),
            "name" => strcasecmp($subject instanceof Player ? $subject->getName() : ($subject->getNameTag() ?: $subject->getName()), $value) === 0,
            default => $this->unknown("condition", $name),
        };
    }

    /** @return array<string, string> */
    private function parameters(string $raw): array
    {
        $result = [];
        foreach ($this->splitTopLevel($raw, [";", ","]) as $part) {
            [$key, $value] = array_pad(explode("=", trim($part), 2), 2, "true");
            if ($key !== "") {
                $result[strtolower(trim($key))] = trim(trim($value), "\"'");
            }
        }
        return $result;
    }

    /** @param list<string> $delimiters @return list<string> */
    private function splitTopLevel(string $text, array $delimiters): array
    {
        $result = [];
        $start = 0;
        $curly = 0;
        $square = 0;
        $quote = null;
        $escaped = false;
        for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === "\\" && $quote !== null) {
                $escaped = true;
                continue;
            }
            if (($char === '"' || $char === "'") && ($quote === null || $quote === $char)) {
                $quote = $quote === null ? $char : null;
                continue;
            }
            if ($quote !== null) {
                continue;
            }
            if ($char === "{") {
                ++$curly;
            } elseif ($char === "}") {
                --$curly;
            } elseif ($char === "[") {
                ++$square;
            } elseif ($char === "]") {
                --$square;
            } elseif ($curly === 0 && $square === 0 && in_array($char, $delimiters, true)) {
                $result[] = substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $result[] = substr($text, $start);
        return $result;
    }

    /** @return list<Entity> */
    private function targets(string $targeter, Entity $caster, ?Entity $target): array
    {
        $lower = strtolower($targeter);
        if (str_starts_with($lower, "@self") || str_starts_with($lower, "@caster")) {
            return [$caster];
        }
        if (str_starts_with($lower, "@target") || str_starts_with($lower, "@trigger")) {
            return $target !== null && !$target->isClosed() ? [$target] : [];
        }
        if (str_starts_with($lower, "@playersinradius") || str_starts_with($lower, "@pir")) {
            preg_match('/\{([^}]*)\}/', $targeter, $match);
            $params = $this->parameters($match[1] ?? "");
            $radius = max(0.0, (float) ($params["radius"] ?? $params["r"] ?? 10));
            $result = [];
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                if ($player->getWorld() === $caster->getWorld() && $player->getPosition()->distanceSquared($caster->getPosition()) <= $radius * $radius) {
                    $result[] = $player;
                }
            }
            return $this->filterTargets($result, $caster, $params);
        }
        if (str_starts_with($lower, "@playersinring")) {
            preg_match('/\{([^}]*)\}/', $targeter, $match);
            $params = $this->parameters($match[1] ?? "");
            $min = max(0.0, (float)($params["min"] ?? 0));
            $max = max($min, (float)($params["max"] ?? 10));
            $result = [];
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                $d = $player->getPosition()->distanceSquared($caster->getPosition());
                if ($player->getWorld() === $caster->getWorld() && $d >= $min * $min && $d <= $max * $max) {
                    $result[] = $player;
                }
            }
            return $this->filterTargets($result, $caster, $params);
        }
        if (str_starts_with($lower, "@nearestplayer")) {
            preg_match('/\{([^}]*)\}/', $targeter, $match);
            $params = $this->parameters($match[1] ?? "");
            $radius = max(0.0, (float) ($params["radius"] ?? $params["r"] ?? 64));
            $best = null;
            $bestDistance = $radius * $radius;
            foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                if ($player->getWorld() === $caster->getWorld() && ($distance = $player->getPosition()->distanceSquared($caster->getPosition())) <= $bestDistance) {
                    $best = $player;
                    $bestDistance = $distance;
                }
            }
            return $best !== null ? [$best] : [];
        }
        if (str_starts_with($lower, "@randomplayer")) {
            $players = array_values(array_filter($this->plugin->getServer()->getOnlinePlayers(), fn (Player $player) => $player->getWorld() === $caster->getWorld()));
            return $players === [] ? [] : [$players[array_rand($players)]];
        }
        if (str_starts_with($lower, "@playerbyname")) {
            preg_match('/\{([^}]*)\}/', $targeter, $match);
            $params = $this->parameters($match[1] ?? "");
            $player = $this->plugin->getServer()->getPlayerExact((string)($params["name"] ?? $params["n"] ?? ""));
            return $player === null ? [] : [$player];
        }
        if (str_starts_with($lower, "@threattable") || str_starts_with($lower, "@tt")) {
            return $this->plugin->getMobManager()->threatTargets($caster);
        }
        if (str_starts_with($lower, "@randomthreattarget") || str_starts_with($lower, "@rtt")) {
            $entries = $this->plugin->getMobManager()->threatTargets($caster);
            return $entries === [] ? [] : [$entries[array_rand($entries)]];
        }
        if (str_starts_with($lower, "@mobsinradius") || str_starts_with($lower, "@mir") || str_starts_with($lower, "@entitiesinradius") || str_starts_with($lower, "@eir") || str_starts_with($lower, "@livingentitiesinradius")) {
            preg_match('/\{([^}]*)\}/', $targeter, $match);
            $params = $this->parameters($match[1] ?? "");
            $radius = max(0.0, (float)($params["radius"] ?? $params["r"] ?? 10));
            $result = [];
            $source = str_starts_with($lower, "@mobs") || str_starts_with($lower, "@mir") ? $this->plugin->getMobManager()->activeEntities() : $caster->getWorld()->getEntities();
            foreach ($source as $entity) {
                if ($entity !== $caster && $entity instanceof Living && !$entity->isClosed() && $entity->getPosition()->distanceSquared($caster->getPosition()) <= $radius * $radius) {
                    $result[] = $entity;
                }
            }
            return $this->filterTargets($result, $caster, $params);
        }
        if (str_starts_with($lower, "@nearestentity") || str_starts_with($lower, "@nearestmob")) {
            $best = null;
            $distance = INF;
            foreach ($this->plugin->getMobManager()->activeEntities() as $entity) {
                if ($entity !== $caster && $entity->getWorld() === $caster->getWorld() && ($d = $entity->getPosition()->distanceSquared($caster->getPosition())) < $distance) {
                    $best = $entity;
                    $distance = $d;
                }
            }
            return $best === null ? [] : [$best];
        }
        if (str_starts_with($lower, "@children")) {
            $result = [];
            foreach ($this->plugin->getMobManager()->activeEntities() as $entity) {
                if ($entity->getOwningEntityId() === $caster->getId()) {
                    $result[] = $entity;
                }
            }
            return $result;
        }
        if (str_starts_with($lower, "@siblings")) {
            $parent = $caster->getOwningEntityId();
            if ($parent === null) {
                return[];
            }
            $result = [];
            foreach ($this->plugin->getMobManager()->activeEntities() as $entity) {
                if ($entity !== $caster && $entity->getOwningEntityId() === $parent) {
                    $result[] = $entity;
                }
            }
            return $result;
        }
        if (str_starts_with($lower, "@parent")) {
            $parent = $caster->getOwningEntity();
            return $parent === null ? [] : [$parent];
        }
        if (str_starts_with($lower, "@targetedentity")) {
            $selected = $caster->getTargetEntity();
            return $selected === null ? [] : [$selected];
        }
        if (str_starts_with($lower, "@owner")) {
            $owner = $caster->getOwningEntity();
            return $owner !== null && !$owner->isClosed() ? [$owner] : [];
        }
        if (str_starts_with($lower, "@world") || str_starts_with($lower, "@playersinworld")) {
            return array_values(array_filter($this->plugin->getServer()->getOnlinePlayers(), fn (Player $player) => $player->getWorld() === $caster->getWorld()));
        }
        if (str_starts_with($lower, "@server") || str_starts_with($lower, "@playersonserver")) {
            return array_values($this->plugin->getServer()->getOnlinePlayers());
        }
        if (str_starts_with($lower, "@none")) {
            return [];
        }
        $this->unknown("targeter", $targeter);
        return [];
    }

    /** @param list<Entity> $targets @param array<string,string> $params @return list<Entity> */
    private function filterTargets(array $targets, Entity $caster, array $params): array
    {
        $ignore = array_map("trim", explode(",", strtolower((string)($params["ignore"] ?? ""))));
        $only = array_map("trim", explode(",", strtolower((string)($params["target"] ?? ""))));
        $matches = function (Entity $entity, string $filter) use ($caster): bool {
            $data = $this->plugin->getMobManager()->data($entity);
            return match($filter) {
                "" => false,
                "self" => $entity === $caster,
                "players" => $entity instanceof Player,
                "vanilla" => $data === null,
                "monsters", "creatures" => $entity instanceof Living
                    && !$entity instanceof Player,
                "samefaction" => $data !== null
                    && ($data["faction"] ?? "") !== ""
                    && ($data["faction"] ?? "") === ($this->plugin->getMobManager()->data($caster)["faction"] ?? null),
                "owner" => $caster->getOwningEntityId() === $entity->getId(),
                default => false,
            };
        };
        $targets = array_values(array_filter($targets, function (Entity $entity) use ($ignore, $only, $matches): bool {
            foreach ($ignore as $filter) {
                if ($matches($entity, $filter)) {
                    return false;
                }
            }
            if ($only !== [""]) {
                foreach ($only as $filter) {
                    if ($matches($entity, $filter)) {
                        return true;
                    }
                }
                return false;
            }
            return true;
        }));
        $sort = strtolower((string)($params["sort"] ?? "none"));
        if ($sort === "random") {
            shuffle($targets);
        } elseif (in_array($sort, ["nearest", "furthest", "highest_health", "lowest_health"], true)) {
            usort($targets, function (Entity $a, Entity $b) use ($sort, $caster): int {
                $av = str_contains($sort, "health") && $a instanceof Living ? $a->getHealth() : $a->getPosition()->distanceSquared($caster->getPosition());
                $bv = str_contains($sort, "health") && $b instanceof Living ? $b->getHealth() : $b->getPosition()->distanceSquared($caster->getPosition());
                return in_array($sort, ["furthest", "highest_health"], true)
                    ? $bv <=> $av
                    : $av <=> $bv;
            });
        }
        $limit = max(0, (int)($params["limit"] ?? 0));
        return $limit > 0 ? array_slice($targets, 0, $limit) : $targets;
    }

    /** @param array<string,string> $params */
    private function lineParticles(Entity $caster, Entity $target, array $params, string $targeter): void
    {
        if ($caster->getWorld() !== $target->getWorld()) {
            return;
        }
        preg_match('/\{([^}]*)\}/', $targeter, $match);
        $targeterParams = $this->parameters($match[1] ?? "");
        $spacing = max(0.05, (float) ($targeterParams["radius"] ?? $targeterParams["r"] ?? 0.25));
        $start = $caster->getPosition()->add(0, $caster instanceof Living ? $caster->getEyeHeight() * 0.5 : 0.5, 0);
        $end = $target->getPosition()->add(0, $target instanceof Living ? $target->getEyeHeight() * 0.5 : 0.5, 0);
        $delta = $end->subtractVector($start);
        $length = max(0.001, $delta->length());
        $steps = min(512, max(1, (int) ceil($length / $spacing)));
        $particle = match(strtolower((string) ($params["p"] ?? $params["particle"] ?? "flame"))) {
            "smoke" => new SmokeParticle(),
            "heart" => new HeartParticle(),
            "crit", "critical" => new CriticalParticle(),
            "explode", "explosion" => new ExplodeParticle(),
            default => new FlameParticle(),
        };
        for ($i = 0; $i <= $steps; ++$i) {
            $caster->getWorld()->addParticle($start->add($delta->x * $i / $steps, $delta->y * $i / $steps, $delta->z * $i / $steps), $particle);
        }
    }

    /** @param array<string,string> $params */
    private function apply(string $mechanic, array $params, Entity $caster, Entity $target): void
    {
        switch ($mechanic) {
            case "damage":
                $amount = (float) ($params["amount"] ?? $params["a"] ?? 1) * $this->plugin->getMobManager()->power($caster);
                $target->attack(new EntityDamageByEntityEvent($caster, $target, EntityDamageEvent::CAUSE_MAGIC, max(0.0, $amount)));
                if ($this->plugin->getMobManager()->isMythic($caster)) {
                    $this->trigger($caster, "onSkillDamage", $target);
                }
                break;
            case "heal":
                if ($target instanceof Living) {
                    $target->heal(new EntityRegainHealthEvent($target, max(0.0, (float) ($params["amount"] ?? 1)), EntityRegainHealthEvent::CAUSE_MAGIC));
                }
                break;
            case "sethealth":
                if ($target instanceof Living) {
                    $target->setHealth(max(0.0, min($target->getMaxHealth(), (float)($params["amount"] ?? $params["a"] ?? 1))));
                }
                break;
            case "setmaxhealth":
                if ($target instanceof Living) {
                    $target->setMaxHealth(max(1, (int)($params["amount"] ?? $params["a"] ?? 20)));
                    $target->setHealth(min($target->getHealth(), $target->getMaxHealth()));
                }
                break;
            case "percentdamage":
                $amount = $target instanceof Living ? $target->getMaxHealth() * max(0.0, (float)($params["percent"] ?? $params["p"] ?? 10)) / 100 : 0;
                if ($amount > 0) {
                    $target->attack(new EntityDamageByEntityEvent($caster, $target, EntityDamageEvent::CAUSE_MAGIC, $amount));
                }
                break;
            case "message":
                if ($target instanceof Player) {
                    $target->sendMessage(MythicMobs::color($this->replace((string) ($params["m"] ?? $params["message"] ?? ""), $caster, $target)));
                }
                break;
            case "actionmessage":
            case "actionbar":
                if ($target instanceof Player) {
                    $target->sendActionBarMessage(MythicMobs::color($this->replace((string)($params["m"] ?? $params["message"] ?? ""), $caster, $target)));
                }
                break;
            case "title":
            case "sendtitle":
                if ($target instanceof Player) {
                    $target->sendTitle(MythicMobs::color($this->replace((string)($params["title"] ?? $params["t"] ?? ""), $caster, $target)), MythicMobs::color($this->replace((string)($params["subtitle"] ?? $params["s"] ?? ""), $caster, $target)), (int)($params["fadein"] ?? 10), (int)($params["duration"] ?? $params["stay"] ?? 40), (int)($params["fadeout"] ?? 10));
                }
                break;
            case "command":
                $command = ltrim($this->replace((string) ($params["c"] ?? $params["command"] ?? ""), $caster, $target), "/");
                if ($command !== "") {
                    $this->plugin->getServer()->dispatchCommand($this->plugin->getServer()->getConsoleSender(), $command);
                }
                break;
            case "potion":
                if ($target instanceof Living && ($effect = StringToEffectParser::getInstance()->parse((string) ($params["type"] ?? $params["t"] ?? "speed"))) !== null) {
                    $target->getEffects()->add(new EffectInstance($effect, max(1, (int) ($params["duration"] ?? $params["d"] ?? 100)), max(0, (int) ($params["level"] ?? $params["l"] ?? 1) - 1)));
                }
                break;
            case "potionclear":
            case "clearpotion":
            case "removeeffects":
                if ($target instanceof Living) {
                    $target->getEffects()->clear();
                }
                break;
            case "ignite":
                $target->setOnFire(max(1, (int) ceil((int) ($params["ticks"] ?? $params["duration"] ?? 100) / 20)));
                break;
            case "extinguish":
                $target->extinguish();
                break;
            case "teleport":
                $target->teleport($caster->getLocation());
                break;
            case "teleporttarget":
                $caster->teleport($target->getLocation());
                break;
            case "throw":
                $power = (float) ($params["velocity"] ?? $params["v"] ?? 1);
                $delta = $target->getPosition()->subtractVector($caster->getPosition())->normalize();
                $target->setMotion(new Vector3($delta->x * $power, max(0.3, $power * 0.5), $delta->z * $power));
                break;
            case "pull":
            case "pulltowards":
                $power = (float)($params["velocity"] ?? $params["v"] ?? 1);
                $delta = $caster->getPosition()->subtractVector($target->getPosition())->normalize();
                $target->setMotion($delta->multiply($power));
                break;
            case "velocity":
            case "setvelocity":
                $target->setMotion(new Vector3((float)($params["x"] ?? 0), (float)($params["y"] ?? 0), (float)($params["z"] ?? 0)));
                break;
            case "summon":
                $casterLevel = (int) ($this->plugin->getMobManager()->data($caster)["level"] ?? 1);
                $level = isset($params["level"]) || isset($params["l"]) ? $this->plugin->getMobManager()->rollLevel($params["level"] ?? $params["l"], "summon mechanic") : $casterLevel;
                $summoned = $this->plugin->getMobManager()->spawn((string) ($params["type"] ?? $params["mob"] ?? $params["m"] ?? ""), Location::fromObject($target->getPosition(), $target->getWorld()), $level);
                $summoned?->setOwningEntity($caster);
                break;
            case "setlevel":
                if (!$target instanceof Living) {
                    break;
                }
                $current = (int) ($this->plugin->getMobManager()->data($target)["level"] ?? 1);
                $raw = trim((string) ($params["level"] ?? $params["l"] ?? 1));
                $level = preg_match('/^[+-]\d+$/', $raw) ? $current + (int) $raw : $this->plugin->getMobManager()->rollLevel($raw, "setlevel mechanic");
                $this->plugin->getMobManager()->setLevel($target, max(1, $level));
                break;
            case "setname":
            case "setdisplayname":
                $target->setNameTag(MythicMobs::color($this->replace((string)($params["name"] ?? $params["n"] ?? ""), $caster, $target)));
                $target->setNameTagVisible(true);
                break;
            case "setscale":
                $target->setScale(max(0.01, (float)($params["scale"] ?? $params["s"] ?? 1)));
                break;
            case "settarget":
                $caster->setTargetEntity($target);
                break;
            case "cleartarget":
                $target->setTargetEntity(null);
                break;
            case "giveitem":
            case "give":
                if ($target instanceof Player && ($item = $this->plugin->makeItem((string)($params["item"] ?? $params["i"] ?? $params["type"] ?? "stone"), max(1, (int)($params["amount"] ?? $params["a"] ?? 1)))) !== null) {
                    $target->getInventory()->addItem($item);
                }
                break;
            case "dropitem":
                if (($item = $this->plugin->makeItem((string)($params["item"] ?? $params["i"] ?? $params["type"] ?? "stone"), max(1, (int)($params["amount"] ?? $params["a"] ?? 1)))) !== null) {
                    $target->getWorld()->dropItem($target->getPosition(), $item);
                }
                break;
            case "feed":
                if ($target instanceof Player) {
                    $target->getHungerManager()->addFood((float)($params["amount"] ?? $params["a"] ?? 1));
                }
                break;
            case "experience":
            case "giveexperience":
                if ($target instanceof Player) {
                    $target->getXpManager()->addXp(max(0, (int)($params["amount"] ?? $params["a"] ?? 1)));
                }
                break;
            case "suicide":
            case "kill":
                $target->kill();
                break;
            case "remove":
            case "despawn":
                $target->flagForDespawn();
                break;
            case "signal":
                $this->trigger($target, "onSignal", $caster, (string)($params["signal"] ?? $params["s"] ?? $params["value"] ?? $params["v"] ?? ""));
                break;
            case "effect:particles":
            case "particles":
                $particle = match(strtolower((string) ($params["p"] ?? $params["particle"] ?? "flame"))) {
                    "smoke" => new SmokeParticle(),
                    "heart" => new HeartParticle(),
                    "crit", "critical" => new CriticalParticle(),
                    "explode", "explosion" => new ExplodeParticle(),
                    default => new FlameParticle(),
                };
                for ($i = 0, $amount = min(100, max(1, (int) ($params["amount"] ?? 8))); $i < $amount; ++$i) {
                    $target->getWorld()->addParticle($target->getPosition()->add((mt_rand(-100, 100) / 100), mt_rand(0, 200) / 100, mt_rand(-100, 100) / 100), $particle);
                }
                break;
            case "sound":
            case "effect:sound":
                $sound = str_contains(strtolower((string) ($params["s"] ?? $params["sound"] ?? "")), "teleport") ? new EndermanTeleportSound() : new BlazeShootSound();
                $target->getWorld()->addSound($target->getPosition(), $sound);
                break;
            case "animation":
            case "animate":
                $name = (string) ($params["animation"] ?? $params["a"] ?? $params["name"] ?? "");
                if ($name === "") {
                    break;
                }
                $animation = $this->plugin->getModelManager()->resolveAnimation($target, $name);
                $packet = AnimateEntityPacket::create($animation, (string) ($params["nextstate"] ?? "default"), (string) ($params["stopexpression"] ?? ""), (int) ($params["stopversion"] ?? 0), (string) ($params["controller"] ?? ""), (float) ($params["blendout"] ?? 0.0), [$target->getId()]);
                $target->getWorld()->broadcastPacketToViewers($target->getPosition(), $packet);
                break;
            default:
                $this->unknown("mechanic", $mechanic);
                break;
        }
    }

    private function unknown(string $kind, string $name): bool
    {
        $key = $kind.":".strtolower($name);
        if (!isset($this->unknownComponents[$key])) {
            $this->unknownComponents[$key] = true;
            $this->plugin->getLogger()->warning("Unsupported Mythic $kind '$name'.");
        }
        return false;
    }

    private function replace(string $text, Entity $caster, Entity $target): string
    {
        $data = $this->plugin->getMobManager()->data($caster);
        return str_replace(["<caster.name>", "<caster.level>", "<target.name>", "{player}"], [$caster->getNameTag() ?: $caster->getName(), (string) ($data["level"] ?? 1), $target instanceof Player ? $target->getName() : ($target->getNameTag() ?: $target->getName()), $target instanceof Player ? $target->getName() : ""], $text);
    }
}
