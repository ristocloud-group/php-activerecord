<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\BelongsTo;
use ActiveRecord\HasMany;
use ActiveRecord\HasOne;
use ActiveRecord\RelationshipException;

/**
 * Relationship loads and global scopes (spec §3.5, §4.5, §4.6, §6 errors 12 and 18): a load is filtered by the
 * target's scopes, and the relationship option 'unscoped' lifts them, lazily and eagerly.
 *
 * Fixtures: authors 1 Tito (parent 3), 2 George W. Bush (parent 2), 3 Bill Clinton (parent 1), 4 Uncle Bob (parent 2);
 * books 1 (author 1), 2 (author 2). GlobalScopedAuthor and its subclasses hide author 2.
 */
class GlobalScopeRelationshipTest extends DatabaseTest
{
    use CapturesStatements;

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        GlobalScopedRecordingAuthor::$calls = [];
    }

    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(static fn($m) => (int) $m->author_id, $models);
    }

    public function test_belongs_to_target_scope_lazy_and_eager(): void
    {
        $book = GlobalScopedBook::find(2);              // author_id 2 is hidden
        $this->assert_null($book->author);
        $this->assert_same(2, $book->any_author->author_id);
        $eager = GlobalScopedBook::find(2, ['include' => ['author', 'any_author']]);
        $this->assert_null($eager->author);
        $this->assert_same(2, $eager->any_author->author_id);
    }

    public function test_has_many_and_has_one_unscoped_lazy_and_eager(): void
    {
        // children of author 2: 2 and 4; the scope hides 2
        $owner = GlobalScopedRecordingAuthor::without_global_scope('not_bush')->find(2);
        $this->assert_same([4], $this->ids($owner->children));
        $this->assert_same([2, 4], $this->ids($owner->any_children));
        // has_one at offset 1 of author_id desc: scoped [4] has none, unscoped [4, 2] gives 2
        $this->assert_null($owner->second_child);
        $this->assert_same(2, $owner->any_second_child->author_id);

        $eager = GlobalScopedRecordingAuthor::without_global_scope('not_bush')
            ->with('children', 'any_children', 'second_child', 'any_second_child')->find(2);
        $this->assert_same([4], $this->ids($eager->children));
        $this->assert_same([2, 4], $this->ids($eager->any_children));
        $this->assert_null($eager->second_child);
        $this->assert_same(2, $eager->any_second_child->author_id);
    }

    public function test_unscoped_option_validation_and_unknown_name(): void
    {
        $this->assert_exception_message_contains("must be true or a list of scope names", fn() => new ActiveRecord\BelongsTo(['author', 'unscoped' => 'x']), RelationshipException::class);

        foreach ([false, null, 1, 'not_bush', ['not_bush', 3], ['a' => 'not_bush'], [1 => 'not_bush']] as $value) {
            $this->assert_exception_message_contains(
                "The option 'unscoped' of relationship 'books' must be true or a list of scope names",
                fn() => new HasMany(['books', 'unscoped' => $value]),
                RelationshipException::class
            );
        }

        // a name the target does not have: the owner's Table loads, the first load throws error 12, lazy and eager
        $book = GlobalScopedBook::find(1);
        $error = 'GlobalScopedAuthor has no global scope named nope: its global scopes are not_bush';
        $this->assert_exception_message_contains($error, fn() => $book->nope_author, ActiveRecordException::class);
        $this->assert_exception_message_contains($error, fn() => GlobalScopedBook::find(1, ['include' => ['nope_author']]), ActiveRecordException::class);
    }

    public function test_unscoped_accepted_by_belongs_to_has_many_has_one(): void
    {
        foreach ([true, ['not_bush'], ['any', 'names'], []] as $value) {
            $this->assert_instance_of(BelongsTo::class, new BelongsTo(['author', 'unscoped' => $value]));
            $this->assert_instance_of(HasMany::class, new HasMany(['books', 'unscoped' => $value]));
            $this->assert_instance_of(HasOne::class, new HasOne(['book', 'unscoped' => $value]));
        }

        // HasOne declares its own list now: it still takes every option it took through HasMany
        $this->assert_instance_of(HasOne::class, new HasOne(['book', 'primary_key' => 'book_id', 'order' => 'name', 'group' => 'name',
            'having' => 'name', 'limit' => 1, 'offset' => 0, 'through' => 'books', 'source' => 'Book', 'unscoped' => true]));

        $this->assert_exception_message_contains(
            "Unknown option 'bogus' for relationship 'book'. Valid options: class_name, class, foreign_key, conditions, select, readonly, namespace, primary_key, order, group, having, limit, offset, through, source, unscoped.",
            fn() => new HasOne(['book', 'bogus' => true]),
            RelationshipException::class
        );
    }

    public function test_unscoped_load_skips_find_override_and_plain_load_calls_it(): void
    {
        $owner = GlobalScopedRecordingAuthor::without_global_scope('not_bush')->find(4);   // parent 2
        $this->assert_same([], GlobalScopedRecordingAuthor::$calls);

        // a plain load calls the public methods as before: belongs_to first(), has_many find('all'), has_one find('first')
        $this->assert_null($owner->parent);
        $this->assert_same([], $owner->children);
        $this->assert_null($owner->second_child);
        $this->assert_same(['first', 'find first', 'find all', 'find first'], GlobalScopedRecordingAuthor::$calls);

        // an unscoped load goes through neither
        GlobalScopedRecordingAuthor::$calls = [];
        $this->assert_same(2, $owner->any_parent->author_id);
        $this->assert_same([], $owner->any_children);
        $this->assert_null($owner->any_second_child);
        $this->assert_same([], GlobalScopedRecordingAuthor::$calls);

        // eager: the owner's find('all'), then one find('all') per plain include and none per unscoped one
        GlobalScopedRecordingAuthor::find('all', ['include' => ['any_parent', 'any_children', 'any_second_child']]);
        $this->assert_same(['find all'], GlobalScopedRecordingAuthor::$calls);

        GlobalScopedRecordingAuthor::$calls = [];
        GlobalScopedRecordingAuthor::find('all', ['include' => ['parent', 'children', 'second_child']]);
        $this->assert_same(['find all', 'find all', 'find all', 'find all'], GlobalScopedRecordingAuthor::$calls);
    }

    public function test_empty_unscoped_list_loads_as_without_the_option(): void
    {
        // 'unscoped' => [] removes nothing: the loads are scoped and go through the public find()/first(), as plain ones
        $owner = GlobalScopedRecordingAuthor::without_global_scope('not_bush')->find(2);   // parent 2, children 2 and 4

        $this->assert_null($owner->empty_unscoped_parent);
        $this->assert_same([4], $this->ids($owner->empty_unscoped_children));
        $this->assert_null($owner->empty_unscoped_second_child);
        $this->assert_same(['first', 'find first', 'find all', 'find first'], GlobalScopedRecordingAuthor::$calls);

        GlobalScopedRecordingAuthor::$calls = [];
        $eager = GlobalScopedRecordingAuthor::find(4, ['include' => ['empty_unscoped_parent', 'empty_unscoped_children', 'empty_unscoped_second_child']]);
        $this->assert_null($eager->empty_unscoped_parent);
        $this->assert_same([], $eager->empty_unscoped_children);
        $this->assert_null($eager->empty_unscoped_second_child);
        $this->assert_same(['find pk', 'find all', 'find all', 'find all'], GlobalScopedRecordingAuthor::$calls);
    }

    public function test_nested_same_class_include_inside_unscoped_load_keeps_its_scopes(): void
    {
        // any_author lifts the scope for its own load only: author 2's books load, and their (scoped) author is hidden
        $eager = GlobalScopedBook::find(2, ['include' => ['any_author' => ['books' => ['author', 'any_author']]]]);
        $this->assert_same(2, $eager->any_author->author_id);
        $this->assert_same([2], array_map(static fn($b) => (int) $b->book_id, $eager->any_author->books));
        $this->assert_null($eager->any_author->books[0]->author);
        $this->assert_same(2, $eager->any_author->books[0]->any_author->author_id);

        $lazy = GlobalScopedBook::find(2);
        $this->assert_null($lazy->any_author->books[0]->author);
        $this->assert_same(2, $lazy->any_author->books[0]->any_author->author_id);
    }

    public function test_declared_or_conditions_with_target_scope(): void
    {
        // Review Focus 3: book 2's author (2) matches the declared OR but the target's scope hides it
        $lazy = [];
        $lazy_sql = $this->statements(function () use (&$lazy) {
            foreach (GlobalScopedBookAuthorOr::find('all', ['order' => 'book_id']) as $book) {
                $lazy[$book->book_id] = $this->ids($book->listed_authors);
            }
        });

        $eager = [];
        $eager_sql = $this->statements(function () use (&$eager) {
            foreach (GlobalScopedBookAuthorOr::find('all', ['order' => 'book_id', 'include' => 'listed_authors']) as $book) {
                $eager[$book->book_id] = $this->ids($book->listed_authors);
            }
        });

        $this->assert_same([1 => [1], 2 => []], $lazy);
        $this->assert_same($lazy, $eager);

        // (scope) AND ((<declared>) AND <key>)
        $declared = preg_quote("(name = 'Tito' OR name = 'George W. Bush')", '/');
        $this->assert_matches_regular_expression("/WHERE \\([^()]*author_id[^()]* != \\?\\) AND \\($declared AND [^()]*author_id[^()]*\\s*=\\s*\\?\\)/", implode("\n", $lazy_sql));
        $this->assert_matches_regular_expression("/WHERE \\([^()]*author_id[^()]* != \\?\\) AND \\($declared AND [^()]*author_id[^()]*\\s*IN\\s*\\(\\?,\\s*\\?\\)\\)/", implode("\n", $eager_sql));
    }
}
