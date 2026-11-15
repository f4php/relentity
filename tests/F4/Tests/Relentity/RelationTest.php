<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\Relentity\Exception\RelentityException;
use F4\Tests\Relentity\Fixtures\{
    Employee,
    File,
    InvalidRelations,
    NoPrimaryKey,
    NoTableName,
    OwnedFile,
    UnknownDefaultRelation,
};

final class RelationTest extends TestCase
{
    private static function lateral(string $alias, string $table, string $value, string $rows): string
    {
        $value = str_replace('{target}', "\"{$table}\"", $value);
        return "SELECT \"file\".*, \"{$alias}\".\"relation_rows\" AS \"{$alias}\" FROM \"file\""
            . " LEFT JOIN LATERAL (SELECT {$value} AS \"relation_rows\" FROM ({$rows}) AS \"{$table}\") AS \"{$alias}\" ON true"
            . ' LIMIT 100';
    }

    private const string ROW = 'to_jsonb({target}.*)';
    private const string LIST = 'jsonb_agg(to_jsonb({target}.*))';
    private const string CONCEALED = 'to_jsonb("account".*) - ARRAY[\'passwordHash\',\'token\']::text[]';
    private const string ACCOUNT_LINK = '"account"."accountUUID" = "file"."accountUUID"';
    private const string ATTRIBUTES_LINK = '"attribute"."fileUUID" = "file"."fileUUID"';
    private const string TAGS_THROUGH = 'SELECT "file_tag"."tagUUID" FROM "file_tag" WHERE "deleted" = FALSE';

    public static function relationProvider(): array
    {
        return [
            'one-to-many, inferred keys' => ['attributes', self::lateral('attributes', 'attribute', self::LIST,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'class-string shorthand is one-to-many' => ['attributesShorthand', self::lateral('attributesShorthand', 'attribute', self::LIST,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'relation class defaults to one-to-many' => ['attributesDefaultType', self::lateral('attributesDefaultType', 'attribute', self::LIST,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'modifyTarget as first-class callable' => ['visibleAttributes', self::lateral('visibleAttributes', 'attribute', self::LIST,
                'SELECT * FROM "attribute" WHERE "visible" = TRUE AND ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'modifyTarget as static closure' => ['recentAttributes', self::lateral('recentAttributes', 'attribute', self::LIST,
                'SELECT * FROM "attribute" WHERE "visible" = TRUE AND ' . self::ATTRIBUTES_LINK . ' ORDER BY "created" DESC LIMIT 5')],
            'count ignores the target default limit' => ['attributeCount', self::lateral('attributeCount', 'attribute', 'count(*)',
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK)],
            'options without a relation class' => ['attributeCountDefaultType', self::lateral('attributeCountDefaultType', 'attribute', 'count(*)',
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK)],
            'several options without a relation class' => ['ownedAttributesDefaultType', self::lateral('ownedAttributesDefaultType', 'attribute', self::LIST,
                'SELECT * FROM "attribute" WHERE "attribute"."ownerUUID" = "file"."ownerUUID" LIMIT 100')],
            'many-to-one, source key only, single row' => ['owner', self::lateral('owner', 'user', self::ROW,
                'SELECT * FROM "user" WHERE "user"."userUUID" = "file"."ownerUUID" LIMIT 1')],
            'many-to-one, keys inferred from the target' => ['user', self::lateral('user', 'user', self::ROW,
                'SELECT * FROM "user" WHERE "user"."userUUID" = "file"."userUUID" LIMIT 1')],
            'one-to-one, keys inferred from the source' => ['primaryAttribute', self::lateral('primaryAttribute', 'attribute', self::ROW,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 1')],
            'one-to-one, explicit keys' => ['ownedAttribute', self::lateral('ownedAttribute', 'attribute', self::ROW,
                'SELECT * FROM "attribute" WHERE "attribute"."ownerUUID" = "file"."ownerUUID" LIMIT 1')],
            'many-to-many through entity' => ['tags', self::lateral('tags', 'tag', self::LIST,
                'SELECT * FROM "tag" WHERE "tag"."tagUUID" IN (' . self::TAGS_THROUGH . ' AND "file_tag"."fileUUID" = "file"."fileUUID") ORDER BY "name" ASC LIMIT 100')],
            'many-to-many modifyThrough' => ['activeTags', self::lateral('activeTags', 'tag', self::LIST,
                'SELECT * FROM "tag" WHERE "tag"."tagUUID" IN (' . self::TAGS_THROUGH . ' AND "active" = TRUE AND "file_tag"."fileUUID" = "file"."fileUUID") ORDER BY "name" ASC LIMIT 100')],
            'many-to-many count' => ['tagCount', self::lateral('tagCount', 'tag', 'count(*)',
                'SELECT * FROM "tag" WHERE "tag"."tagUUID" IN (' . self::TAGS_THROUGH . ' AND "file_tag"."fileUUID" = "file"."fileUUID") ORDER BY "name" ASC')],
            'many-to-many link table name' => ['labels', self::lateral('labels', 'label', self::LIST,
                'SELECT * FROM "label" WHERE "label"."labelUUID" IN (SELECT "file_label"."labelUUID" FROM "file_label" WHERE "file_label"."fileUUID" = "file"."fileUUID") LIMIT 100')],
            'many-to-many link table name and keys' => ['linkedLabels', self::lateral('linkedLabels', 'label', self::LIST,
                'SELECT * FROM "label" WHERE "label"."labelUUID" IN (SELECT "file_label_link"."labelId" FROM "file_label_link" WHERE "file_label_link"."fileId" = "file"."fileUUID") LIMIT 100')],
            'many-to-one conceals target defaults' => ['account', self::lateral('account', 'account', self::CONCEALED,
                'SELECT * FROM "account" WHERE ' . self::ACCOUNT_LINK . ' LIMIT 1')],
            'one-to-one conceals target defaults' => ['accountProfile', self::lateral('accountProfile', 'account', self::CONCEALED,
                'SELECT * FROM "account" WHERE "account"."fileUUID" = "file"."fileUUID" LIMIT 1')],
            'one-to-many conceals target defaults' => ['accounts', self::lateral('accounts', 'account', 'jsonb_agg(' . self::CONCEALED . ')',
                'SELECT * FROM "account" WHERE "account"."fileUUID" = "file"."fileUUID" LIMIT 100')],
            'many-to-many conceals target defaults' => ['linkedAccounts', self::lateral('linkedAccounts', 'account', 'jsonb_agg(' . self::CONCEALED . ')',
                'SELECT * FROM "account" WHERE "account"."accountUUID" IN (SELECT "file_account"."accountUUID" FROM "file_account" WHERE "file_account"."fileUUID" = "file"."fileUUID") LIMIT 100')],
            'count has nothing to conceal' => ['accountCount', self::lateral('accountCount', 'account', 'count(*)',
                'SELECT * FROM "account" WHERE "account"."fileUUID" = "file"."fileUUID"')],
            'modifyTarget reveals all target defaults' => ['revealedAccount', self::lateral('revealedAccount', 'account', self::ROW,
                'SELECT * FROM "account" WHERE ' . self::ACCOUNT_LINK . ' LIMIT 1')],
            'modifyTarget reveals and conceals' => ['partlyRevealedAccount', self::lateral('partlyRevealedAccount', 'account',
                'to_jsonb("account".*) - ARRAY[\'passwordHash\',\'email\']::text[]',
                'SELECT * FROM "account" WHERE ' . self::ACCOUNT_LINK . ' LIMIT 1')],
            'paths are concealed one by one' => ['deepConcealedAccount', self::lateral('deepConcealedAccount', 'account',
                self::CONCEALED . ' #- ARRAY[\'settings\',\'secret\']::text[] #- ARRAY[\'settings\',\'api.key\']::text[]',
                'SELECT * FROM "account" WHERE ' . self::ACCOUNT_LINK . ' LIMIT 1')],
            'custom select and join callbacks' => ['custom', 'SELECT "file".*, \'custom\' AS "custom" FROM "file" LIMIT 100'],
        ];
    }

    #[DataProvider('relationProvider')]
    public function testRelationSQL(string $alias, string $expected): void
    {
        $this->assertSame($expected, File::fetch()->with($alias)->asSQL());
    }

    /**
     * @param string $source the source key column
     * @param string $rows the target query, which must see the source key as "relation_source"."key"
     */
    private static function selfLateral(string $alias, string $value, string $source, string $rows): string
    {
        return "SELECT \"employee\".*, \"{$alias}\".\"relation_rows\" AS \"{$alias}\" FROM \"employee\""
            . " LEFT JOIN LATERAL (SELECT {$value} AS \"relation_rows\""
            . " FROM (SELECT \"employee\".\"{$source}\" AS \"key\") AS \"relation_source\""
            . " CROSS JOIN LATERAL ({$rows}) AS \"employee\") AS \"{$alias}\" ON true"
            . ' LIMIT 100';
    }

    /**
     * A target stored in the source table would shadow it, so the source key is read outside of the target query.
     */
    public static function selfReferencingProvider(): array
    {
        $manager = 'SELECT to_jsonb("employee".*) AS "relation_rows"'
            . ' FROM (SELECT "employee"."managerUUID" AS "key") AS "relation_source"'
            . ' CROSS JOIN LATERAL (SELECT * FROM "employee" WHERE "employee"."employeeUUID" = "relation_source"."key" LIMIT 1) AS "employee"';
        return [
            'many-to-one' => ['manager', self::selfLateral('manager', 'to_jsonb("employee".*)', 'managerUUID',
                'SELECT * FROM "employee" WHERE "employee"."employeeUUID" = "relation_source"."key" LIMIT 1')],
            'one-to-many' => ['reports', self::selfLateral('reports', 'jsonb_agg(to_jsonb("employee".*))', 'employeeUUID',
                'SELECT * FROM "employee" WHERE "employee"."managerUUID" = "relation_source"."key" LIMIT 100')],
            'one-to-many count' => ['reportCount', self::selfLateral('reportCount', 'count(*)', 'employeeUUID',
                'SELECT * FROM "employee" WHERE "employee"."managerUUID" = "relation_source"."key"')],
            'many-to-many' => ['mentors', self::selfLateral('mentors', 'jsonb_agg(to_jsonb("employee".*))', 'employeeUUID',
                'SELECT * FROM "employee" WHERE "employee"."employeeUUID" IN (SELECT "employee_mentor"."mentorUUID" FROM "employee_mentor"'
                . ' WHERE "employee_mentor"."employeeUUID" = "relation_source"."key") LIMIT 100')],
            'nested' => ['managerWithManager', self::selfLateral('managerWithManager', 'to_jsonb("employee".*)', 'managerUUID',
                'SELECT "employee".*, "manager"."relation_rows" AS "manager" FROM "employee"'
                . " LEFT JOIN LATERAL ({$manager}) AS \"manager\" ON true"
                . ' WHERE "employee"."employeeUUID" = "relation_source"."key" LIMIT 1')],
        ];
    }

    #[DataProvider('selfReferencingProvider')]
    public function testSelfReferencingRelationSQL(string $alias, string $expected): void
    {
        $this->assertSame($expected, Employee::fetch()->with($alias)->asSQL());
    }

    public function testSeveralRelationsHaveDistinctResultColumns(): void
    {
        $this->assertSame(
            'SELECT "file".*, "owner"."relation_rows" AS "owner", "user"."relation_rows" AS "user" FROM "file"'
                . ' LEFT JOIN LATERAL (SELECT to_jsonb("user".*) AS "relation_rows" FROM'
                . ' (SELECT * FROM "user" WHERE "user"."userUUID" = "file"."ownerUUID" LIMIT 1) AS "user") AS "owner" ON true'
                . ' LEFT JOIN LATERAL (SELECT to_jsonb("user".*) AS "relation_rows" FROM'
                . ' (SELECT * FROM "user" WHERE "user"."userUUID" = "file"."userUUID" LIMIT 1) AS "user") AS "user" ON true'
                . ' LIMIT 100',
            File::fetch()->with('owner', 'user')->asSQL(),
        );
    }

    public function testWildcardInColumnListIsQualifiedWhenRelationsAreJoined(): void
    {
        $this->assertStringStartsWith(
            'SELECT "file".*, "name", "owner"."relation_rows" AS "owner" FROM "file"',
            File::fetch(['*', 'name'])->with('owner')->asSQL(),
        );
    }

    public function testDuplicateRelationIsJoinedOnce(): void
    {
        $this->assertSame(
            File::fetch()->with('owner')->asSQL(),
            File::fetch()->with('owner', 'owner')->asSQL(),
        );
    }

    public function testWithoutRemovesRelation(): void
    {
        $this->assertSame(
            File::fetch()->with('tags')->asSQL(),
            File::fetch()->with('owner', 'tags')->without('owner')->asSQL(),
        );
    }

    public function testDefaultRelationsAreJoined(): void
    {
        $this->assertSame(
            File::fetch()->with('owner')->asSQL(),
            OwnedFile::fetch()->asSQL(),
        );
    }

    public function testDefaultRelationIsNotJoinedTwice(): void
    {
        $this->assertSame(
            OwnedFile::fetch()->asSQL(),
            OwnedFile::fetch()->with('owner')->asSQL(),
        );
    }

    public function testWithoutRemovesDefaultRelation(): void
    {
        $this->assertSame(
            File::fetch()->asSQL(),
            OwnedFile::fetch()->without('owner')->asSQL(),
        );
    }

    public function testWithoutIgnoresInactiveAndUnknownRelations(): void
    {
        $this->assertSame(
            File::fetch()->with('owner')->asSQL(),
            File::fetch()->with('owner')->without('tags', 'undeclared')->asSQL(),
        );
    }

    public function testRelationsKeepTheOrderTheyWereAddedIn(): void
    {
        $this->assertSame(
            File::fetch()->with('owner', 'tags')->asSQL(),
            File::fetch()->with('owner')->with('tags', 'owner')->asSQL(),
        );
        $this->assertNotSame(
            File::fetch()->with('owner', 'tags')->asSQL(),
            File::fetch()->with('tags', 'owner')->asSQL(),
        );
    }

    public function testUnknownRelationIsRejectedByWith(): void
    {
        $files = File::fetch();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown relation F4\\Tests\\Relentity\\Fixtures\\File::RELATIONS['undeclared']");
        $files->with('undeclared');
    }

    public function testUnknownDefaultRelationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown relation F4\\Tests\\Relentity\\Fixtures\\UnknownDefaultRelation::RELATIONS['undeclared']");
        UnknownDefaultRelation::fetch();
    }

    public static function invalidRelationProvider(): array
    {
        return [
            ['unknownOption', 'Unknown named parameter $tragetKey'],
            ['notARelation', "RELATIONS['notARelation'] must be a F4\\Relentity\\Relation\\RelationInterface class"],
            ['notAnEntity', "RELATIONS['notAnEntity'] must be a F4\\Relentity\\RelentityInterface class"],
            ['wrongType', 'must be of type bool, string given'],
            ['missingThrough', "Invalid declaration of F4\\Tests\\Relentity\\Fixtures\\InvalidRelations::RELATIONS['missingThrough']"],
            ['missingThroughWithOptions', '($through) not passed'],
            ['modifyThroughWithTable', 'modifyThrough requires through to be a F4\Relentity\RelentityInterface class'],
            ['undeclared', "Unknown relation F4\\Tests\\Relentity\\Fixtures\\InvalidRelations::RELATIONS['undeclared']"],
        ];
    }

    #[DataProvider('invalidRelationProvider')]
    public function testInvalidRelation(string $alias, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        InvalidRelations::fetch()->with($alias)->asSQL();
    }

    public function testMissingTableNameIsRejected(): void
    {
        $this->expectException(RelentityException::class);
        $this->expectExceptionMessage('must define TABLE_NAME');
        NoTableName::fetch();
    }

    public function testMissingPrimaryKeyIsRejected(): void
    {
        $this->expectException(RelentityException::class);
        $this->expectExceptionMessage('must define PRIMARY_KEY');
        NoPrimaryKey::fetch();
    }
}
