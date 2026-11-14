<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\Tests\Relentity\Fixtures\{
    FileAttribute,
    FileTag,
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

    public function testLimitDoesNotApplyToDelete(): void
    {
        $this->assertSame(
            'DELETE FROM "attribute" WHERE "visible" = FALSE RETURNING *',
            FileAttribute::delete()->matching(['visible' => false])->limitedTo(5, skipping: 10)->asSQL(),
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

    public function testFetchColumns(): void
    {
        $this->assertSame(
            'SELECT "name", "value" FROM "attribute" LIMIT 100',
            FileAttribute::fetch(columns: ['name', 'value'])->asSQL(),
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
            FileAttribute::update(['visible' => true])->asSQL(),
        );
        $this->assertSame(
            'UPDATE "attribute" SET "visible" = TRUE RETURNING "attributeUUID"',
            FileAttribute::update(['visible' => true])->asQuery(returning: 'attributeUUID')->asSQL(),
        );
    }
}
