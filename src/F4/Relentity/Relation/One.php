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
    ActionType,
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

class One extends AbstractRelation implements RelationInterface {

    protected const array SUPPORTED_ACTIONS = [
        ActionType::Fetch,
    ];

    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        if(empty($this->source)) {
            throw new RuntimeException('Cannot determine source relentity, a withSource() call on relation instance is required');
        }
        $target = clone ($this->target);
        return static::buildJoinLateralQuery(
            query: $query,
            alias: $alias,
            sourceTableName: $this->source->getTableName(),
            rows: new Fragment('to_jsonb({#::#}.*) AS {#::#}', [
                DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
            ]),
            targetQuery: $target
                ->matching(static::buildJoinCondition(
                    condition: $this->relationCondition,
                    defaultSourceColumn: $target->getPrimaryKey(),
                    defaultTargetColumn: $target->getPrimaryKey(),
                    sourceAlias: static::SOURCE_ALIAS,
                    targetAlias: $target->getTableName(),
                ))
                ->limitedTo(1)
                ->asQuery(),
        );
    }
}
