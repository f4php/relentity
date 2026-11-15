<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\Relentity\Exception\RelentityException;
use F4\Tests\Relentity\Fixtures\{
    File,
    InvalidRelations,
    NoPrimaryKey,
    NoTableName,
    OwnedFile,
    UnknownDefaultRelation,
};

final class RelationTest extends TestCase
{
    private static function lateral(string $alias, string $value, string $rows): string
    {
        return "SELECT *, \"{$alias}\".\"relation_value\" AS \"{$alias}\" FROM \"file\""
            . " LEFT JOIN LATERAL (SELECT {$value} AS \"relation_value\" FROM ({$rows}) AS \"relation_rows\") AS \"{$alias}\" ON true"
            . ' LIMIT 100';
    }

    private const string LIST = 'jsonb_agg(to_jsonb("relation_rows".*))';
    private const string ATTRIBUTES_LINK = '"attribute"."fileUUID" = "file"."fileUUID"';
    private const string TAGS_THROUGH = 'SELECT "file_tag"."tagUUID" FROM "file_tag" WHERE "deleted" = FALSE';

    public static function relationProvider(): array
    {
        return [
            'one-to-many, inferred keys' => ['attributes', self::lateral('attributes', self::LIST,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'class-string shorthand is one-to-many' => ['attributesShorthand', self::lateral('attributesShorthand', self::LIST,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'relation class defaults to one-to-many' => ['attributesDefaultType', self::lateral('attributesDefaultType', self::LIST,
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'modifyTarget as first-class callable' => ['visibleAttributes', self::lateral('visibleAttributes', self::LIST,
                'SELECT * FROM "attribute" WHERE "visible" = TRUE AND ' . self::ATTRIBUTES_LINK . ' LIMIT 100')],
            'modifyTarget as static closure' => ['recentAttributes', self::lateral('recentAttributes', self::LIST,
                'SELECT * FROM "attribute" WHERE "visible" = TRUE AND ' . self::ATTRIBUTES_LINK . ' ORDER BY "created" DESC LIMIT 5')],
            'count ignores the target default limit' => ['attributeCount', self::lateral('attributeCount', 'count(*)',
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK)],
            'options without a relation class' => ['attributeCountDefaultType', self::lateral('attributeCountDefaultType', 'count(*)',
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK)],
            'several options without a relation class' => ['ownedAttributesDefaultType', self::lateral('ownedAttributesDefaultType', self::LIST,
                'SELECT * FROM "attribute" WHERE "attribute"."ownerUUID" = "file"."ownerUUID" LIMIT 100')],
            'many-to-one, source key only, single row' => ['owner', self::lateral('owner', 'to_jsonb("relation_rows".*)',
                'SELECT * FROM "user" WHERE "user"."userUUID" = "file"."ownerUUID" LIMIT 1')],
            'many-to-one, keys inferred from the target' => ['user', self::lateral('user', 'to_jsonb("relation_rows".*)',
                'SELECT * FROM "user" WHERE "user"."userUUID" = "file"."userUUID" LIMIT 1')],
            'one-to-one, keys inferred from the source' => ['primaryAttribute', self::lateral('primaryAttribute', 'to_jsonb("relation_rows".*)',
                'SELECT * FROM "attribute" WHERE ' . self::ATTRIBUTES_LINK . ' LIMIT 1')],
            'one-to-one, explicit keys' => ['ownedAttribute', self::lateral('ownedAttribute', 'to_jsonb("relation_rows".*)',
                'SELECT * FROM "attribute" WHERE "attribute"."ownerUUID" = "file"."ownerUUID" LIMIT 1')],
            'many-to-many through entity' => ['tags', self::lateral('tags', self::LIST,
                'SELECT * FROM "tag" WHERE "tag"."tagUUID" IN (' . self::TAGS_THROUGH . ' AND "file_tag"."fileUUID" = "file"."fileUUID") ORDER BY "name" ASC LIMIT 100')],
            'many-to-many modifyThrough' => ['activeTags', self::lateral('activeTags', self::LIST,
                'SELECT * FROM "tag" WHERE "tag"."tagUUID" IN (' . self::TAGS_THROUGH . ' AND "active" = TRUE AND "file_tag"."fileUUID" = "file"."fileUUID") ORDER BY "name" ASC LIMIT 100')],
            'many-to-many count' => ['tagCount', self::lateral('tagCount', 'count(*)',
                'SELECT * FROM "tag" WHERE "tag"."tagUUID" IN (' . self::TAGS_THROUGH . ' AND "file_tag"."fileUUID" = "file"."fileUUID") ORDER BY "name" ASC')],
            'many-to-many link table name' => ['labels', self::lateral('labels', self::LIST,
                'SELECT * FROM "label" WHERE "label"."labelUUID" IN (SELECT "file_label"."labelUUID" FROM "file_label" WHERE "file_label"."fileUUID" = "file"."fileUUID") LIMIT 100')],
            'many-to-many link table name and keys' => ['linkedLabels', self::lateral('linkedLabels', self::LIST,
                'SELECT * FROM "label" WHERE "label"."labelUUID" IN (SELECT "file_label_link"."labelId" FROM "file_label_link" WHERE "file_label_link"."fileId" = "file"."fileUUID") LIMIT 100')],
            'custom select and join callbacks' => ['custom', 'SELECT *, \'custom\' AS "custom" FROM "file" LIMIT 100'],
        ];
    }

    #[DataProvider('relationProvider')]
    public function testRelationSQL(string $alias, string $expected): void
    {
        $this->assertSame($expected, File::fetch()->with($alias)->asSQL());
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
