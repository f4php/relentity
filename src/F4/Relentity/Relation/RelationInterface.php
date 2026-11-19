<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\DB\{
    QueryBuilderInterface,
};
use F4\Relentity\{
    RelentityInterface,
};

interface RelationInterface {
    public static function of(string|RelentityInterface $relentity): static;
    public function withSource(RelentityInterface $relentity): static;
    public function appendSelectExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface;
    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface;
}
