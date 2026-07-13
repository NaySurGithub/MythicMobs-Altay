<?php

declare(strict_types=1);

namespace mythicmobs\cinematic;

use mythicmobs\MythicMobs;
use pocketmine\entity\Entity;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AnimateEntityPacket;
use pocketmine\network\mcpe\protocol\CameraInstructionPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstruction;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEase;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionEaseType;
use pocketmine\network\mcpe\protocol\types\camera\CameraSetInstructionRotation;
use pocketmine\network\mcpe\protocol\types\camera\CameraTargetInstruction;
use pocketmine\player\Player;
use pocketmine\entity\Location;

final class CinematicManager
{
    /** @var array<string,array<string,mixed>> */
    private array $definitions = [];
    /** @var array<int,array<string,mixed>> */
    private array $active = [];
    private CameraPresetManager $presets;
    private int $nextId = 1;

    public function __construct(private MythicMobs $plugin)
    {
        $this->presets = new CameraPresetManager();
    }

    public function reload(): void
    {
        $this->stopAll();
        $this->definitions = $this->plugin->loadDefinitions("Cinematics");
    }

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function start(
        string $name,
        Entity $caster,
        ?array $viewers = null
    ): bool {
        $definition = $this->definition($name);
        if ($definition === null || $caster->isClosed()) {
            return false;
        }
        foreach ($this->active as $sequence) {
            if (
                ($sequence["caster"] ?? null) === $caster &&
                strcasecmp((string) ($sequence["name"] ?? ""), $name) === 0
            ) {
                return false;
            }
        }
        $range = max(1.0, (float) ($definition["Range"] ?? 32));
        if ($viewers === null) {
            $viewers = [];
            foreach ($caster->getWorld()->getPlayers() as $player) {
                if (
                    $player->isConnected() &&
                    $player->getPosition()->distanceSquared(
                        $caster->getPosition()
                    ) <= $range * $range
                ) {
                    $viewers[] = $player;
                }
            }
        }
        if ($viewers === []) {
            return false;
        }
        $players = [];
        foreach ($viewers as $player) {
            if (!$player instanceof Player || !$player->isConnected()) {
                continue;
            }
            $this->presets->send($player);
            $players[$player->getId()] = [
                "player" => $player,
                "location" => clone $player->getLocation(),
            ];
        }
        if ($players === []) {
            return false;
        }
        $id = $this->nextId++;
        $this->active[$id] = [
            "name" => $name,
            "definition" => $definition,
            "caster" => $caster,
            "tick" => -1,
            "duration" => max(1, (int) ($definition["Duration"] ?? 100)),
            "freeze" => (bool) ($definition["FreezePlayers"] ?? true),
            "freezeCaster" => (bool) ($definition["FreezeCaster"] ?? true),
            "invulnerableCaster" => (bool) (
                $definition["InvulnerableCaster"] ?? true
            ),
            "casterLocation" => clone $caster->getLocation(),
            "players" => $players,
        ];
        return true;
    }

    public function tick(): void
    {
        foreach (array_keys($this->active) as $id) {
            $sequence = &$this->active[$id];
            $caster = $sequence["caster"];
            if (!$caster instanceof Entity || $caster->isClosed()) {
                $this->stop($id);
                continue;
            }
            if ((bool) $sequence["freezeCaster"]) {
                $savedCasterLocation = $sequence["casterLocation"];
                if (
                    $savedCasterLocation instanceof Location &&
                    $caster->getWorld() === $savedCasterLocation->getWorld() &&
                    $caster->getPosition()->distanceSquared(
                        $savedCasterLocation
                    ) > 0.01
                ) {
                    $caster->teleport($savedCasterLocation);
                }
                $caster->setMotion(Vector3::zero());
            }
            ++$sequence["tick"];
            foreach ($sequence["players"] as $playerId => $viewer) {
                $player = $viewer["player"];
                if (!$player instanceof Player || !$player->isConnected()) {
                    unset($sequence["players"][$playerId]);
                    continue;
                }
                if ((bool) $sequence["freeze"]) {
                    $saved = $viewer["location"];
                    if (
                        $saved instanceof Location &&
                        $player->getWorld() === $saved->getWorld() &&
                        $player->getPosition()->distanceSquared($saved) > 0.01
                    ) {
                        $player->teleport($saved);
                    }
                    $player->setMotion(Vector3::zero());
                }
            }
            if ($sequence["players"] === []) {
                $this->stop($id);
                continue;
            }
            $timeline = (array) (
                $sequence["definition"]["Timeline"] ?? []
            );
            foreach ((array) ($timeline[$sequence["tick"]] ?? []) as $action) {
                $this->execute($sequence, $action);
            }
            if ($sequence["tick"] >= $sequence["duration"]) {
                $this->stop($id);
            }
        }
    }

    public function stopFor(Entity $caster): void
    {
        foreach ($this->active as $id => $sequence) {
            if (($sequence["caster"] ?? null) === $caster) {
                $this->stop($id);
            }
        }
    }

    public function isCasterInvulnerable(Entity $caster): bool
    {
        foreach ($this->active as $sequence) {
            if (
                ($sequence["caster"] ?? null) === $caster &&
                (bool) ($sequence["invulnerableCaster"] ?? false)
            ) {
                return true;
            }
        }
        return false;
    }

    public function stopForPlayer(
        Player $player,
        bool $skipping = false
    ): bool {
        $stopped = false;
        foreach (array_keys($this->active) as $id) {
            $sequence = &$this->active[$id];
            if (!isset($sequence["players"][$player->getId()])) {
                continue;
            }
            if (
                $skipping &&
                !(bool) ($sequence["definition"]["AllowSkip"] ?? true)
            ) {
                continue;
            }
            $this->clearCamera($player);
            unset($sequence["players"][$player->getId()]);
            $stopped = true;
            if ($sequence["players"] === []) {
                $this->stop($id);
            }
        }
        unset($sequence);
        return $stopped;
    }

    public function stopAll(): void
    {
        foreach (array_keys($this->active) as $id) {
            $this->stop($id);
        }
    }

    /** @param array<string,mixed> $sequence */
    private function execute(array $sequence, mixed $rawAction): void
    {
        $caster = $sequence["caster"];
        if (!$caster instanceof Entity) {
            return;
        }
        $action = is_array($rawAction)
            ? $rawAction
            : ["Skill" => (string) $rawAction];
        if (isset($action["Skill"])) {
            $this->plugin->getSkillEngine()->cast(
                (string) $action["Skill"],
                $caster,
                $caster
            );
        }
        if (isset($action["Animation"])) {
            $animation = $this->plugin->getModelManager()->resolveAnimation(
                $caster,
                (string) $action["Animation"]
            );
            $caster->getWorld()->broadcastPacketToViewers(
                $caster->getPosition(),
                AnimateEntityPacket::create(
                    $animation,
                    "default",
                    "",
                    0,
                    "",
                    0.0,
                    [$caster->getId()]
                )
            );
        }
        foreach ($sequence["players"] as $viewer) {
            $player = $viewer["player"];
            if (!$player instanceof Player || !$player->isConnected()) {
                continue;
            }
            if (isset($action["Message"])) {
                $player->sendMessage(
                    MythicMobs::color((string) $action["Message"])
                );
            }
            if (isset($action["Title"])) {
                $player->sendTitle(
                    MythicMobs::color((string) $action["Title"]),
                    MythicMobs::color((string) ($action["Subtitle"] ?? "")),
                    (int) ($action["FadeIn"] ?? 10),
                    (int) ($action["Stay"] ?? 40),
                    (int) ($action["FadeOut"] ?? 10)
                );
            }
            if (isset($action["Sound"])) {
                $player->getNetworkSession()->sendDataPacket(
                    PlaySoundPacket::create(
                        (string) $action["Sound"],
                        $caster->getPosition()->x,
                        $caster->getPosition()->y,
                        $caster->getPosition()->z,
                        (float) ($action["Volume"] ?? 1.0),
                        (float) ($action["Pitch"] ?? 1.0),
                        null
                    )
                );
            }
            if (isset($action["Camera"]) && is_array($action["Camera"])) {
                $this->sendCamera($player, $caster, $action["Camera"]);
            }
            if ((bool) ($action["ClearCamera"] ?? false)) {
                $this->clearCamera($player);
            }
        }
    }

    /** @param array<string,mixed> $camera */
    private function sendCamera(
        Player $player,
        Entity $caster,
        array $camera
    ): void {
        $relative = (bool) ($camera["Relative"] ?? true);
        $base = $relative ? $caster->getPosition() : Vector3::zero();
        $position = new Vector3(
            $base->x + (float) ($camera["X"] ?? 0),
            $base->y + (float) ($camera["Y"] ?? 2),
            $base->z + (float) ($camera["Z"] ?? -6)
        );
        $facing = null;
        if ((bool) ($camera["FaceCaster"] ?? true)) {
            $facing = $caster->getPosition()->add(0, 1, 0);
        } elseif (isset($camera["FaceX"], $camera["FaceY"], $camera["FaceZ"])) {
            $facing = new Vector3(
                (float) $camera["FaceX"],
                (float) $camera["FaceY"],
                (float) $camera["FaceZ"]
            );
        }
        $rotation = $facing === null
            ? new CameraSetInstructionRotation(
                (float) ($camera["Pitch"] ?? 0),
                (float) ($camera["Yaw"] ?? 0)
            )
            : null;
        $easeName = strtolower((string) ($camera["Ease"] ?? "linear"));
        try {
            $easeType = CameraSetInstructionEaseType::fromName($easeName);
        } catch (\InvalidArgumentException) {
            $easeType = CameraSetInstructionEaseType::LINEAR;
        }
        $instruction = new CameraSetInstruction(
            $this->presets->index("mythicmobs:cinematic"),
            new CameraSetInstructionEase(
                $easeType,
                max(0.0, (float) ($camera["Duration"] ?? 1.0))
            ),
            $position,
            $rotation,
            $facing,
            null,
            null,
            null,
            false
        );
        $player->getNetworkSession()->sendDataPacket(
            CameraInstructionPacket::create(
                $instruction,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null
            )
        );
        if ((bool) ($camera["TargetCaster"] ?? false)) {
            $player->getNetworkSession()->sendDataPacket(
                CameraInstructionPacket::create(
                    null,
                    null,
                    null,
                    new CameraTargetInstruction(
                        new Vector3(
                            (float) ($camera["TargetOffsetX"] ?? 0),
                            (float) ($camera["TargetOffsetY"] ?? 1),
                            (float) ($camera["TargetOffsetZ"] ?? 0)
                        ),
                        $caster->getId()
                    ),
                    null,
                    null,
                    null,
                    null,
                    null
                )
            );
        }
    }

    private function stop(int $id): void
    {
        $sequence = $this->active[$id] ?? null;
        if ($sequence === null) {
            return;
        }
        foreach ($sequence["players"] as $viewer) {
            $player = $viewer["player"];
            if ($player instanceof Player && $player->isConnected()) {
                $this->clearCamera($player);
            }
        }
        unset($this->active[$id]);
    }

    private function clearCamera(Player $player): void
    {
        $player->getNetworkSession()->sendDataPacket(
            CameraInstructionPacket::create(
                null,
                true,
                null,
                null,
                true,
                null,
                null,
                null,
                true
            )
        );
    }

    /** @return array<string,mixed>|null */
    private function definition(string $name): ?array
    {
        foreach ($this->definitions as $key => $definition) {
            if (strcasecmp($key, $name) === 0) {
                return $definition;
            }
        }
        return null;
    }
}
