<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\ConfigException;
use ActiveRecord\SoftDeletable;
use ActiveRecord\UndefinedPropertyException;

/**
 * Soft delete on the read side (spec §3.2, §3.3, §3.4 trashed(), §4.2, §4.4 steps 2 and 4, §6 errors 6, 7, 8, 13, 14):
 * the soft_deletes scope of a SoftDeletable model hides its trashed rows, with_trashed()/only_trashed()/without_trashed()
 * and the removal methods set the trashed state, the last call winning.
 *
 * Fixtures: trash_posts 1 A live, 2 B trashed, 3 C live draft, 4 D live; TrashPost also hides drafts (its published
 * scope). trash_dated 1 live, 2 removed on 2026-01-01.
 */
class SoftDeleteReadTest extends DatabaseTest
{
    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        return array_map(static fn($m) => (int) $m->id, $models);
    }

    public function test_reads_hide_trashed_rows_everywhere(): void
    {
        $this->assert_equals([1, 4], $this->ids(TrashPost::order_by('id')->get()));          // 2 trashed, 3 draft
        $this->assert_same(2, TrashPost::count());
        $this->assert_null(TrashPost::find('first', ['conditions' => ['code = ?', 'B']]));
        $this->assert_equals([1, 4], $this->ids(TrashPost::all(['order' => 'id'])));
        $this->assert_false(TrashPost::where('code', 'B')->exists());
    }

    public function test_with_only_without_trashed_and_last_call_wins(): void
    {
        $this->assert_equals([1, 2, 4], $this->ids(TrashPost::with_trashed()->order_by('id')->get()));
        $this->assert_equals([2], $this->ids(TrashPost::only_trashed()->get()));
        $this->assert_equals([1, 4], $this->ids(TrashPost::without_global_scopes()->without_trashed()->where('draft', 0)->order_by('id')->get()));
        $this->assert_equals([1, 2, 4], $this->ids(TrashPost::without_global_scope(SoftDeletable::SCOPE)->order_by('id')->get()));
        $this->assert_sql_doesnt_has('IS NULL', TrashPost::only_trashed()->to_sql());   // never both (Rails trap)

        // chained on one query, the last call wins
        $this->assert_equals([1, 4], $this->ids(TrashPost::with_trashed()->only_trashed()->without_trashed()->order_by('id')->get()));
        $this->assert_equals([2], $this->ids(TrashPost::without_trashed()->with_trashed()->only_trashed()->get()));
        $this->assert_equals([1, 2, 3, 4], $this->ids(TrashPost::without_trashed()->without_global_scopes()->order_by('id')->get()));
        $this->assert_equals([2], $this->ids(TrashPost::without_global_scopes()->only_trashed()->get()));
        $this->assert_equals([1, 3, 4], $this->ids(TrashPost::without_global_scope('published')->order_by('id')->get()));
    }

    public function test_removal_methods_set_the_trashed_state(): void
    {
        $this->assert_same(['all' => false, 'removed' => [], 'trashed' => 'with'], TrashPost::without_global_scope(SoftDeletable::SCOPE)->state()->scopes);
        $this->assert_same(['all' => true, 'removed' => [], 'trashed' => 'with'], TrashPost::without_global_scopes()->state()->scopes);
        $this->assert_same(['all' => false, 'removed' => ['published'], 'trashed' => 'with'], TrashPost::without_global_scopes([SoftDeletable::SCOPE, 'published'])->state()->scopes);
        $this->assert_same(['all' => false, 'removed' => ['published'], 'trashed' => 'default'], TrashPost::without_global_scopes(['published'])->state()->scopes);
        $this->assert_same(['all' => true, 'removed' => [], 'trashed' => 'without'], TrashPost::without_global_scopes()->without_trashed()->state()->scopes);
        $this->assert_same(['all' => false, 'removed' => [], 'trashed' => 'only'], TrashPost::with_trashed()->only_trashed()->state()->scopes);

        // the list form: both scopes lifted
        $this->assert_equals([1, 2, 3, 4], $this->ids(TrashPost::without_global_scopes([SoftDeletable::SCOPE, 'published'])->order_by('id')->get()));
    }

    // only_trashed() puts its group where soft_deletes would be: after the model's own published scope (spec §3.1)
    public function test_only_trashed_replaces_the_filter_in_its_position(): void
    {
        $sql = TrashPost::only_trashed()->where('code', 'B')->to_sql();

        $this->assert_sql_has('IS NOT NULL', $sql);
        $this->assert_true(strpos($sql, 'draft') < strpos($sql, 'IS NOT NULL'));
        $this->assert_true(strpos($sql, 'IS NOT NULL') < strpos($sql, 'code'));

        $sql = TrashPost::where('code', 'A')->to_sql();
        $this->assert_sql_has('deleted_at IS NULL', $sql);
        $this->assert_true(strpos($sql, 'draft') < strpos($sql, 'IS NULL'));
        $this->assert_sql_doesnt_has('NOT NULL', $sql);

        // with_trashed() leaves no filter on the column
        $this->assert_sql_doesnt_has('deleted_at', TrashPost::with_trashed()->to_sql());
    }

    public function test_trashed(): void
    {
        $this->assert_true(TrashPost::with_trashed()->find(2)->trashed());
        $this->assert_false(TrashPost::find(1)->trashed());

        $post = TrashPost::select('id')->find(1);
        $this->assert_exception_message_contains('deleted_at', fn() => $post->trashed(), UndefinedPropertyException::class);
    }

    public function test_date_column(): void
    {
        $this->assert_same(1, TrashDated::count());
        $this->assert_equals([2], $this->ids(TrashDated::only_trashed()->get()));
        $this->assert_equals([1, 2], $this->ids(TrashDated::with_trashed()->order_by('id')->get()));
        $this->assert_sql_has('removed_on IS NULL', TrashDated::to_sql());
        $this->assert_true(TrashDated::only_trashed()->find(2)->trashed());
        $this->assert_false(TrashDated::find(1)->trashed());
    }

    public function test_errors_6_7_8_14(): void
    {
        // 6: the trait without the interface, the interface without the trait
        $this->assert_exception_message_contains('TrashTraitOnly uses SoftDeletes but does not implement SoftDeletable', fn() => TrashTraitOnly::count(), ConfigException::class);
        $this->assert_exception_message_contains('TrashInterfaceOnly implements SoftDeletable but does not use SoftDeletes', fn() => TrashInterfaceOnly::count(), ConfigException::class);
        $this->assert_exception_message_contains('TrashInterfaceOnly implements SoftDeletable but does not use SoftDeletes', fn() => TrashInterfaceOnly::with_trashed(), ConfigException::class);

        // 7: no column of the table, at compile time and at the *_trashed() call
        $this->assert_exception_message_contains('TrashDatedNoColumn::soft_delete_column() names nope, which is not a column of trash_dated', fn() => TrashDatedNoColumn::count(), ConfigException::class);
        $this->assert_exception_message_contains('TrashDatedNoColumn::soft_delete_column() names nope, which is not a column of trash_dated', fn() => TrashDatedNoColumn::only_trashed(), ConfigException::class);

        // 8: a column of another type
        $this->assert_exception_message_contains('TrashWrong::soft_delete_column() names deleted_at, a ', fn() => TrashWrong::count(), ConfigException::class);
        $this->assert_exception_message_contains(' column: soft delete needs a DATE or DATETIME column', fn() => TrashWrong::with_trashed(), ConfigException::class);

        // 14: a model that is not SoftDeletable
        $this->assert_exception_message_contains('with_trashed() needs a model with soft delete: Author is not SoftDeletable', fn() => Author::with_trashed(), ActiveRecordException::class);
        $this->assert_exception_message_contains('only_trashed() needs a model with soft delete: GlobalScopedAuthor is not SoftDeletable', fn() => GlobalScopedAuthor::only_trashed(), ActiveRecordException::class);
        $this->assert_exception_message_contains('without_trashed() needs a model with soft delete: Author is not SoftDeletable', fn() => Author::new_query()->without_trashed(), ActiveRecordException::class);
    }

    // 13: the trashed methods carry no meaning on a where() group's query
    public function test_trashed_methods_refused_on_a_group(): void
    {
        $this->assert_exception_message_contains(
            "with_trashed() cannot be used on a where() group or on a global scope's query",
            fn() => TrashPost::where(fn($q) => $q->with_trashed())->get(),
            ActiveRecordException::class,
        );
    }

    // a throwing call leaves the query as it was
    public function test_a_refused_call_leaves_the_query_alone(): void
    {
        $query = Author::new_query();

        try {
            $query->only_trashed();
        } catch (ActiveRecordException) {
        }

        $this->assert_same(['all' => false, 'removed' => [], 'trashed' => 'default'], $query->state()->scopes);
    }

    // the relationship models load (they are exercised by the relationship tasks)
    public function test_the_soft_delete_models_read(): void
    {
        $this->assert_same(2, TrashComment::count());
        $this->assert_same(2, TrashTag::count());
        $this->assert_same(2, TrashTagging::count());
        $this->assert_same(2, TrashVote::count());
        $this->assert_same(1, TrashPkless::count());
        $this->assert_same(2, TrashPkless::with_trashed()->count());
    }
}
