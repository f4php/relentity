<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\DB\{
    QueryBuilderInterface,
};
use F4\Relentity\{
    RelentityInterface,
};

/**
 * A relation is a stateless definition built from one RELATIONS entry: its constructor
 * receives the target class followed by the entry's named options. The same instance
 * compiles the relation for any source instance and alias.
 */
interface RelationInterface {
    public function appendSelect(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface;
    public function appendJoin(QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface;
}
