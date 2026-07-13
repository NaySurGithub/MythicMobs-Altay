<?php

declare(strict_types=1);

namespace mythicmobs\skill;

final class SkillContext
{
    /** @param array<string,string> $parameters @param array<string,string> $variables */
    public function __construct(public array $parameters = [], public array $variables = [])
    {
    }
}
