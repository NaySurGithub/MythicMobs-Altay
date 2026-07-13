<?php

declare(strict_types=1);

namespace mythicmobs\ai;

use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;

final class AiController
{
    private PathNavigator $navigator;
    /** @var array<int,array{destination:Vector3,until:int}> */
    private array $wander = [];
    private int $tick = 0;

    public function __construct()
    {
        $this->navigator = new PathNavigator();
    }
    public function forget(int $id): void
    {
        unset($this->wander[$id]);
        $this->navigator->clear($id);
    }
    public function beginTick(): void
    {
        ++$this->tick;
        $this->navigator->tick();
    }

    /** @param array<string,mixed> $data */
    public function tick(Living $mob, array &$data, ?Entity $target): void
    {
        $goals = $this->goals((array) ($data["definition"]["AIGoalSelectors"] ?? ["meleeattack", "randomstroll"]));
        $options = (array) ($data["definition"]["Options"] ?? []);
        $speed = max(0.03, min(0.6, (float) ($options["MovementSpeed"] ?? 0.2)));
        $openDoors = isset($goals["opendoors"]);
        $canSwim = (bool) ($options["CanSwim"] ?? true);
        $canClimb = (bool) ($options["CanClimb"] ?? true);
        $maxFallDistance = max(
            0,
            min(8, (int) ($options["MaxFallDistance"] ?? 3))
        );
        $avoidHazards = (bool) ($options["AvoidHazards"] ?? true);
        $move = function (Vector3 $goal, float $moveSpeed) use (
            $mob,
            $openDoors,
            $canSwim,
            $canClimb,
            $maxFallDistance,
            $avoidHazards
        ): void {
            $this->navigator->move(
                $mob,
                $goal,
                $moveSpeed,
                $openDoors,
                $canSwim,
                $canClimb,
                $maxFallDistance,
                $avoidHazards
            );
        };
        if ($target !== null && !$target->isClosed()) {
            $mob->setTargetEntity($target);
            $distance = $mob->getPosition()->distanceSquared($target->getPosition());
            $combat = $this->firstGoal($goals, [
                "fleeplayers",
                "avoidplayers",
                "panic",
                "arrowattack",
                "rangedattack",
                "shootattack",
                "meleeattack",
                "attack",
                "movetowardtarget",
                "gototarget",
            ]);
            if (in_array($combat, ["fleeplayers", "avoidplayers", "panic"], true)) {
                $away = $mob->getPosition()->subtractVector($target->getPosition())->normalize()->multiply(8);
                $move($mob->getPosition()->addVector($away), $speed * 1.2);
                return;
            }
            if (in_array($combat, ["arrowattack", "rangedattack", "shootattack"], true)) {
                $params = $goals[$combat];
                $range = max(4.0, (float)($params["range"] ?? 16));
                if ($distance > $range * $range * 0.7) {
                    $move($target->getPosition(), $speed);
                } else {
                    $mob->lookAt($target->getEyePos());
                }
                if ($distance <= $range * $range && microtime(true) - $data["lastAttack"] >= max(0.5, (float)($params["interval"] ?? 1.5))) {
                    $data["lastAttack"] = microtime(true);
                    $this->shoot($mob, $target);
                }
                return;
            }
            if (isset($goals["leapattarget"]) && $distance > 4 && $distance < 25 && $mob->isOnGround()) {
                $direction = $target->getPosition()->subtractVector($mob->getPosition())->normalize();
                $mob->setMotion(new Vector3($direction->x * 0.45, 0.42, $direction->z * 0.45));
            }
            if ($distance > 6.25) {
                $move($target->getPosition(), $speed);
            } elseif (in_array($combat, ["meleeattack", "attack"], true) && microtime(true) - $data["lastAttack"] >= max(0.25, (float)($goals[$combat]["interval"] ?? 1))) {
                $data["lastAttack"] = microtime(true);
                $target->attack(new EntityDamageByEntityEvent($mob, $target, EntityDamageEvent::CAUSE_ENTITY_ATTACK, $data["damage"]));
            }
            return;
        }
        if (isset($goals["followowner"]) && ($owner = $mob->getOwningEntity()) !== null) {
            $move($owner->getPosition(), $speed);
            return;
        }
        if (isset($goals["randomstroll"]) || isset($goals["randomwalk"])) {
            $state = $this->wander[$mob->getId()] ?? null;
            if ($state === null || $this->tick >= $state["until"] || $mob->getPosition()->distanceSquared($state["destination"]) < 1) {
                $radius = max(2, (int)($goals["randomstroll"]["radius"] ?? 8));
                $state = [
                    "destination" => $mob->getPosition()->add(
                        mt_rand(-$radius, $radius),
                        0,
                        mt_rand(-$radius, $radius),
                    ),
                    "until" => $this->tick + mt_rand(40, 120),
                ];
                $this->wander[$mob->getId()] = $state;
            }
            $move($state["destination"], $speed * 0.65);
        }
    }

    /** @param list<mixed> $lines @return array<string,array<string,string>> */
    private function goals(array $lines): array
    {
        $result = [];
        $order = 0;
        foreach ($lines as $line) {
            $text = trim((string)$line);
            if ($text === "") {
                continue;
            }
            $priority = 1000 + $order++;
            if (preg_match('/^(\d+)\s+/', $text, $priorityMatch)) {
                $priority = (int)$priorityMatch[1];
                $text = preg_replace('/^\d+\s+/', '', $text) ?? $text;
            }
            if (!preg_match('/^([a-z_]+)(?:\{([^}]*)\})?/i', $text, $match)) {
                continue;
            }
            $name = strtolower($match[1]);
            if ($name === "clear") {
                $result = [];
                continue;
            }
            $params = ["_priority" => (string)$priority];
            foreach (preg_split('/[;,]/', $match[2] ?? '') ?: [] as $part) {
                [$key, $value] = array_pad(
                    explode('=', trim($part), 2),
                    2,
                    'true',
                );
                $params[strtolower($key)] = $value;
            }
            $result[$name] = $params;
        }
        return $result;
    }

    /** @param array<string,array<string,string>> $goals @param list<string> $names */
    private function firstGoal(array $goals, array $names): ?string
    {
        $selected = null;
        $priority = PHP_INT_MAX;
        foreach ($names as $name) {
            if (!isset($goals[$name])) {
                continue;
            }
            $candidate = (int)($goals[$name]["_priority"] ?? PHP_INT_MAX);
            if ($candidate < $priority) {
                $selected = $name;
                $priority = $candidate;
            }
        }
        return $selected;
    }

    private function shoot(Living $mob, Entity $target): void
    {
        $start = $mob->getEyePos();
        $direction = $target->getEyePos()->subtractVector($start)->normalize();
        $arrow = new Arrow(Location::fromObject($start, $mob->getWorld()), $mob, false);
        $arrow->setPickupMode(Arrow::PICKUP_NONE);
        $arrow->setMotion($direction->multiply(1.6));
        $arrow->spawnToAll();
    }
}
