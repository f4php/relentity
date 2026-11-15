<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\Relentity\{
    RelentityInterface,
    Relation\AbstractToOne,
    Relation\RelationInterface,
};

/**
 * A single related row as a JSON object (or null), where the key lives on the source ("belongs to"):
 * both keys default to the target primary key.
 * When the source column is named differently, set it alone, e.g. 'sourceKey' => 'ownerUUID'.
 */
class ManyToOne extends AbstractToOne implements RelationInterface {

    protected function defaultKey(RelentityInterface $source): string {
        return $this->target::PRIMARY_KEY;
    }
}
