<?php

namespace Tests\Feature\Support;

use App\Models\Concerns\SearchesDatabase;
use App\Support\SearchField;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds a throwaway Eloquent model (backed by the existing `eps` table) that
 * searches a single column with the given mode, so we can assert the exact
 * SQL/bindings the trait emits per mode. No rows are queried.
 */
function modelSearching(SearchField $field): Model
{
    return new class($field) extends Model
    {
        public static SearchField $field;

        protected $table = 'eps';

        protected $guarded = [];

        public function __construct(?SearchField $field = null, array $attributes = [])
        {
            if ($field !== null) {
                self::$field = $field;
            }
            parent::__construct($attributes);
        }

        use SearchesDatabase;

        public function searchableColumns(): array
        {
            return [self::$field];
        }
    };
}

test('SearchField constructors set mode and normalize columns', function (): void {
    expect(SearchField::exact('code')->mode)->toBe(SearchField::EXACT)
        ->and(SearchField::exact('code')->columns)->toBe(['code'])
        ->and(SearchField::prefix('code')->mode)->toBe(SearchField::PREFIX)
        ->and(SearchField::substring('code')->mode)->toBe(SearchField::SUBSTRING)
        ->and(SearchField::fuzzy('code')->mode)->toBe(SearchField::FUZZY)
        ->and(SearchField::fuzzy(['a', 'b'])->columns)->toBe(['a', 'b'])
        ->and(SearchField::fuzzy('x')->isFuzzy())->toBeTrue()
        ->and(SearchField::substring('x')->isFuzzy())->toBeFalse();
});

test('exact mode emits case-insensitive equality', function (): void {
    $query = modelSearching(SearchField::exact('code'))->newQuery()->search('Foo');

    expect($query->toSql())->toContain('lower(')
        ->and($query->toSql())->toContain('= lower(?)')
        ->and($query->getBindings())->toBe(['Foo']);
});

test('prefix mode binds a trailing wildcard only', function (): void {
    $query = modelSearching(SearchField::prefix('code'))->newQuery()->search('Foo');

    expect($query->getBindings())->toBe(['Foo%']);
});

test('substring mode binds wrapping wildcards', function (): void {
    $query = modelSearching(SearchField::substring('code'))->newQuery()->search('Foo');

    expect($query->getBindings())->toBe(['%Foo%']);
});

test('fuzzy mode on a non-postgres driver degrades to a plain substring like', function (): void {
    $query = modelSearching(SearchField::fuzzy('code'))->newQuery()->search('Foo');

    // SQLite has no trigram support, so no word_similarity clause is added.
    expect($query->toSql())->not->toContain('word_similarity')
        ->and($query->getBindings())->toBe(['%Foo%']);
});

test('blank term applies no constraints', function (): void {
    $query = modelSearching(SearchField::substring('code'))->newQuery()->search('   ');

    expect($query->getBindings())->toBe([]);
});
