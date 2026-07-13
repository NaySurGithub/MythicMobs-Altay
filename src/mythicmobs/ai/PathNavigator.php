<?php

declare(strict_types=1);

namespace mythicmobs\ai;

use pocketmine\block\Door;
use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Living;
use pocketmine\math\Vector3;
use pocketmine\world\World;

final class PathNavigator
{
    /** @var array<int,array{path:list<Vector3>,index:int,goal:string,repath:int}> */
    private array $states = [];
    /** @var array<int,array{x:float,z:float,stuck:int}> */
    private array $movement = [];
    private int $tick = 0;
    private int $searchesThisTick = 0;
    private const MAX_SEARCHES_PER_TICK = 1;
    private const MAX_VISITED_NODES = 256;

    public function tick(): void
    {
        ++$this->tick;
        $this->searchesThisTick = 0;
    }
    public function clear(int $entityId): void
    {
        unset($this->states[$entityId]);
        unset($this->movement[$entityId]);
    }

    public function move(
        Living $entity,
        Vector3 $goal,
        float $speed,
        bool $openDoors = false,
        bool $canSwim = true,
        bool $canClimb = true,
        int $maxFallDistance = 3,
        bool $avoidHazards = true
    ): void {
        $id = $entity->getId();
        $goalKey = $goal->getFloorX() . ":" . $goal->getFloorY() . ":" . $goal->getFloorZ();
        $state = $this->states[$id] ?? null;
        if (
            $state === null ||
            $state["goal"] !== $goalKey ||
            $this->tick >= $state["repath"]
        ) {
            $path = $state["path"] ?? [];
            $searched = false;
            if ($this->searchesThisTick < self::MAX_SEARCHES_PER_TICK) {
                ++$this->searchesThisTick;
                $searched = true;
                $path = $this->findPath(
                    $entity->getWorld(),
                    $entity->getPosition(),
                    $goal,
                    $openDoors,
                    $canSwim,
                    $canClimb,
                    $maxFallDistance,
                    $avoidHazards
                );
            }
            $state = [
                "path" => $path,
                "index" => 0,
                "goal" => $goalKey,
                "repath" => $this->tick + (
                    $searched
                        ? ($path === [] ? 40 : 20)
                        : 1
                ),
            ];
            $this->states[$id] = $state;
        }
        $waypoint = $state["path"][$state["index"]] ?? null;
        if ($waypoint === null) {
            $entity->setMotion(new Vector3(0, $entity->getMotion()->y, 0));
            return;
        }
        if ($entity->getPosition()->distanceSquared($waypoint) < 0.5) {
            ++$state["index"];
            $this->states[$id] = $state;
            $waypoint = $state["path"][$state["index"]] ?? null;
            if ($waypoint === null) {
                return;
            }
        }
        if ($openDoors) {
            $block = $entity->getWorld()->getBlockAt($waypoint->getFloorX(), $waypoint->getFloorY(), $waypoint->getFloorZ());
            if ($block instanceof Door && !$block->isOpen()) {
                $entity->getWorld()->setBlock($block->getPosition(), $block->setOpen(true));
            }
        }
        $delta = $waypoint->subtractVector($entity->getPosition());
        $horizontal = sqrt($delta->x ** 2 + $delta->z ** 2);
        $position = $entity->getPosition();
        $movement = $this->movement[$id] ?? null;
        $stuck = 0;
        if ($movement !== null && $horizontal > 0.2) {
            $moved = ($position->x - $movement["x"]) ** 2
                + ($position->z - $movement["z"]) ** 2;
            $stuck = $moved < 0.0004
                ? $movement["stuck"] + 1
                : 0;
        }
        $this->movement[$id] = [
            "x" => $position->x,
            "z" => $position->z,
            "stuck" => $stuck,
        ];
        $vertical = $entity->getMotion()->y;
        $currentBlock = $entity->getWorld()->getBlockAt(
            $entity->getPosition()->getFloorX(),
            $entity->getPosition()->getFloorY(),
            $entity->getPosition()->getFloorZ()
        );
        if ($canSwim && $this->isWater($currentBlock->getTypeId())) {
            $vertical = max(-0.18, min(0.22, $delta->y * 0.25));
            $speed *= 0.8;
        } elseif (
            $canClimb &&
            $this->isClimbable($currentBlock->getTypeId())
        ) {
            $vertical = $delta->y >= 0 ? 0.2 : -0.15;
        }
        if (
            $this->isGrounded($entity) &&
            (
                $delta->y > 0.35 ||
                $stuck >= 2 ||
                $this->hasOneBlockObstacle($entity, $delta)
            )
        ) {
            $vertical = 0.42;
        }
        if ($horizontal < 0.001) {
            $entity->setMotion(new Vector3(0, $vertical, 0));
            return;
        }
        $entity->setMotion(new Vector3($delta->x / $horizontal * $speed, $vertical, $delta->z / $horizontal * $speed));
        $entity->lookAt($waypoint->add(0, 1, 0));
    }

    private function hasOneBlockObstacle(
        Living $entity,
        Vector3 $direction
    ): bool {
        $horizontal = new Vector3($direction->x, 0, $direction->z);
        if ($horizontal->lengthSquared() < 0.0001) {
            return false;
        }

        $direction = $horizontal->normalize();
        $world = $entity->getWorld();
        $y = $entity->getPosition()->getFloorY();
        foreach ([0.55, 0.9] as $distance) {
            $ahead = $entity->getPosition()->addVector(
                $direction->multiply($distance)
            );
            $x = $ahead->getFloorX();
            $z = $ahead->getFloorZ();
            $feetBlocked = $world->getBlockAt($x, $y, $z)
                ->getCollisionBoxes() !== [];
            $headClear = $world->getBlockAt($x, $y + 1, $z)
                ->getCollisionBoxes() === [];
            $aboveClear = $world->getBlockAt($x, $y + 2, $z)
                ->getCollisionBoxes() === [];
            if ($feetBlocked && $headClear && $aboveClear) {
                return true;
            }
        }

        return false;
    }

    private function isGrounded(Living $entity): bool
    {
        if ($entity->isOnGround()) {
            return true;
        }
        $position = $entity->getPosition();
        return $entity->getWorld()->getBlockAt(
            $position->getFloorX(),
            $position->getFloorY() - 1,
            $position->getFloorZ()
        )->getCollisionBoxes() !== [] && abs($entity->getMotion()->y) < 0.08;
    }

    /** @return list<Vector3> */
    private function findPath(
        World $world,
        Vector3 $start,
        Vector3 $goal,
        bool $doors,
        bool $canSwim,
        bool $canClimb,
        int $maxFallDistance,
        bool $avoidHazards
    ): array {
        $sx = $start->getFloorX();
        $sy = $start->getFloorY();
        $sz = $start->getFloorZ();
        $gx = $goal->getFloorX();
        $gy = $goal->getFloorY();
        $gz = $goal->getFloorZ();
        $startKey = "$sx:$sy:$sz";
        $open = new \SplPriorityQueue();
        $open->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $open->insert([$sx, $sy, $sz], 0);
        $cost = [$startKey => 0.0];
        $came = [];
        $nodes = [];
        $walkableCache = [];
        $verticalSteps = [0, 1];
        for ($fall = 1; $fall <= $maxFallDistance; ++$fall) {
            $verticalSteps[] = -$fall;
        }
        for (
            $visited = 0;
            !$open->isEmpty() && $visited < self::MAX_VISITED_NODES;
            ++$visited
        ) {
            $current = $open->extract()["data"];
            [$x, $y, $z] = $current;
            $key = "$x:$y:$z";
            $nodes[$key] = $current;
            if (abs($x - $gx) + abs($z - $gz) <= 1 && abs($y - $gy) <= 2) {
                return $this->reconstruct($came, $nodes, $key);
            }
            $neighbors = [[1, 0], [-1, 0], [0, 1], [0, -1]];
            foreach ($neighbors as [$dx, $dz]) {
                foreach ($verticalSteps as $dy) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    $nz = $z + $dz;
                    if (!$this->walkable(
                        $world,
                        $nx,
                        $ny,
                        $nz,
                        $doors,
                        $canSwim,
                        $canClimb,
                        $avoidHazards,
                        $walkableCache
                    )) {
                        continue;
                    }
                    $next = "$nx:$ny:$nz";
                    $new = ($cost[$key] ?? 0) + 1 + abs($dy) * 0.5;
                    if ($new >= ($cost[$next] ?? INF)) {
                        break;
                    }
                    $cost[$next] = $new;
                    $came[$next] = $key;
                    $nodes[$next] = [$nx,$ny,$nz];
                    $heuristic = abs($nx - $gx) + abs($nz - $gz) + abs($ny - $gy) * 0.5;
                    $open->insert([$nx,$ny,$nz], -($new + $heuristic));
                    break;
                }
            }
            $currentType = $world->getBlockAt($x, $y, $z)->getTypeId();
            if (
                ($canSwim && $this->isWater($currentType)) ||
                ($canClimb && $this->isClimbable($currentType))
            ) {
                foreach ([1, -1] as $dy) {
                    $ny = $y + $dy;
                    if (!$this->walkable(
                        $world,
                        $x,
                        $ny,
                        $z,
                        $doors,
                        $canSwim,
                        $canClimb,
                        $avoidHazards,
                        $walkableCache
                    )) {
                        continue;
                    }
                    $next = "$x:$ny:$z";
                    $new = ($cost[$key] ?? 0) + 1.2;
                    if ($new >= ($cost[$next] ?? INF)) {
                        continue;
                    }
                    $cost[$next] = $new;
                    $came[$next] = $key;
                    $nodes[$next] = [$x, $ny, $z];
                    $heuristic = abs($x - $gx)
                        + abs($z - $gz)
                        + abs($ny - $gy) * 0.5;
                    $open->insert([$x, $ny, $z], -($new + $heuristic));
                }
            }
        }
        return [];
    }

    private function walkable(
        World $world,
        int $x,
        int $y,
        int $z,
        bool $doors,
        bool $canSwim,
        bool $canClimb,
        bool $avoidHazards,
        array &$cache
    ): bool {
        $cacheKey = "$x:$y:$z";
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        if (!$world->isChunkLoaded($x >> 4, $z >> 4)) {
            return $cache[$cacheKey] = false;
        }
        $feet = $world->getBlockAt($x, $y, $z);
        $head = $world->getBlockAt($x, $y + 1, $z);
        $floor = $world->getBlockAt($x, $y - 1, $z);
        if (
            $avoidHazards &&
            (
                $this->isHazard($feet->getTypeId()) ||
                $this->isHazard($head->getTypeId()) ||
                $this->isHazard($floor->getTypeId())
            )
        ) {
            return $cache[$cacheKey] = false;
        }
        if (
            $canSwim &&
            (
                $this->isWater($feet->getTypeId()) ||
                $this->isWater($head->getTypeId())
            )
        ) {
            return $cache[$cacheKey] = true;
        }
        if (
            $canClimb &&
            (
                $this->isClimbable($feet->getTypeId()) ||
                $this->isClimbable($head->getTypeId())
            )
        ) {
            return $cache[$cacheKey] = true;
        }
        $feetPass = $feet->getCollisionBoxes() === [] || ($doors && $feet instanceof Door);
        $headPass = $head->getCollisionBoxes() === [] || ($doors && $head instanceof Door);
        return $cache[$cacheKey] = $feetPass &&
            $headPass &&
            $floor->getCollisionBoxes() !== [];
    }

    private function isWater(int $typeId): bool
    {
        return $typeId === BlockTypeIds::WATER;
    }

    private function isClimbable(int $typeId): bool
    {
        return $typeId === BlockTypeIds::LADDER ||
            $typeId === BlockTypeIds::VINES;
    }

    private function isHazard(int $typeId): bool
    {
        return in_array(
            $typeId,
            [
                BlockTypeIds::LAVA,
                BlockTypeIds::FIRE,
                BlockTypeIds::SOUL_FIRE,
                BlockTypeIds::CACTUS,
                BlockTypeIds::MAGMA,
                BlockTypeIds::SWEET_BERRY_BUSH,
                BlockTypeIds::CAMPFIRE,
            ],
            true
        );
    }

    /** @param array<string,string> $came @param array<string,array{int,int,int}> $nodes @return list<Vector3> */
    private function reconstruct(array $came, array $nodes, string $key): array
    {
        $path = [];
        while (isset($came[$key])) {
            [$x,$y,$z] = $nodes[$key];
            $path[] = new Vector3($x + 0.5, $y, $z + 0.5);
            $key = $came[$key];
        }
        return array_reverse($path);
    }
}
