<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\Relentity\{
    RelentityInterface,
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

use F4\DB\{
    QueryBuilderInterface,
};

class ManyToMany extends AbstractRelation implements RelationInterface {
    public function appendJoin(QueryBuilderInterface $query): QueryBuilderInterface {
        return $query;
    }
}