<?php

declare(strict_types=1);

namespace mythicmobs\ai;

use pocketmine\block\Door;
use pocketmine\entity\Living;
use pocketmine\math\Vector3;
use pocketmine\world\World;

final class PathNavigator
{
    /** @var array<int,array{path:list<Vector3>,index:int,goal:string,repath:int}> */
    private array $states = [];
    private int $tick = 0;
    private int $searchesThisTick = 0;
    private const MAX_SEARCHES_PER_TICK = 4;

    public function tick(): void
    {
        ++$this->tick;
        $this->searchesThisTick = 0;
    }
    public function clear(int $entityId): void
    {
        unset($this->states[$entityId]);
    }

    public function move(Living $entity, Vector3 $goal, float $speed, bool $openDoors = false): void
    {
        $id = $entity->getId();
        $goalKey = $goal->getFloorX() . ":" . $goal->getFloorY() . ":" . $goal->getFloorZ();
        $state = $this->states[$id] ?? null;
        if ($state === null || $state["goal"] !== $goalKey || $state["index"] >= count($state["path"]) || $this->tick >= $state["repath"]) {
            $path = $state["path"] ?? [];
            if ($this->searchesThisTick < self::MAX_SEARCHES_PER_TICK) {
                ++$this->searchesThisTick;
                $path = $this->findPath($entity->getWorld(), $entity->getPosition(), $goal, $openDoors);
            }
            $state = ["path" => $path, "index" => 0, "goal" => $goalKey, "repath" => $this->tick + 10];
            $this->states[$id] = $state;
        }
        $waypoint = $state["path"][$state["index"]] ?? $goal;
        if ($entity->getPosition()->distanceSquared($waypoint) < 0.5) {
            ++$state["index"];
            $this->states[$id] = $state;
            $waypoint = $state["path"][$state["index"]] ?? $goal;
        }
        if ($openDoors) {
            $block = $entity->getWorld()->getBlockAt($waypoint->getFloorX(), $waypoint->getFloorY(), $waypoint->getFloorZ());
            if ($block instanceof Door && !$block->isOpen()) {
                $entity->getWorld()->setBlock($block->getPosition(), $block->setOpen(true));
            }
        }
        $delta = $waypoint->subtractVector($entity->getPosition());
        $horizontal = sqrt($delta->x ** 2 + $delta->z ** 2);
        if ($horizontal < 0.001) {
            return;
        }
        $vertical = $entity->getMotion()->y;
        if ($delta->y > 0.35 && $entity->isOnGround()) {
            $vertical = 0.36;
        }
        $entity->setMotion(new Vector3($delta->x / $horizontal * $speed, $vertical, $delta->z / $horizontal * $speed));
        $entity->lookAt($waypoint->add(0, 1, 0));
    }

    /** @return list<Vector3> */
    private function findPath(World $world, Vector3 $start, Vector3 $goal, bool $doors): array
    {
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
        for ($visited = 0; !$open->isEmpty() && $visited < 384; ++$visited) {
            $current = $open->extract()["data"];
            [$x, $y, $z] = $current;
            $key = "$x:$y:$z";
            $nodes[$key] = $current;
            if (abs($x - $gx) + abs($z - $gz) <= 1 && abs($y - $gy) <= 2) {
                return $this->reconstruct($came, $nodes, $key);
            }
            foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dz]) {
                foreach ([0,1,-1] as $dy) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    $nz = $z + $dz;
                    if (!$this->walkable($world, $nx, $ny, $nz, $doors)) {
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
        }
        return [];
    }

    private function walkable(World $world, int $x, int $y, int $z, bool $doors): bool
    {
        if (!$world->isChunkLoaded($x >> 4, $z >> 4)) {
            return false;
        }
        $feet = $world->getBlockAt($x, $y, $z);
        $head = $world->getBlockAt($x, $y + 1, $z);
        $floor = $world->getBlockAt($x, $y - 1, $z);
        $feetPass = $feet->getCollisionBoxes() === [] || ($doors && $feet instanceof Door);
        $headPass = $head->getCollisionBoxes() === [] || ($doors && $head instanceof Door);
        return $feetPass && $headPass && $floor->getCollisionBoxes() !== [];
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
