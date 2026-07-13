# No-code custom models

Create a YAML file in `plugin_data/MythicMobs/Models`. Every model identifier automatically receives a concrete server entity class, `EntityFactory` registration, and client actor registration.

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

Run `/mm models build`, then restart the server. The plugin creates a content-versioned `resource_packs/MythicMobsModels-<hash>.mcpack` and adds it to the resource stack. A restart is required because Altay loads resource packs before plugins.
