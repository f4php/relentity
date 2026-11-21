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

    protected string|RelentityInterface $viaEntity;
    protected null|string|array|ConditionCollection $viaSourceCondition;
    protected null|string|array|ConditionCollection $viaTargetCondition;
    public function via(string|RelentityInterface $entity, null|string|array|ConditionCollection $sourceCondition = null, null|string|array|ConditionCollection $targetCondition = null): static {
        if($entity instanceof RelentityInterface && $entity->getActionType() !== ActionType::Fetch) {
            throw new InvalidArgumentException(__FUNCTION__ . '() requires Relentity objects to have Fetch action type');
        }
        $this->viaEntity =  $entity;
        $this->viaSourceCondition = $sourceCondition;
        $this->viaTargetCondition = $targetCondition;
        return $this;
    }

    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        if(empty($this->source)) {
            throw new RuntimeException('Cannot determine source relentity, a withSource() call on relation instance is required');
        }
        $target = clone ($this->target);
        if(!empty($this->viaEntity)) {
            $viaTable = $this->viaEntity instanceof RelentityInterface ? $this->viaEntity->getTableName() : $this->viaEntity;
            $linkCondition = ConditionCollection::of(
                static::buildJoinCondition(                        // source.col = link.col
                    condition: $this->viaSourceCondition,
                    defaultSourceColumn: $this->source->getPrimaryKey(),
                    defaultTargetColumn: $this->source->getPrimaryKey(),
                    sourceAlias: static::SOURCE_ALIAS,
                    targetAlias: $viaTable,
                ),
                static::buildJoinCondition(                        // link.col = target.col
                    condition: $this->viaTargetCondition,
                    defaultSourceColumn: $target->getPrimaryKey(),
                    defaultTargetColumn: $target->getPrimaryKey(),
                    sourceAlias: $viaTable,
                    targetAlias: $target->getTableName(),
                ),
            );
            $target->matching(['EXISTS({#::#})' => match($this->viaEntity instanceof RelentityInterface) {
                true => (clone $this->viaEntity)->unlimited()->matching($linkCondition)->asQuery('1'),
                default => DB::select(1)->from($this->viaEntity)->where($linkCondition),
            }]);
        }
        else {
            $target->matching(static::buildJoinCondition(
                condition: $this->relationCondition,
                defaultSourceColumn: $this->source->getPrimaryKey(),
                defaultTargetColumn: $this->source->getPrimaryKey(),
                sourceAlias: static::SOURCE_ALIAS,
                targetAlias: $target->getTableName(),
            ));
        }
        return static::buildJoinLateralQuery(
            query: $query,
            alias: $alias,
            sourceTableName: $this->source->getTableName(),
            rows: match($target->getActionType() === ActionType::Count) { 
                true => new Fragment('{#::#}.{#::#} AS {#::#}', [
                    DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                    DB::escapeIdentifier(RelentityInterface::COUNT_ALIAS),
                    DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
                ]),
                default => new Fragment('jsonb_agg(to_jsonb({#::#}.*)) AS {#::#}', [
                    DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                    DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
                ])
            },
            targetQuery: $target->asQuery(),
        );
    }
}
