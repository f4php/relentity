<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use Closure;

use F4\Relentity\{
    RelentityInterface,
    Relation\RelationInterface,
};

use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};

abstract class AbstractRelation implements RelationInterface {

    protected const string DEFAULT_JSONB_ALIAS = 'relation_jsonb';

    public function __construct(
        protected RelentityInterface $source,
        protected RelentityInterface $target,
        protected string $targetAlias,
        protected ?Closure $selectCallback = null,
        protected ?Closure $joinCallback = null,
    ) {}
    public function appendSelect(QueryBuilderInterface $query): QueryBuilderInterface {
        return match($this->selectCallback) {
            null => $query->select(
                new Fragment('{#::#}.{#::#} AS {#::#}', [
                    $query->escapeIdentifier($this->targetAlias),
                    $query->escapeIdentifier(static::DEFAULT_JSONB_ALIAS),
                    $query->escapeIdentifier($this->targetAlias),
                ])
            ),
            default => ($this->selectCallback)($query),
        };
    }
    abstract public function appendJoin(QueryBuilderInterface $query): QueryBuilderInterface;

}