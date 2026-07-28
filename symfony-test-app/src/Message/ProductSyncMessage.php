<?php

namespace App\Message;

final class ProductSyncMessage
{
    /**
     * @param string[] $uuids
     * @param string[] $identifiers
     * @param string[] $modelCodes
     */
    public function __construct(
        public readonly array $uuids = [],
        public readonly array $identifiers = [],
        public readonly array $modelCodes = [],
        public readonly ?int $logId = null,
    ) {}
}
