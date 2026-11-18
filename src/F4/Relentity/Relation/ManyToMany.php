<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use RuntimeException;

use F4\DB;
use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};

use F4\Relentity\{
    RelentityInterface,
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

use function
    is_subclass_of
;

class ManyToMany extends AbstractRelation implements RelationInterface {

    protected string $viaTable;
    protected string $viaKey;
    public function via(string|RelentityInterface $tableOrRelentity, string $key): static {
        $this->viaTable = match($tableOrRelentity instanceof RelentityInterface) {
            true => $tableOrRelentity->getTableName(),
            default => $tableOrRelentity,
        };
        $this->viaKey = $key;
        return $this;
    }
    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        if(empty($this->source)) {
            throw new RuntimeException('Cannot determine source relentity, a withSource() call on relation instance is required');
        }
        $target = clone ($this->target);
        return $query
            ->leftJoinLateral(
                new Fragment('({#::#}) AS {#::#}', [
                    DB::select(new Fragment('jsonb_agg(to_jsonb({#::#}.*)) AS {#::#}', [
                        $query->escapeIdentifier(self::SUBQUERY_ALIAS),
                        $query->escapeIdentifier(static::RELATION_ROWS_ALIAS),
                    ]))
                        ->from([
                            self::SUBQUERY_ALIAS => $target
                                ->joinedWith(
                                    $this->viaTable,
                                    $this->viaKey,
                                )
                                ->matching([
                                    // TODO: complex and custom join statements
                                    new Fragment('{#::#}.{#::#} = {#::#}.{#::#}', [
                                        $query->escapeIdentifier($this->source->getTableName()),
                                        $query->escapeIdentifier($this->sourceKey ?? $this->source->getPrimaryKey()),
                                        $query->escapeIdentifier($target->getTableName()),
                                        $query->escapeIdentifier($this->targetKey ?? $this->source->getPrimaryKey()),
                                    ])
                                ])
                                ->asQuery(),
                        ]),
                    $query->escapeIdentifier($alias),
                ])
            )
            ->on('true');
    }

}
