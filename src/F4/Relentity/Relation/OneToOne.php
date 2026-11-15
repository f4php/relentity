<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\Relentity\{
    RelentityInterface,
    Relation\AbstractToOne,
    Relation\RelationInterface,
};

/**
 * A single related row as a JSON object (or null), where the key lives on the target ("has one"):
 * both keys default to the source primary key.
 * When the key lives on the source ("belongs to"), use ManyToOne.
 */
class OneToOne extends AbstractToOne implements RelationInterface {

    protected function defaultKey(RelentityInterface $source): string {
        return $source::PRIMARY_KEY;
    }
}
