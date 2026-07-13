<?php

declare(strict_types=1);

namespace mythicmobs\entity;

abstract class BuiltEntity extends MythicLiving
{
    protected function getInitialDragMultiplier(): float
    {
        return 0.02;
    }
    protected function getInitialGravity(): float
    {
        return 0.08;
    }
}
