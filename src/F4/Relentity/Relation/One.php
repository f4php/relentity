<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\DB;
use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};

use F4\Relentity\{
    ActionType,
    RelentityInterface,
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

class One extends AbstractRelation implements RelationInterface {

    protected const array SUPPORTED_ACTIONS = [
        ActionType::Aggregate,
        ActionType::Fetch,
    ];

    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        $target = $this->buildTarget();
        if ($target->getActionType() === ActionType::Aggregate) {
            // a scalar subquery: PostgreSQL rejects an aggregate that returns more than one row,
            // instead of silently duplicating the source row
            return static::buildJoinLateralQuery(
                query: $query,
                alias: $alias,
                sourceTableName: $this->source->getTableName(),
                rows: new Fragment('(SELECT to_jsonb({#::#}.*) FROM ({#::#}) AS {#::#}) AS {#::#}', [
                    DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                    $target->asQuery(),
                    DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                    DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
                ]),
            );
        }
        return static::buildJoinLateralQuery(
            query: $query,
            alias: $alias,
            sourceTableName: $this->source->getTableName(),
            rows: new Fragment('to_jsonb({#::#}.*) AS {#::#}', [
                DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
            ]),
            targetQuery: $target->limitedTo(1)->asQuery(),
        );
    }

    /**
     * A fetch() target is the row the source belongs to, compared by the primary key of the target.
     * An aggregate() target summarizes the rows that belong to the source, as for Many.
     */
    protected function getDefaultConditionColumn(RelentityInterface $target): string {
        return match ($target->getActionType()) {
            ActionType::Fetch => $target->getPrimaryKey(),
            default => parent::getDefaultConditionColumn($target),
        };
    }
}
