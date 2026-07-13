# No-code custom models

Create a YAML file in `plugin_data/MythicMobs/Models`. Every model identifier automatically receives a concrete server entity class, `EntityFactory` registration, and client actor registration.

The simplest setup needs only an identifier:

```yaml
FrostGolemModel:
  Identifier: rpg:frost_golem
```

Then place these files in `plugin_data/MythicMobs/Models/assets`:

```text
frost_golem.geo.json
frost_golem.png
frost_golem.animation.json
frost_golem.animation_controllers.json
```

Geometry identifiers, animation identifiers, and animation-controller identifiers are discovered from the JSON automatically. The plugin also generates the client-entity definition and a dedicated render controller.

```yaml
FrostGolemModel:
  Identifier: rpg:frost_golem
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
    roar:
      File: assets/frost_golem.animation.json
      Identifier: animation.frost_golem.roar
  AnimationControllers:
    main:
      File: assets/frost_golem.controller.json
      Identifier: controller.animation.frost_golem.main
  Scripts:
    animate: [main]
  RenderControllers: [controller.render.default]
```

Put referenced files inside `plugin_data/MythicMobs/Models/assets`. Use the identifier on a mob:

```yaml
FrostGolem:
  Type: zombie
  NetworkType: rpg:frost_golem
  Display: '&bFrost Golem'
  Health: 100
  Skills:
    - animation{a=roar} @self ~onSpawn
    - animation{a=attack;blendout=0.15} @self ~onAttack
```

The pack is rebuilt automatically when model definitions or asset contents change. You can also run `/mm models build` manually. Restart afterward so Altay loads the generated content-versioned `resource_packs/MythicMobsModels-<hash>.mcpack` from the resource stack.
