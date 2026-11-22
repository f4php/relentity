<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\DB;
use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};

use F4\Relentity\{
    Relation\AbstractRelation,
    Relation\RelationInterface,
};

class Many extends AbstractRelation implements RelationInterface {

    public function appendJoinExpression(QueryBuilderInterface $query, string $alias): QueryBuilderInterface {
        $target = $this->buildTarget();
        return static::buildJoinLateralQuery(
            query: $query,
            alias: $alias,
            sourceTableName: $this->source->getTableName(),
            rows: new Fragment('jsonb_agg(to_jsonb({#::#}.*)) AS {#::#}', [
                DB::escapeIdentifier(self::SUBQUERY_ALIAS),
                DB::escapeIdentifier(static::RELATION_ROWS_ALIAS),
            ]),
            targetQuery: $target->asQuery(),
        );
    }
}
