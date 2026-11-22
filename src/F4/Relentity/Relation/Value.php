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
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

/**
 * Exactly one value of one column of an aggregate() target, e.g. Value::of(Timesheets::count()).
 * Conditions and via() work as for Many. The target is a scalar subquery, so PostgreSQL rejects
 * a target with more than one column or more than one row.
 */
class Value extends AbstractRelation implements RelationInterface {

    protected const array SUPPORTED_ACTIONS = [
        ActionType::Aggregate,
    ];

    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        $target = $this->buildTarget();
        return static::buildJoinLateralQuery(
            query: $query,
            alias: $alias,
            sourceTableName: $this->source->getTableName(),
            rows: new Fragment('({#::#}) AS {#::#}', [
                $target->asQuery(),
                DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
            ]),
        );
    }
}
