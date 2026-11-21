<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\DB\{
    ConditionCollection,
    QueryBuilderInterface,
};
use F4\Relentity\{
    RelentityInterface,
};

interface RelationInterface {
    public static function of(string|RelentityInterface $target, null|string|array|ConditionCollection $condition = null): static;
    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface;
    public function appendSelectExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface;
    public function getConcealedPaths(string $alias): array;
    public function withSource(RelentityInterface $relentity): static;
}
