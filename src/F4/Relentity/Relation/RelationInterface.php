<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\DB\{
    QueryBuilderInterface,
};

interface RelationInterface {
    public function appendSelect(QueryBuilderInterface $query): QueryBuilderInterface;
    public function appendJoin(QueryBuilderInterface $query): QueryBuilderInterface;
}