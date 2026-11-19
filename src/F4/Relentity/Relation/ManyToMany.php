<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use RuntimeException;

use F4\DB;
use F4\DB\{
    ConditionCollection,
    Fragment,
    QueryBuilderInterface,
};

use F4\Relentity\{
    ActionType,
    RelentityInterface,
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

class ManyToMany extends AbstractRelation implements RelationInterface {
    protected const array SUPPORTED_ACTIONS = [
        ActionType::Fetch,
    ];
    protected string $viaTable;
    protected string|array|ConditionCollection $viaCondition;

    public function via(string|RelentityInterface $tableOrRelentity, string|array|ConditionCollection $condition): static {
        $this->viaTable = match($tableOrRelentity instanceof RelentityInterface) {
            true => $tableOrRelentity->getTableName(),
            default => $tableOrRelentity,
        };
        $this->viaCondition = $condition;
        return $this;
    }
    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        if(empty($this->source)) {
            throw new RuntimeException('Cannot determine source relentity, a withSource() call on relation instance is required');
        }
        if(empty($this->viaTable) || empty($this->viaCondition)) {
            throw new RuntimeException('Association entity is missing, use via()');
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
                                    table: $this->viaTable,
                                    condition: $this->viaCondition,
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
