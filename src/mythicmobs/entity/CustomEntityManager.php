<?php

declare(strict_types=1);

namespace mythicmobs\entity;

use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use pocketmine\entity\EntityDataHelper;

final class CustomEntityManager
{
    use SingletonTrait;

    private int $nextRuntimeId = 1_000_000;
    /** @var array<string, class-string<Entity>> */
    private array $registered = [];

    /**
     * @param class-string<Entity> $className
     * @param \Closure(World, CompoundTag): Entity $creationFunc
     * @param list<string>|null $saveNames
     */
    public function register(string $identifier, string $className, \Closure $creationFunc, ?array $saveNames = null, bool $summonable = true): void
    {
        $identifier = strtolower(trim($identifier));
        if (isset($this->registered[$identifier])) {
            return;
        }
        if ($className::getNetworkTypeId() !== $identifier) {
            throw new \InvalidArgumentException("$className::getNetworkTypeId() must return $identifier");
        }
        $this->registered[$identifier] = $className;
        if (!EntityFactory::getInstance()->isRegistered($className)) {
            EntityFactory::getInstance()->register($className, $creationFunc, $saveNames ?? [$identifier]);
        }
        $this->injectClientIdentifier($identifier, $summonable);
    }

    public function registerClientIdentifier(string $identifier, bool $summonable = true): void
    {
        $identifier = strtolower(trim($identifier));
        if (isset($this->registered[$identifier])) {
            return;
        }
        $this->injectClientIdentifier($identifier, $summonable);
    }

    /** @return class-string<Entity>|null */
    public function getEntityClass(string $identifier): ?string
    {
        return $this->registered[strtolower($identifier)] ?? null;
    }
    public function isRegistered(string $identifier): bool
    {
        return isset($this->registered[strtolower($identifier)]);
    }

    /** Creates and registers a concrete BuiltEntity class for a YAML-only model. */
    public function registerDynamic(string $identifier, bool $summonable = true): string
    {
        $identifier = strtolower(trim($identifier));
        if (!preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid Bedrock entity identifier: $identifier");
        }
        if (isset($this->registered[$identifier])) {
            return $this->registered[$identifier];
        }
        $shortName = "DynamicModel_" . substr(hash("sha256", $identifier), 0, 16);
        $className = __NAMESPACE__ . "\\" . $shortName;
        if (!class_exists($className, false)) {
            $classCode = sprintf(
                <<<'PHP'
                namespace %s;

                final class %s extends BuiltEntity
                {
                    public static function getNetworkTypeId(): string
                    {
                        return %s;
                    }
                }
                PHP,
                __NAMESPACE__,
                $shortName,
                var_export($identifier, true),
            );

            eval($classCode);
        }
        /** @var class-string<Entity> $className */
        $this->register($identifier, $className, fn (World $world, CompoundTag $nbt): Entity => new $className(EntityDataHelper::parseLocation($nbt, $world), $nbt), null, $summonable);
        return $className;
    }

    private function injectClientIdentifier(string $identifier, bool $summonable): void
    {
        $packet = StaticPacketCache::getInstance()->getAvailableActorIdentifiers();
        $root = $packet->identifiers->getRoot();
        if (!$root instanceof CompoundTag) {
            return;
        }
        $existing = $root->getTag("idlist");
        $idlist = $existing instanceof ListTag ? $existing : new ListTag([], NBT::TAG_Compound);
        foreach ($idlist as $tag) {
            if ($tag instanceof CompoundTag && $tag->getString("id", "") === $identifier) {
                return;
            }
        }
        $idlist->push(CompoundTag::create()
            ->setString("bid", "")
            ->setString("id", $identifier)
            ->setInt("rid", $this->nextRuntimeId++)
            ->setByte("hasspawnegg", 0)
            ->setByte("summonable", $summonable ? 1 : 0)
            ->setByte("experimental", 0));
        $root->setTag("idlist", $idlist);
        $packet->identifiers = new CacheableNbt($root);
    }
}
