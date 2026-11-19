<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use InvalidArgumentException,
    RuntimeException;

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

class Many extends AbstractRelation implements RelationInterface {

    protected string $viaTable;
    protected string|array|ConditionCollection $viaCondition;

    public function via(string|RelentityInterface $tableOrRelentity, string|array|ConditionCollection $condition): static {
        if($this->target->getActionType() !== ActionType::Fetch) {
            throw new InvalidArgumentException(__FUNCTION__. '() is not supported for action type ' . $this->target->getActionType()->name);
        }
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
        $target = clone ($this->target);
        if(!empty($this->viaTable) && !empty($this->viaCondition)) {
            $target->joinedWith(
                table: $this->viaTable,
                condition: $this->viaCondition,
            );
        }
        return $query
            ->leftJoinLateral(
                new Fragment('({#::#}) AS {#::#}', [
                    DB::select(match($this->target->getActionType() === ActionType::Count) {
                        true => new Fragment('{#::#}.{#::#} AS {#::#}', [
                            $query->escapeIdentifier(self::SUBQUERY_ALIAS),
                            $query->escapeIdentifier(RelentityInterface::COUNT_ALIAS),
                            $query->escapeIdentifier(static::RELATION_ROWS_ALIAS),
                        ]),
                        default => new Fragment('jsonb_agg(to_jsonb({#::#}.*)) AS {#::#}', [
                            $query->escapeIdentifier(self::SUBQUERY_ALIAS),
                            $query->escapeIdentifier(static::RELATION_ROWS_ALIAS),
                        ])
                    })
                        ->from([
                            self::SUBQUERY_ALIAS => $target
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
