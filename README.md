# MythicMobs for [Altay](https://github.com/altayofficial/Altay/)

A YAML-driven custom mob, skill, item, model, boss, and spawner system for [Altay](https://github.com/altayofficial/Altay/) and PocketMine-MP API 5.

[Project forked from MythicMobs](https://www.spigotmc.org/resources/mythicmobs.5702/)

## Overview

MythicMobs for [Altay](https://github.com/altayofficial/Altay/) gives server owners a configuration-first toolkit for building RPG enemies, bosses, encounters, equipment, and visual effects without writing a separate plugin for every mob.

Definitions are loaded from YAML. A mob can combine custom attributes, levels, equipment, AI targeting, factions, damage modifiers, drops, kill messages, boss bars, custom Bedrock models, animations, and reusable metaskills. Items retain their Mythic identity and skills through named NBT, including after they are dropped or stored.

The project is designed for:

- RPG, survival, dungeon, and minigame servers.
- Server owners who want configurable content without a dedicated developer.
- Developers who need a reusable PocketMine-native mob and skill foundation.
- Bedrock servers that require real namespaced actor identifiers and resource-pack models.

## Feature Summary

### Custom mobs

- YAML mob definitions.
- Real PocketMine `Living` entity implementations.
- Vanilla-backed and namespaced Bedrock network identifiers.
- Custom health, damage, movement speed, scale, armor, power, and knockback resistance.
- Equipment, factions, threat tracking, melee targeting, drops, and experience.
- Custom kill messages and damage modifiers.
- Level scaling, per-mob level modifiers, and world-distance scaling.
- Single, chained, and multiple templates.
- Range-based native Bedrock boss bars.

### Skills and metaskills

- Mechanics, targeters, triggers, health conditions, inline conditions, and chance.
- Reusable metaskills stored in `Skills/*.yml`.
- Target inheritance and target overrides.
- Caster, target, and trigger conditions.
- Cooldowns, cooldown fallbacks, failure skills, and cooldown modification.
- Tick-based delays and nested execution.
- Nested inline metaskills.
- Skill parameters and shared skill-tree variables.
- Custom entity animation playback.

### Custom items

- Display names, lore, enchantments, and templates.
- Item skills embedded directly in named NBT.
- Skill data survives drops, pickups, containers, inventories, and world saves.
- Item triggers for use, attack, damage, and dropping.
- Mythic items can be used in mob equipment and drop definitions.

### Models and animations

- No-code model definitions in YAML.
- Dynamic namespaced entity-class registration.
- Bedrock actor identifier injection.
- Geometry, texture, material, animation, animation-controller, script, and render-controller declarations.
- Automatic, content-versioned `.mcpack` generation.
- Animation aliases callable from skills.

### Encounter tools

- Persistent YAML spawner definitions.
- Configurable world, location, radius, interval, maximum population, and level.
- Optional world-level scaling.
- Native boss bars with range, color, style, fog, sky, and music controls.

## Requirements

- [Altay](https://github.com/altayofficial/Altay/) or a compatible PocketMine-MP fork using API `5.0.0`.
- PHP runtime and extensions provided by the server distribution.
- DevTools when loading the plugin directly from this source folder.
- A Bedrock resource pack for custom geometry, textures, and animations. The plugin can build and register this pack from model definitions.

Custom entity clients must accept the server resource pack. Vanilla-backed entities do not require custom visual assets.

## Installation

1. Place the `MythicMobs` directory in the server's `plugins` directory.
2. Install and enable DevTools when running the unpacked source version.
3. Start the server once.
4. Edit the generated files in `plugin_data/MythicMobs`.
5. Run `/mm reload` after changing YAML definitions.
6. When changing model assets, run `/mm models build` and restart the server.

The restart after a model build is required because [Altay](https://github.com/altayofficial/Altay/) loads resource packs before plugins are enabled.

## Data Directory

```text
plugin_data/MythicMobs/
DropTables/
  ExampleDropTables.yml
├── Items/
│   └── ExampleItems.yml
├── Mobs/
│   └── ExampleMobs.yml
├── Models/
│   ├── assets/
│   └── ExampleModels.yml
├── Skills/
│   └── ExampleSkills.yml
├── Spawners/
│   └── ExampleSpawners.yml
├── config-general.yml
└── config-mobs.yml
```

Definitions may be split across any number of `.yml` files in the appropriate directory. Internal names must be unique within their definition type.

## Quick Start

### Create a mob

```yaml
FlameGuardian:
  Type: skeleton
  Display: '&cFlame Guardian &7[Lv. <caster.level>]'
  Level: 3to7
  Health: 80
  Damage: 8
  Faction: Guardians

  LevelModifiers:
    Health: 6
    Damage: 0.5
    Armor: 0.25

  Options:
    MovementSpeed: 0.24
    FollowRange: 24
    AlwaysShowName: true
    PreventOtherDrops: true

  BossBar:
    Enabled: true
    Title: '&c<caster.name> &7[Lv. <caster.level>]'
    Range: 32
    Color: RED
    Style: SEGMENTED_10

  Skills:
    - skill{s=GuardianEntrance;amount=12} @self ~onSpawn
    - ignite{ticks=100} @target ~onAttack <50% 0.5

  Drops:
    - GuardianBlade 1 0.15
    - emerald 1-3 0.6
```

Reload and spawn it:

```text
/mm reload
/mm mobs spawn FlameGuardian:5 1
```

From the console, include a location:

```text
mm mobs spawn FlameGuardian:5 1 world,0,70,0,0,0
```

### Create a metaskill

```yaml
GuardianBaseEffects:
  Skills:
    - setvariable{var=skill.particleamount;val=<skill.amount>}
    - effect:particles{p=smoke;amount=<skill.var.particleamount>}

GuardianEntrance:
  Cooldown: 5
  Skill: GuardianBaseEffects
  Skills:
    - delay 10
    - effect:particles{p=flame;amount=<skill.var.particleamount>}
    - sound{s=mob.blaze.shoot}
```

Mechanics without an explicit targeter inherit the target set passed to the metaskill. A mechanic with its own targeter overrides that inherited set.

### Create a skilled item

```yaml
WeaponTemplate:
  Id: iron_sword
  Lore:
    - '&8A weapon carrying Mythic power.'

GuardianBlade:
  Template: WeaponTemplate
  Display: '&6Guardian Blade'
  Enchantments:
    - sharpness:2
  Skills:
    - effect:particles{p=flame;amount=8} @self ~onUse
    - effect:particles{p=crit;amount=6} @target ~onAttack
    - effect:particles{p=smoke;amount=4} @self ~onDrop
```

When the item is built, the plugin writes its internal name and complete skill list into its named NBT:

```text
MythicMobs
├── InternalName
└── Skills[]
```

Use `/mm items info GuardianBlade` to verify the number of embedded NBT skill lines.

### Create a drop table

Drop tables are reusable loot pools stored in `DropTables/*.yml`. Entries may use amounts, ranges, chances, weights, level bonuses, permissions, or other drop tables.

```yaml
GuardianRewards:
  MinItems: 1
  MaxItems: 2
  Drops:
    - Item: GuardianBlade
      Amount: 1
      Weight: 1
      Chance: 0.15
      MinLevel: 5
    - Item: emerald
      Amount: 2-6
      Weight: 8
      BonusLevelItems: 0.10
    - Table: GuardianSupplies
      Weight: 3

GuardianSupplies:
  Rolls: 1
  Drops:
    - Item: arrow
      Amount: 4-12
      Weight: 4
    - Item: golden_apple
      Amount: 1
      Weight: 1
      Permission: mythicmobs.loot.rare
```

Reference the table from a mob:

```yaml
FlameGuardian:
  Drops:
    - GuardianRewards
```

`Rolls` selects weighted entries a fixed number of times. `MinItems` and `MaxItems` select a random number of weighted entries. Without those options, every entry rolls its chance independently. Tables may reference other tables; circular references are rejected.

### Per-player loot

Per-player loot rolls custom drops separately for every eligible participant. Loot ownership prevents another player from collecting it.

```yaml
FlameGuardian:
  Drops:
    - GuardianRewards
  DropsPerPlayer: true
  MinimumDamagePercentForDrops: 0.05
  ClientsideDrops: true
```

- `DropsPerPlayer` enables separate rolls for every player who damaged the mob.
- `MinimumDamagePercentForDrops` is the required portion of maximum health dealt by that player. `0.05` and `5` both mean 5%.
- `ClientsideDrops` makes personal drops visible only to their owner. When false, everyone can see them, but only their owner can collect them.
- Items are split into legal stack sizes automatically.
- Projectile damage is credited to the player who fired the projectile.

Server-wide defaults are under `Configuration.MobDrops` in `config-mobs.yml`.

## Mob Configuration

Common top-level mob fields include:

| Field | Purpose |
| --- | --- |
| `Type` | Vanilla server-side entity base. |
| `NetworkType` | Namespaced Bedrock actor identifier for a custom model. |
| `Template` | One or more inherited mob definitions. |
| `Exclude` | Top-level template fields that should not be inherited. |
| `Display` | Visible name tag with color codes and placeholders. |
| `Level` | Fixed level or inclusive range such as `3to7` or `3-7`. |
| `Health` | Base maximum health. |
| `Damage` | Base melee damage. |
| `Armor` | Additional Mythic armor points. |
| `Power` | Multiplier used by supported skill damage. |
| `Faction` | Faction used by targeting and threat behavior. |
| `Options` | Movement, follow range, scale, display, and drop behavior. |
| `LevelModifiers` | Additive attribute growth per level. |
| `Equipment` | Armor assigned to equipment slots. |
| `DamageModifiers` | Cause-specific incoming damage multipliers. |
| `KillMessages` | Randomized player death messages. |
| `BossBar` | Native Bedrock boss-bar configuration. |
| `Skills` | Triggered skill lines attached to the mob. |
| `Drops` | Direct item entries or reusable drop-table names. |
| `DropsPerPlayer` | Rolls custom drops separately for every eligible participant. |
| `MinimumDamagePercentForDrops` | Required damage contribution for personal loot. |
| `ClientsideDrops` | Shows personal loot only to its owner. |

### Levels

The following declarations are valid:

```yaml
Level: 5
```

```yaml
Level: 3to7
```

`MobLevel` and `Options.Level` are accepted aliases. An explicitly supplied spawn level overrides the configured default. Invalid values log an error and fall back to level `1`.

Per-mob modifiers are additive:

```yaml
LevelModifiers:
  Health: 5
  Damage: 0.5
  MovementSpeed: 0.005
  KnockbackResistance: 0.02
  Armor: 0.5
  Power: 0.1
  Scale: 0.01
```

Attributes without a mob-specific modifier use the global equations in `config-mobs.yml`.

### Templates

Templates may be declared as one name, a comma-separated string, or a YAML list:

```yaml
EliteGuardian:
  Template: GuardianBase, FireResistanceBase
  Exclude:
    - KillMessages
  Health: 120
```

Inheritance supports chained templates and detects circular references. Scalars are overridden, lists are appended in inheritance order, and nested maps are merged recursively. `BossBar`, `Disguise`, and `Mount` replace their complete inherited sections.

### Damage modifiers

List and map syntax are supported:

```yaml
DamageModifiers:
  - PROJECTILE 0.5
  - FIRE -0.25
  - FIRE_TICK 0
```

- A positive value multiplies damage.
- `0` removes the damage.
- A negative value cancels damage and heals the mob by the corresponding amount.

### Boss bars

```yaml
BossBar:
  Enabled: true
  Title: '&c<caster.name>'
  Range: 40
  Color: PURPLE
  Style: SOLID
  CreateFog: false
  DarkenSky: false
  PlayMusic: false
  Fog: minecraft:fog_hell
  Music: music.game
```

Colors: `PINK`, `BLUE`, `RED`, `GREEN`, `YELLOW`, `PURPLE`, `WHITE`.

Styles: `SOLID`, `SEGMENTED_6`, `SEGMENTED_10`, `SEGMENTED_12`, `SEGMENTED_20`.

Boss bars automatically follow health, update level placeholders, reconcile nearby viewers, and clean up when the mob closes or dies.

## Pathfinding and AI goals

Mobs use a bounded A* navigator instead of straight-line pursuit. It searches walkable blocks, handles one-block elevation changes, caches routes, repaths moving targets, can open doors, and limits new searches per tick for low-tier servers.

Goal and target selectors accept optional numeric priorities. Lower numbers run first. `clear` removes inherited/default selectors.

```yaml
AIGoalSelectors:
  - clear
  - 1 opendoors
  - 2 leapattarget
  - 3 meleeattack{interval=1}
  - 8 randomstroll{radius=10}

AITargetSelectors:
  - clear
  - 1 hurtbytarget
  - 2 players
```

Implemented goal selectors include `meleeattack`, `attack`, `arrowattack`, `rangedattack`, `shootattack`, `movetowardtarget`, `gototarget`, `leapattarget`, `fleeplayers`, `avoidplayers`, `panic`, `followowner`, `opendoors`, `randomstroll`, and `randomwalk`. Ranged attacks spawn real arrows and attribute their damage and skill triggers to the Mythic mob.

Implemented target selectors include `hurtbytarget`, `attacker`, `attackers`, `players`, `nearestplayer`, `monsters`, `nearestmonster`, `otherfactionmonsters`, `specificfactionmonsters`, `specifictargetfaction`, and `specificmob`. Selection respects `Options.FollowRange`, active threat, faction, and selector priority.

```yaml
AITargetSelectors:
  - 1 otherfactionmonsters
  - 2 specificfactionmonsters Guardians
  - 3 specificmob SkeletalKnight
```

## Skill System

A mob or item skill line uses this general structure:

```text
mechanic{option=value} @Targeter{option=value} ~onTrigger <health 0.5
```

The targeter, trigger, health condition, inline conditions, and chance are optional where appropriate.

### Implemented triggers

- Lifecycle: `onSpawn`, `onSpawnOrLoad`, `onReady`, `onDeath`, `onTeleport`, `onHeal`, `onExplode`
- Combat: `onCombat`, `onEnterCombat`, `onDropCombat`, `onChangeTarget`, `onAttack`, `onDamaged`, `onSkillDamage`, `onKill`, `onPlayerKill`
- Projectile: `onShoot`, `onProjectileHit`, `onProjectileLand`, `onBowHit`
- Other: `onInteract`, `onSignal:name`, `onTimer:<ticks>`
- Item-only event hooks: `onUse` and `onDrop`

### Implemented targeters

- Single entity: `@self`, `@caster`, `@target`, `@trigger`, `@owner`, `@parent`, `@TargetedEntity`, `@NearestPlayer`, `@NearestMob`, `@PlayerByName`
- Multiple entities: `@PlayersInRadius`, `@PlayersInRing`, `@MobsInRadius`, `@EntitiesInRadius`, `@Children`, `@Siblings`
- Threat: `@ThreatTable`, `@TT`, `@RandomThreatTarget`, `@RTT`
- Global: `@World`, `@PlayersInWorld`, `@Server`, `@PlayersOnServer`, `@RandomPlayer`, `@None`
- Common `ignore`, `target`, `limit`, and `sort` options on radius targeters
- Particle `@Line{r=0.25}`

### Implemented mechanics

- Damage and health: `damage`, `percentdamage`, `heal`, `sethealth`, `setmaxhealth`, `kill`, `suicide`
- Messaging: `message`, `actionmessage`, `actionbar`, `title`, `sendtitle`, `command`
- Status: `potion`, `potionclear`, `removeeffects`, `ignite`, `extinguish`, `feed`, `experience`
- Movement: `teleport`, `teleporttarget`, `throw`, `pull`, `pulltowards`, `velocity`, `setvelocity`
- Entity state: `setname`, `setdisplayname`, `setscale`, `settarget`, `cleartarget`, `remove`, `despawn`
- Content: `giveitem`, `give`, `dropitem`, `summon`, `setlevel`, `signal`
- `particles` / `effect:particles`
- `sound` / `effect:sound`
- `animation` / `animate`
- `skill`, `s`, `metaskill`, `meta`, `skill:Name`
- `setvariable`
- `variableskill`
- `setskillcooldown`
- `delay`
- Universal `delay`, `repeat`, `repeatinterval`, and line `cooldown` attributes

### Conditions and chance

Health modifiers support absolute values, percentages, ranges, and one-shot thresholds:

```yaml
Skills:
  - ignite{ticks=100} @target ~onAttack <50% 0.5
  - skill{s=PhaseTwo} @self ~onDamaged =30%-50%
  - skill{s=LastStand} @self ~onDamaged <20
```

The final decimal is chance, where `1` is always and `0` is never. Chance is evaluated once per attempted skill execution.

Supported metaskill condition primitives currently include:

- `day`
- `night`
- `distance`
- `health`
- `healthpercent`
- `skilloncooldown`
- `isplayer`, `isliving`, `ismythicmob`, `mythicmobtype`, `entitytype`, `faction`
- `world`, `onground`, `burning`, `targetexists`, `incombat`, `haspermission`, `haspotioneffect`
- `altitude`, `level`, `name`, `true`, `false`
- Inline `?targetwithin{d=...}`
- Inline `?targetnotwithin{d=...}`

Unknown component names are reported once in the server log. Bukkit/Paper internals and external integrations such as Citizens, ModelEngine, LibsDisguises, Vault, or Crucible are not emulated silently.

### Metaskills

```yaml
ExampleSkill:
  CancelIfNoTargets: true
  Conditions:
    - day true
  TargetConditions:
    - distance{d=<10} true
  TriggerConditions:
    - distance{d=<5} true
  FailedConditionsSkill: ExampleFailure
  Cooldown: 10
  OnCooldownSkill: ExampleCooldown
  Skill: SharedEffects
  Skills:
    - message{m='Hello, <target.name>!'}
    - delay 20
    - ignite{ticks=100}
```

Supported metaskill behavior includes:

- Per-caster cooldowns.
- Failure and cooldown branches.
- Parent-skill execution with parent controls ignored.
- Target inheritance and per-target filtering.
- Nested calls with the same skill-tree state.
- Delayed continuation.
- Reserved-parameter filtering.
- `<skill.parameter>` placeholders.
- `setvariable{var=skill.name;val=value}` and `<skill.var.name>`.
- `<caster.skill.SkillName.cooldown>`.

Inline metaskills are supported:

```yaml
Skills:
  - 'skill{s=[ - message{m="Prepare yourself!"} - delay 20 - ignite ]} @trigger ~onInteract'
```

Nested braces, brackets, quoted delimiters, `<#>` comments, and `<&nm>` comments are handled by the balanced parser.

## Custom Models

Define a model in `Models/*.yml`:

```yaml
FrostGolemModel:
  Identifier: rpg:frost_golem
  Summonable: true

  Geometry:
    default:
      File: assets/frost_golem.geo.json
      Identifier: geometry.frost_golem

  Textures:
    default:
      File: assets/frost_golem.png
      Identifier: textures/entity/frost_golem

  Animations:
    attack:
      File: assets/frost_golem.animation.json
      Identifier: animation.frost_golem.attack

  AnimationControllers:
    main:
      File: assets/frost_golem.controller.json
      Identifier: controller.animation.frost_golem.main

  Scripts:
    animate: [main]

  RenderControllers:
    - controller.render.default
```

Reference the same identifier from a mob:

```yaml
FrostGolem:
  Type: zombie
  NetworkType: rpg:frost_golem
  Display: '&bFrost Golem'
  Health: 100
  Skills:
    - animation{a=attack;blendout=0.15} @self ~onAttack
```

Then build and restart:

```text
/mm models build
```

The model manager:

1. Loads model YAML.
2. Registers a concrete runtime entity class.
3. Injects the namespaced identifier into the Bedrock actor list.
4. Packages referenced assets.
5. Creates `resource_packs/MythicMobsModels-<hash>.mcpack`.
6. Updates the server resource stack.

See [MODELS.md](MODELS.md) for the focused model guide.

## Spawners

```yaml
guardian_temple:
  MobName: FlameGuardian
  World: world
  X: 100
  'Y': 64
  Z: -40
  Radius: 4
  Interval: 30
  MaxMobs: 3
  Level: 3to6
  UseWorldScaling: false
  Enabled: true
```

Spawner state can be created and modified in-game or from YAML. Runtime changes are saved to `Spawners/runtime.yml`.

## Commands

The root command is `/mythicmobs`. Aliases: `/mm`, `/mythic`.

| Command | Description |
| --- | --- |
| `/mm help` | Show command help. |
| `/mm version` | Show plugin and API version information. |
| `/mm reload` | Reload configurations and definitions. |
| `/mm debug <level>` | Set the persisted debug level. |
| `/mm debugmode <true\|false>` | Toggle debug mode. |
| `/mm save` | Save runtime spawner state. |
| `/mm mobs list` | List loaded mobs. |
| `/mm mobs info <mob>` | Show core mob information. |
| `/mm mobs listactive` | Show active Mythic mob counts. |
| `/mm mobs spawn <mob>:<level> [amount] [world,x,y,z,yaw,pitch]` | Spawn mobs. |
| `/mm mobs kill <mob>` | Remove active mobs by internal name. |
| `/mm mobs kill -f <faction>` | Remove active mobs by faction. |
| `/mm mobs killall` | Remove every active Mythic mob. |
| `/mm items list` | List loaded items. |
| `/mm items info <item>` | Show item type and embedded NBT skill count. |
| `/mm items get <item> [amount]` | Give an item to yourself. |
| `/mm items give <player> <item> [amount]` | Give an item to a player. |
| `/mm skills cast <skill>` | Cast a metaskill in-game. |
| `/mm models list` | List loaded models. |
| `/mm models build` | Rebuild the generated model resource pack. |
| `/mm spawners list` | List spawner definitions. |
| `/mm spawners info <name>` | Show a spawner definition. |
| `/mm spawners create <name> <mob> [seconds] [max]` | Create a spawner at the player. |
| `/mm spawners set <name> <setting> <value>` | Update a spawner setting. |
| `/mm spawners activate <name>` | Force a spawner activation attempt. |
| `/mm spawners resettimers <name>` | Reset a spawner timer. |
| `/mm spawners delete <name>` | Delete a runtime spawner. |

Administrative operations require `mythicmobs.admin`, which defaults to server operators.

## Configuration

### `config-general.yml`

Controls general diagnostics, feature switches, command feedback, scheduler periods, packet compatibility keys, and compatibility placeholders.

Important sections:

- `Configuration.General`
- `Configuration.Features`
- `Configuration.Commands`
- `Configuration.Clock`
- `Configuration.Packet`
- `Configuration.Compatibility`

### `config-mobs.yml`

Controls mob defaults, drops, egg metadata, hologram compatibility keys, global level equations, default level modifiers, and per-world scaling.

World scaling example:

```yaml
Configuration:
  MobLeveling:
    ScalingEquations:
      Health: 'V * ((1.05)^(L-1))'
      Damage: 'V * ((1.05)^(L-1))'
      Scale: 'V'
    WorldScaling:
      Default:
        Enabled: true
        ScaleVanillaMobs: false
        PerBlocksFromSpawn: 250
```

## Permissions

| Permission | Default | Purpose |
| --- | --- | --- |
| `mythicmobs.command` | Everyone | Access the root command. |
| `mythicmobs.admin` | Operator | Access administrative operations. |
| `mythicmobs.command.mobs.list` | Operator | Compatibility permission for mob listing. |
| `mythicmobs.command.info` | Operator | Compatibility permission for information commands. |
| `mythicmobs.command.test.cast` | Operator | Compatibility permission for test casting. |

## Placeholders

Common supported placeholders include:

- `<caster.name>`
- `<caster.level>`
- `<mob.name>`
- `<mob.level>`
- `<target.name>`
- `<target.display_name>`
- `<skill.parameter>`
- `<skill.var.name>`
- `<caster.skill.SkillName.cooldown>`
- `<&sq>`

Placeholder availability depends on the mechanic or message context.

## Troubleshooting

### A custom model is invisible

- Confirm the mob's `NetworkType` exactly matches the model `Identifier`.
- Confirm geometry, texture, animation, and controller files exist under `Models/assets`.
- Run `/mm models build`.
- Restart the server before clients connect.
- Confirm the generated pack is present in `resource_packs/resource_packs.yml`.
- Require server resource packs when the model is essential to gameplay.

### A definition does not load

- Validate YAML indentation.
- Quote ambiguous values and the `Y` coordinate key when required by the YAML parser.
- Check the console for missing templates, circular template chains, invalid levels, or missing assets.
- Keep internal names unique.
- Run `/mm reload` after changes.

### A skill does not execute

- Verify the trigger name and targeter.
- Confirm the caster or inherited target exists.
- Check health conditions, inline conditions, chance, cooldown, and `CancelIfNoTargets`.
- Use `@self` for mechanics that should always affect the caster.
- Verify metaskill internal-name spelling.

### An item lost its skills

- Use `/mm items info <item>` to confirm the source definition builds NBT skills.
- Ensure the item was created by MythicMobs after skills were added to its YAML.
- Existing items are not retroactively rewritten when a definition changes.

## Project Status

The plugin is actively evolving. Some configuration options from the Java edition of MythicMobs depend on Java-only plugins or server features and are therefore unavailable on PocketMine. Check the console after `/mm reload`; unsupported component names and invalid configuration values are reported there.
