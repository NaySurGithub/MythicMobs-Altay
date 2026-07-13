<?php

declare(strict_types=1);

namespace mythicmobs\ui;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class CallbackForm implements Form
{
    private \Closure $handler;

    /** @param array<string,mixed> $data */
    public function __construct(
        private array $data,
        \Closure $handler
    ) {
        $this->handler = $handler;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function handleResponse(Player $player, mixed $data): void
    {
        if ($data === null) {
            return;
        }
        ($this->handler)($player, $data);
    }
}
