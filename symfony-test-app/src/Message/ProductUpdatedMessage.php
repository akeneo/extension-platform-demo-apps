<?php

namespace App\Message;

final class ProductUpdatedMessage
{
    public function __construct(
        public readonly string $identifier,
        public readonly bool $isUuid = false,
        public readonly ?string $receivedAt = null,
    ) {}
}
