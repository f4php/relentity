<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\Tests\Relentity\Fixtures\{
    Account,
    File,
    FileAttribute,
    FileTag,
    OwnedFile,
    Tag,
};

final class RelentityTest extends TestCase
{
    public static function limitProvider(): array
    {
        return [
            'default limit' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes,
                'SELECT * FROM "attribute" LIMIT 100',
            ],
            'limitedTo replaces the default limit' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->limitedTo(10),
                'SELECT * FROM "attribute" LIMIT 10',
            ],
            'limitedTo with skipping' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->limitedTo(10, skipping: 20),
                'SELECT * FROM "attribute" LIMIT 10 OFFSET 20',
            ],
            'limitedTo named arguments' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->limitedTo(rows: 10, skipping: 20),
                'SELECT * FROM "attribute" LIMIT 10 OFFSET 20',
            ],
            'limitedTo(null) removes the limit' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->limitedTo(null),
                'SELECT * FROM "attribute"',
            ],
            'unlimited removes the limit' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->unlimited(),
                'SELECT * FROM "attribute"',
            ],
            'unlimited after limitedTo' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->limitedTo(10)->unlimited(),
                'SELECT * FROM "attribute"',
            ],
            'skipping keeps the default limit' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->skipping(20),
                'SELECT * FROM "attribute" LIMIT 100 OFFSET 20',
            ],
            'skipping named argument' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->skipping(rows: 20),
                'SELECT * FROM "attribute" LIMIT 100 OFFSET 20',
            ],
            'skipping then limitedTo' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->skipping(20)->limitedTo(10),
                'SELECT * FROM "attribute" LIMIT 10 OFFSET 20',
            ],
            'limitedTo without skipping keeps earlier skipping' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->limitedTo(10, skipping: 20)->limitedTo(5),
                'SELECT * FROM "attribute" LIMIT 5 OFFSET 20',
            ],
            'unlimited keeps skipping' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->skipping(5)->unlimited(),
                'SELECT * FROM "attribute" OFFSET 5',
            ],
            'skipping(0) removes skipping' => [
                static fn(FileAttribute $attributes): FileAttribute => $attributes->skipping(20)->skipping(0),
                'SELECT * FROM "attribute" LIMIT 100',
            ],
        ];
    }

    #[DataProvider('limitProvider')]
    public function testLimitAndSkipping(callable $modify, string $expected): void
    {
        $this->assertSame($expected, $modify(FileAttribute::fetch())->asSQL());
    }

    public function testLimitDoesNotApplyToRemove(): void
    {
        $this->assertSame(
            'DELETE FROM "attribute" WHERE "visible" = FALSE RETURNING *',
            FileAttribute::remove()->matching(['visible' => false])->limitedTo(5, skipping: 10)->asSQL(),
        );
    }

    public static function sortingProvider(): array
    {
        return [
            'default order' => [
                static fn(Tag $tags): Tag => $tags,
                'SELECT * FROM "tag" ORDER BY "name" ASC LIMIT 100',
            ],
            'sortedBy replaces the default order' => [
                static fn(Tag $tags): Tag => $tags->sortedBy(['created' => 'desc']),
                'SELECT * FROM "tag" ORDER BY "created" DESC LIMIT 100',
            ],
            'repeated sortedBy replaces instead of adding' => [
                static fn(Tag $tags): Tag => $tags->sortedBy(['created' => 'desc'])->sortedBy(['tagUUID' => 'asc']),
                'SELECT * FROM "tag" ORDER BY "tagUUID" ASC LIMIT 100',
            ],
            'sortedBy without arguments removes the order' => [
                static fn(Tag $tags): Tag => $tags->sortedBy(),
                'SELECT * FROM "tag" LIMIT 100',
            ],
        ];
    }

    #[DataProvider('sortingProvider')]
    public function testSorting(callable $modify, string $expected): void
    {
        $this->assertSame($expected, $modify(Tag::fetch())->asSQL());
    }

    public function testMatchingAddsToDefaultsAndEarlierCalls(): void
    {
        $this->assertSame(
            'SELECT * FROM "file_tag" WHERE "deleted" = FALSE AND "active" = TRUE AND "pinned" = TRUE LIMIT 100',
            FileTag::fetch()->matching(['active' => true])->matching(['pinned' => true])->asSQL(),
        );
    }

    public static function namedVariadicProvider(): array
    {
        return [
            'matching' => ['matching', static fn(File $files): File => $files->matching(filters: ['visible' => true])],
            'matching, column-like name' => ['matching', static fn(File $files): File => $files->matching(status: ['a', 'b'])],
            // Called by a dynamic name, as static analysis wrongly reports filters: [...] as overwriting the positional argument
            'matching, after positional' => ['matching', static fn(File $files): File => $files->{'matching'}(['visible' => true], filters: ['pinned' => true])],
            'sortedBy' => ['sortedBy', static fn(File $files): File => $files->sortedBy(criteria: ['name' => 'asc'])],
            'with' => ['with', static fn(File $files): File => $files->with(relations: 'tags')],
            'without' => ['without', static fn(File $files): File => $files->without(relations: 'tags')],
            'concealing' => ['concealing', static fn(File $files): File => $files->concealing(columns: 'name')],
            'revealing' => ['revealing', static fn(File $files): File => $files->revealing(columns: 'name')],
            'selecting' => ['selecting', static fn(File $files): File => $files->selecting(columns: 'name')],
        ];
    }

    #[DataProvider('namedVariadicProvider')]
    public function testVariadicMethodsRejectNamedArguments(string $method, callable $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$method}() does not accept named arguments");
        $call(File::fetch());
    }

    public function testVariadicMethodsAcceptPositionalAndSpreadArguments(): void
    {
        $this->assertSame(
            'SELECT * FROM "file" WHERE "visible" = TRUE AND "pinned" = TRUE ORDER BY "name" ASC, "created" DESC LIMIT 100',
            File::fetch()
                ->matching(...[['visible' => true], ['pinned' => true]])
                ->sortedBy(['name' => 'asc'], ['created' => 'desc'])
                ->with(...[])
                ->without('owner', 'tags')
                ->concealing('passwordHash', ['token'])
                ->revealing(...['token'])
                ->asSQL(),
        );
    }

    public static function concealedColumnsProvider(): array
    {
        return [
            'nothing by default' => [static fn(): File => File::fetch(), []],
            'DEFAULT_CONCEALING' => [static fn(): Account => Account::fetch(), ['passwordHash', 'token']],
            'revealing overrides DEFAULT_CONCEALING' => [
                static fn(): Account => Account::fetch()->revealing('passwordHash'),
                ['token'],
            ],
            'concealing adds to DEFAULT_CONCEALING' => [
                static fn(): Account => Account::fetch()->concealing('email'),
                ['passwordHash', 'token', 'email'],
            ],
            'concealing overrides an earlier revealing' => [
                static fn(): Account => Account::fetch()->revealing('token', 'email')->concealing('email'),
                ['passwordHash', 'email'],
            ],
            'concealing overrides a later revealing' => [
                static fn(): Account => Account::fetch()->concealing('email')->revealing('email', 'token'),
                ['passwordHash', 'email'],
            ],
            'a key and its single-segment path are the same' => [
                static fn(): Account => Account::fetch()->revealing('$.passwordHash')->concealing('$.token'),
                ['$.token'],
            ],
            'paths are kept as given' => [
                static fn(): File => File::fetch()->concealing('$.owner.passwordHash'),
                ['$.owner.passwordHash'],
            ],
            'arrays are flattened and repeated keys listed once' => [
                static fn(): Account => Account::fetch()->concealing('email', ['phone'])->revealing('token')->concealing('email'),
                ['passwordHash', 'email', 'phone'],
            ],
            'not limited to fetch' =>[static fn(): Account => Account::remove()->revealing('token'), ['passwordHash']],
        ];
    }

    #[DataProvider('concealedColumnsProvider')]
    public function testConcealedColumns(callable $build, array $expected): void
    {
        $this->assertSame($expected, $build()->getConcealedColumns());
    }

    public function testFetchColumns(): void
    {
        $this->assertSame(
            'SELECT "name", "value" FROM "attribute" LIMIT 100',
            FileAttribute::fetch(columns: ['name', 'value'])->asSQL(),
        );
    }

    public static function selectingProvider(): array
    {
        return [
            'replaces the default columns' => [
                static fn(): OwnedFile => OwnedFile::fetch()->without('owner')->selecting(['name', 'size']),
                'SELECT "name", "size" FROM "file" LIMIT 100',
            ],
            'replaces the fetch() columns' => [
                static fn(): OwnedFile => OwnedFile::fetch(['name'])->without('owner')->selecting('size'),
                'SELECT "size" FROM "file" LIMIT 100',
            ],
            'accepts several columns as separate arguments' => [
                static fn(): OwnedFile => OwnedFile::fetch()->without('owner')->selecting('name', 'size'),
                'SELECT "name", "size" FROM "file" LIMIT 100',
            ],
            'accepts strings and arrays together' => [
                static fn(): OwnedFile => OwnedFile::fetch()->without('owner')->selecting('name', ['size', 'ownerUUID']),
                'SELECT "name", "size", "ownerUUID" FROM "file" LIMIT 100',
            ],
            'repeated calls replace instead of adding' => [
                static fn(): OwnedFile => OwnedFile::fetch()->without('owner')->selecting(['name'])->selecting(['size']),
                'SELECT "size" FROM "file" LIMIT 100',
            ],
            'asQuery() columns still take precedence' => [
                static fn(): OwnedFile => OwnedFile::fetch()->without('owner')->selecting(['name']),
                'SELECT "size" FROM "file" LIMIT 100',
                ['size'],
            ],
            'relation columns are kept' => [
                static fn(): OwnedFile => OwnedFile::fetch()->selecting(['name']),
                'SELECT "name", "owner"."relation_rows" AS "owner" FROM "file"'
                    . ' LEFT JOIN LATERAL (SELECT to_jsonb("user".*) AS "relation_rows" FROM'
                    . ' (SELECT * FROM "user" WHERE "user"."userUUID" = "file"."ownerUUID" LIMIT 1) AS "user") AS "owner" ON true'
                    . ' LIMIT 100',
            ],
            'a wildcard argument is qualified when there are relations' => [
                static fn(): OwnedFile => OwnedFile::fetch()->selecting('*', 'name'),
                'SELECT "file".*, "name", "owner"."relation_rows" AS "owner" FROM "file"'
                    . ' LEFT JOIN LATERAL (SELECT to_jsonb("user".*) AS "relation_rows" FROM'
                    . ' (SELECT * FROM "user" WHERE "user"."userUUID" = "file"."ownerUUID" LIMIT 1) AS "user") AS "owner" ON true'
                    . ' LIMIT 100',
            ],
            'a wildcard inside an array argument is qualified when there are relations' => [
                static fn(): OwnedFile => OwnedFile::fetch()->selecting(['*', 'name']),
                'SELECT "file".*, "name", "owner"."relation_rows" AS "owner" FROM "file"'
                    . ' LEFT JOIN LATERAL (SELECT to_jsonb("user".*) AS "relation_rows" FROM'
                    . ' (SELECT * FROM "user" WHERE "user"."userUUID" = "file"."ownerUUID" LIMIT 1) AS "user") AS "owner" ON true'
                    . ' LIMIT 100',
            ],
        ];
    }

    #[DataProvider('selectingProvider')]
    public function testSelecting(callable $build, string $expected, ?array $asQueryColumns = null): void
    {
        $this->assertSame($expected, $build()->asQuery($asQueryColumns)->asSQL());
    }

    public function testSelectingDoesNotApplyToChange(): void
    {
        $this->assertSame(
            'UPDATE "attribute" SET "visible" = TRUE RETURNING *',
            FileAttribute::change(['visible' => true])->selecting(['name'])->asSQL(),
        );
    }

    public function testAsQueryColumnsReplaceFetchColumns(): void
    {
        $this->assertSame(
            'SELECT "value" FROM "attribute" LIMIT 100',
            FileAttribute::fetch(['name'])->asQuery(columns: ['value'])->asSQL(),
        );
    }

    public function testAsQueryReturningReplacesDefaultReturning(): void
    {
        $this->assertSame(
            'UPDATE "attribute" SET "visible" = TRUE RETURNING *',
            FileAttribute::change(['visible' => true])->asSQL(),
        );
        $this->assertSame(
            'UPDATE "attribute" SET "visible" = TRUE RETURNING "attributeUUID"',
            FileAttribute::change(['visible' => true])->asQuery(returning: 'attributeUUID')->asSQL(),
        );
    }
}
