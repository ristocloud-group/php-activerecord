<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\ConfigException;
use ActiveRecord\Timestamps;

/**
 * Soft delete on the bulk write side (spec §3.3, §3.4 force_delete_all(), §4.2, §5 "Now", §5.1, §5.2, §6 error 16):
 * Query::delete() and Model::delete_all() of a SoftDeletable model UPDATE the soft-delete column and updated_at to one
 * "now", Query::force_delete() and force_delete_all() DELETE with the soft-delete filter lifted unless the caller set it,
 * every other scope kept, and a static force_delete is refused as update/delete are.
 *
 * Fixtures: trash_posts 1 A live, 2 B trashed, 3 C live draft, 4 D live; TrashPost also hides drafts (its published
 * scope). trash_dated 1 live, 2 removed on 2026-01-01.
 */
class SoftDeleteWriteTest extends DatabaseTest
{
    public function tear_down(): void
    {
        Timestamps::freeze(null);
        parent::tear_down();
    }

    /**
     * @return list<int> the ids of every trash_posts row, trashed and drafts included
     */
    private function all_ids(): array
    {
        return array_map(static fn($m) => (int) $m->id, TrashPost::without_global_scopes()->order_by('id')->get());
    }

    public function test_bulk_delete_is_soft_with_one_now(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        $this->assert_same(1, TrashPost::where('code', 'A')->delete());
        $row = TrashPost::with_trashed()->find(1);
        $this->assert_equals('2026-11-18 10:00:00', $row->deleted_at->format('Y-m-d H:i:s'));
        $this->assert_equals($row->deleted_at, $row->updated_at);
        $this->assert_same(1, TrashPost::delete_all(['conditions' => ['code = ?', 'D']]));
        $this->assert_same(0, TrashPost::count());
        $this->assert_equals([1, 2, 3, 4], $this->all_ids());   // nothing removed
        $this->assert_equals('2026-11-18 10:00:00', TrashPost::with_trashed()->find(4)->deleted_at->format('Y-m-d H:i:s'));
    }

    public function test_bulk_delete_sql_is_an_update_of_the_column(): void
    {
        TrashPost::where('code', 'A')->delete();
        $sql = str_replace(['`', '"'], '', TrashPost::table()->last_sql);
        $this->assert_same(1, preg_match('/^UPDATE trash_posts SET deleted_at=\?, updated_at=\? WHERE \(draft = \?\) AND \(deleted_at IS NULL\) AND \(code = \?\)$/', $sql), $sql);

        TrashPost::delete_all(['conditions' => ['code = ?', 'D']]);
        $sql = str_replace(['`', '"'], '', TrashPost::table()->last_sql);
        $this->assert_same(1, preg_match('/^UPDATE trash_posts SET deleted_at=\?, updated_at=\? WHERE/', $sql), $sql);
    }

    public function test_one_now_with_a_real_clock(): void
    {
        $this->assert_same(1, TrashPost::where('code', 'A')->delete());
        $row = TrashPost::with_trashed()->find(1);
        $this->assert_not_null($row->deleted_at);
        $this->assert_equals($row->deleted_at, $row->updated_at);

        $this->assert_same(1, TrashPost::delete_all(['conditions' => ['code = ?', 'D']]));
        $row = TrashPost::with_trashed()->find(4);
        $this->assert_not_null($row->deleted_at);
        $this->assert_equals($row->deleted_at, $row->updated_at);
    }

    public function test_without_timestamps_leaves_updated_at(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        TrashPost::without_timestamps(function (): void {
            $this->assert_same(1, TrashPost::where('code', 'A')->delete());
            $this->assert_same(1, TrashPost::delete_all(['conditions' => ['code = ?', 'D']]));
        });

        foreach ([1, 4] as $id) {
            $row = TrashPost::with_trashed()->find($id);
            $this->assert_equals('2026-11-18 10:00:00', $row->deleted_at->format('Y-m-d H:i:s'));
            $this->assert_null($row->updated_at);
        }
    }

    public function test_with_trashed_delete_restamps(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        $this->assert_same(1, TrashPost::with_trashed()->where('code', 'B')->delete());
        $this->assert_equals('2026-11-18 10:00:00', TrashPost::with_trashed()->find(2)->deleted_at->format('Y-m-d H:i:s'));

        // the default filter hides row 2: a plain delete does not reach it
        Timestamps::freeze('2026-11-19 10:00:00');
        $this->assert_same(0, TrashPost::where('code', 'B')->delete());
        $this->assert_equals('2026-11-18 10:00:00', TrashPost::with_trashed()->find(2)->deleted_at->format('Y-m-d H:i:s'));
    }

    public function test_bulk_delete_of_a_date_column_writes_a_date(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        $this->assert_same(1, TrashDated::where('id', 1)->delete());
        $this->assert_equals('2026-11-18', TrashDated::with_trashed()->find(1)->removed_on->format('Y-m-d'));
        $this->assert_same(0, TrashDated::count());
        $this->assert_same(0, TrashDated::delete_all());   // nothing live left
    }

    public function test_force_delete_respects_only_and_without_trashed_and_other_scopes(): void
    {
        $this->assert_same(1, TrashPost::only_trashed()->force_delete());                        // row 2 only
        $this->assert_same(4 - 1, TrashPost::without_global_scopes()->with_trashed()->count());  // 3 left
        $this->assert_same(2, TrashPost::without_trashed()->force_delete());                      // rows 1, 4 (draft 3 kept by published)
        $this->assert_same(1, TrashPost::without_global_scope('published')->force_delete());     // row 3
        $this->assert_equals([], $this->all_ids());
    }

    public function test_force_delete_lifts_the_default_filter_only(): void
    {
        $this->assert_same(2, TrashPost::where_in('code', ['A', 'B', 'C'])->force_delete());   // A live, B trashed; C draft kept
        $sql = str_replace(['`', '"'], '', TrashPost::table()->last_sql);
        $this->assert_same(1, preg_match('/^DELETE FROM trash_posts WHERE \(draft = \?\) AND \(code IN/', $sql), $sql);
        $this->assert_equals([3, 4], $this->all_ids());
    }

    public function test_force_delete_leaves_the_query_unchanged(): void
    {
        $q = TrashPost::where_in('code', ['A', 'B']);
        $sql = $q->to_sql();
        $this->assert_same(2, $q->force_delete());   // A live, B trashed
        $this->assert_same('default', $q->state()->scopes['trashed']);
        $this->assert_same($sql, $q->to_sql());
        $this->assert_sql_has('deleted_at IS NULL', str_replace(['`', '"'], '', $q->to_sql()));

        // a trashed row back in the query's reach: the query still hides it
        TrashPost::create(['code' => 'A', 'title' => 'again', 'draft' => 0, 'deleted_at' => '2026-01-01 00:00:00']);
        $this->assert_equals([], $q->get());
        $this->assert_same(1, $q->clone()->with_trashed()->count());
    }

    public function test_force_delete_refusals_name_force_delete(): void
    {
        $this->assert_exception_message_contains('force_delete() cannot use group_by', fn() => TrashPost::new_query()->group_by('id')->force_delete(), ActiveRecordException::class);
        $this->assert_exception_message_contains('force_delete() on TrashPost has where() calls', fn() => TrashPost::where([])->force_delete(), ActiveRecordException::class);
        $this->assert_exception_message_contains('TrashPost::new_query()->force_delete()', fn() => TrashPost::where([])->force_delete(), ActiveRecordException::class);
        $this->assert_exception_message_contains('delete() cannot use group_by', fn() => Author::new_query()->group_by('author_id')->force_delete(), ActiveRecordException::class);
        $this->assert_equals([1, 2, 3, 4], $this->all_ids());
    }

    public function test_force_delete_on_a_model_without_soft_delete_is_delete(): void
    {
        $this->assert_same(1, Author::where('author_id', 4)->force_delete());
        $this->assert_null(Author::find('first', ['conditions' => ['author_id = ?', 4]]));
        $this->assert_same(1, GlobalScopedAuthor::where_in('author_id', [1, 2])->force_delete());   // 2 hidden
        $this->assert_not_null(Author::find('first', ['conditions' => ['author_id = ?', 2]]));
    }

    public function test_force_delete_all_and_static_refusal(): void
    {
        // trashed B and live A hard-deleted, draft C kept by published
        $this->assert_same(2, TrashPost::force_delete_all(['conditions' => ['code IN (?)', ['A', 'B', 'C']]]));
        $sql = str_replace(['`', '"'], '', TrashPost::table()->last_sql);
        $this->assert_same(1, preg_match('/^DELETE FROM trash_posts WHERE /', $sql), $sql);
        $this->assert_sql_doesnt_has('deleted_at', $sql);
        $this->assert_equals([3, 4], $this->all_ids());

        // delete_all()'s option shapes: a bare conditions hash, a string, and every row
        $this->assert_same(1, TrashPost::force_delete_all(['code' => 'D']));
        $this->assert_same(0, TrashPost::force_delete_all("code = 'C'"));   // draft: hidden by published
        $this->assert_same(0, TrashPost::force_delete_all());
        $this->assert_equals([3], $this->all_ids());

        $this->assert_exception_message_contains(
            'Author::force_delete() would write every row: use Author::where(...)->force_delete(...) or Author::new_query()->force_delete(...)',
            fn() => Author::force_delete(),
            ActiveRecordException::class,
        );
    }

    public function test_force_delete_all_keeps_delete_all_checks(): void
    {
        $this->assert_exception_message_contains('Invalid options for force_delete_all(): pass positional conditions', fn() => TrashPost::force_delete_all(['code = ?', 'A']), ActiveRecordException::class);
        $this->assert_exception_message_contains('Invalid options for force_delete_all(): expected an options hash', fn() => TrashPost::force_delete_all(1), ActiveRecordException::class);
        $this->assert_exception_message_contains('Unknown key(s): code', fn() => TrashPost::force_delete_all(['conditions' => 'id = 1', 'code' => 'A']), ActiveRecordException::class);
        $this->assert_exception_message_contains('statement terminator', fn() => TrashPost::force_delete_all(['conditions' => 'id = 1; DELETE FROM trash_posts']), ActiveRecordException::class);
        $this->assert_equals([1, 2, 3, 4], $this->all_ids());
    }

    public function test_refusal_names_delete(): void
    {
        $this->assert_exception_message_contains('delete() cannot use group_by', fn() => TrashPost::new_query()->group_by('id')->delete(), ActiveRecordException::class);
        $this->assert_exception_message_contains('delete() on TrashPost has where() calls', fn() => TrashPost::where([])->delete(), ActiveRecordException::class);
        $this->assert_exception_message_contains('update() cannot use group_by', fn() => TrashPost::new_query()->group_by('id')->update(['title' => 'x']), ActiveRecordException::class);
        $this->assert_equals([1, 2, 3, 4], $this->all_ids());
        $this->assert_same(2, TrashPost::count());
    }

    public function test_limit_on_postgres(): void
    {
        $this->assert_same(1, TrashPost::where('draft', 0)->order_by('id')->limit(1)->delete());
        $this->assert_not_null(TrashPost::with_trashed()->find(1)->deleted_at);
        $this->assert_same(1, TrashPost::count());   // row 4
        $this->assert_equals([1, 2, 3, 4], $this->all_ids());

        $this->assert_same(1, TrashPost::with_trashed()->order_by_desc('id')->limit(1)->force_delete());   // row 4
        $this->assert_equals([1, 2, 3], $this->all_ids());
    }

    public function test_configuration_errors_come_before_any_statement(): void
    {
        $this->assert_exception_message_contains('TrashInterfaceOnly implements SoftDeletable but does not use SoftDeletes', fn() => TrashInterfaceOnly::where('id', 1)->delete(), ConfigException::class);
        $this->assert_exception_message_contains('TrashInterfaceOnly implements SoftDeletable but does not use SoftDeletes', fn() => TrashInterfaceOnly::delete_all(['id' => 1]), ConfigException::class);
        $this->assert_exception_message_contains('names nope, which is not a column', fn() => TrashDatedNoColumn::where('id', 1)->delete(), ConfigException::class);
        $this->assert_exception_message_contains('names nope, which is not a column', fn() => TrashDatedNoColumn::delete_all(['id' => 1]), ConfigException::class);
        $this->assert_exception_message_contains('names nope, which is not a column', fn() => TrashDatedNoColumn::force_delete_all(['id' => 1]), ConfigException::class);
        $this->assert_same(2, TrashDated::with_trashed()->count());
    }
}
