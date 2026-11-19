<?php

use ActiveRecord\ActiveRecordException;
use ActiveRecord\ConfigException;
use ActiveRecord\Timestamps;

/**
 * Soft delete on the instance side (spec §3.4, §3.5 callbacks, §5 "Now", §5.3 to §5.6, §6 errors 15 and 17, §7 rows 3,
 * 4 and 10): delete() of a SoftDeletable record UPDATEs its soft-delete column and updated_at by primary key, restore()
 * sets the column back to NULL between before_restore and after_restore, force_delete() is the hard delete, with
 * is_force_deleting() true while it runs.
 *
 * Fixtures: trash_posts 1 A live, 2 B trashed, 3 C live draft, 4 D live; TrashPost also hides drafts (its published
 * scope). trash_dated 1 live, 2 removed on 2026-01-01. trash_pkless (no primary key) 1 live, 2 trashed. trash_comments
 * has no updated_at.
 */
class SoftDeleteInstanceTest extends DatabaseTest
{
    public function set_up($connection_name = null): void
    {
        parent::set_up($connection_name);
        TrashPostWithCallbacks::reset();
    }

    public function tear_down(): void
    {
        Timestamps::freeze(null);
        TrashPostWithCallbacks::reset();
        parent::tear_down();
    }

    private function raw_row(string $table, int $id): array
    {
        $values = [$id];

        return array_change_key_case(TrashPost::connection()->query("SELECT * FROM $table WHERE id = ?", $values)->fetch(), CASE_LOWER);
    }

    public function test_instance_delete_is_soft_and_object_stays_writable(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        $post = TrashPost::find(1);
        $post->title = 'pending';
        $this->assert_true($post->delete());

        // by primary key, no scope, only the column and updated_at
        $sql = str_replace(['`', '"'], '', TrashPost::table()->last_sql);
        $this->assert_same(1, preg_match('/^UPDATE trash_posts SET deleted_at=\?, updated_at=\? WHERE id\s*=\s*\?$/', $sql), $sql);

        $this->assert_true($post->trashed());
        $this->assert_instance_of(ActiveRecord\DateTime::class, $post->deleted_at);
        $this->assert_instance_of(ActiveRecord\DateTime::class, $post->updated_at);
        $this->assert_equals('2026-11-18 10:00:00', $post->deleted_at->format('Y-m-d H:i:s'));
        $this->assert_equals('2026-11-18 10:00:00', $post->updated_at->format('Y-m-d H:i:s'));
        $this->assert_same(['title'], array_keys($post->dirty_attributes()));   // other pending change untouched, column clean
        $this->assert_null(TrashPost::find('first', ['conditions' => ['id = ?', 1]]));

        $row = $this->raw_row('trash_posts', 1);
        $this->assert_equals('live', $row['title']);   // the pending title was not written
        $this->assert_same(0, strpos((string) $row['deleted_at'], '2026-11-18 10:00:00'));

        // re-stamps, never hard
        Timestamps::freeze('2026-11-19 11:00:00');
        $this->assert_true($post->delete());
        $this->assert_same(1, TrashPost::with_trashed()->where('id', 1)->count());
        $this->assert_equals('2026-11-19 11:00:00', $post->deleted_at->format('Y-m-d H:i:s'));
        $this->assert_equals('2026-11-19 11:00:00', TrashPost::with_trashed()->find(1)->deleted_at->format('Y-m-d H:i:s'));

        // not destroyed: the object can still be written
        $this->assert_true($post->save());
        $reloaded = TrashPost::with_trashed()->find(1);
        $this->assert_equals('pending', $reloaded->title);
        $this->assert_true($reloaded->trashed());

        // a delete() on a record already trashed when it was read re-stamps it too
        $this->assert_true(TrashPost::with_trashed()->find(2)->delete());
        $this->assert_equals('2026-11-19 11:00:00', TrashPost::with_trashed()->find(2)->deleted_at->format('Y-m-d H:i:s'));
    }

    public function test_restore_and_callbacks_order_and_cancel(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        $post = TrashPostWithCallbacks::with_trashed()->find(2);
        $post->title = 'pending';

        TrashPostWithCallbacks::$cancel_restore = true;
        $this->assert_false($post->restore());
        $this->assert_same(['before_restore true'], TrashPostWithCallbacks::$log);
        $this->assert_true($post->trashed());
        $this->assert_true(TrashPost::with_trashed()->find(2)->trashed());

        TrashPostWithCallbacks::reset();
        $this->assert_true($post->restore());
        $sql = str_replace(['`', '"'], '', TrashPostWithCallbacks::table()->last_sql);
        $this->assert_same(1, preg_match('/^UPDATE trash_posts SET deleted_at=\?, updated_at=\? WHERE id\s*=\s*\?$/', $sql), $sql);
        $this->assert_same(['before_restore true', 'after_restore false'], TrashPostWithCallbacks::$log);
        $this->assert_null($post->deleted_at);
        $this->assert_false($post->trashed());
        $this->assert_equals('2026-11-18 10:00:00', $post->updated_at->format('Y-m-d H:i:s'));
        $this->assert_same(['title'], array_keys($post->dirty_attributes()));

        $row = TrashPost::find(2);   // visible again
        $this->assert_null($row->deleted_at);
        $this->assert_equals('trashed', $row->title);   // the pending title was not written
        $this->assert_equals('2026-11-18 10:00:00', $row->updated_at->format('Y-m-d H:i:s'));

        // a record that is not trashed is restored too (Eloquent)
        TrashPostWithCallbacks::reset();
        $this->assert_true(TrashPostWithCallbacks::find(1)->restore());
        $this->assert_same(['before_restore false', 'after_restore false'], TrashPostWithCallbacks::$log);

        // the soft delete runs the destroy callbacks, never the restore ones
        TrashPostWithCallbacks::reset();
        $this->assert_true($post->delete());
        $this->assert_same(['before_destroy false force false', 'after_destroy true force false'], TrashPostWithCallbacks::$log);

        // the restore of a record read without its scopes, and the DATE column: the types a find gives
        $dated = TrashDated::only_trashed()->find(2);
        $this->assert_true($dated->restore());
        $this->assert_null($dated->removed_on);
        $this->assert_null($dated->dirty_attributes());
        $this->assert_same(1, TrashDated::where('id', 2)->count());
        $this->assert_true($dated->delete());
        $this->assert_instance_of(ActiveRecord\DateTime::class, $dated->removed_on);
        $this->assert_equals('2026-11-18 00:00:00', $dated->removed_on->format('Y-m-d H:i:s'));
        $this->assert_equals('2026-11-18 00:00:00', TrashDated::with_trashed()->find(2)->removed_on->format('Y-m-d H:i:s'));
        $this->assert_null($dated->dirty_attributes());
    }

    public function test_restore_refusals(): void
    {
        $post = TrashPost::with_trashed()->find(2);
        $post->readonly();
        $this->assert_exception_message_contains('restore', fn() => $post->restore(), ActiveRecord\ReadOnlyException::class);

        $post = TrashPost::with_trashed()->find(2);
        $post->id = null;
        $this->assert_exception_message_contains('Cannot restore, primary key value is null for: TrashPost (id)', fn() => $post->restore(), ActiveRecordException::class);

        $post = TrashPost::with_trashed()->find(2);
        $post->id = 3;
        $this->assert_exception_message_contains('Cannot restore, primary key changed for: TrashPost', fn() => $post->restore(), ActiveRecordException::class);

        $post = TrashPost::find(1);
        $post->force_delete();
        $this->assert_exception_message_contains('Cannot restore, record has been deleted: TrashPost', fn() => $post->restore(), ActiveRecordException::class);

        $this->assert_true(TrashPost::with_trashed()->find(2)->trashed());   // nothing restored
    }

    public function test_force_delete_and_flag(): void
    {
        $post = TrashPostWithCallbacks::find(1);
        $this->assert_false($post->is_force_deleting());
        $this->assert_true($post->force_delete());
        $sql = str_replace(['`', '"'], '', TrashPostWithCallbacks::table()->last_sql);
        $this->assert_same(1, preg_match('/^DELETE FROM trash_posts WHERE id\s*=\s*\?$/', $sql), $sql);
        $this->assert_same(['before_destroy false force true', 'after_destroy false force true'], TrashPostWithCallbacks::$log);
        $this->assert_false($post->is_force_deleting());
        $this->assert_same(0, TrashPost::without_global_scopes()->where('id', 1)->count());
        $this->assert_exception_message_contains('Cannot delete, record has been deleted', fn() => $post->delete(), ActiveRecordException::class);

        // a trashed record goes too
        $this->assert_true(TrashPostWithCallbacks::with_trashed()->find(2)->force_delete());
        $this->assert_same(0, TrashPost::without_global_scopes()->where('id', 2)->count());

        // a throwing before_destroy clears the flag: a later delete() is soft
        $post = TrashPostWithCallbacks::find(4);
        TrashPostWithCallbacks::$throw_on_destroy = true;
        $this->assert_exception_message_contains('before_destroy failed', fn() => $post->force_delete(), RuntimeException::class);
        $this->assert_false($post->is_force_deleting());
        TrashPostWithCallbacks::reset();
        $this->assert_true($post->delete());
        $this->assert_same(['before_destroy false force false', 'after_destroy true force false'], TrashPostWithCallbacks::$log);
        $this->assert_true(TrashPost::with_trashed()->find(4)->trashed());

        // before_destroy returning false cancels the force delete as it cancels delete()
        TrashPostWithCallbacks::$cancel_destroy = true;
        $this->assert_false(TrashPostWithCallbacks::find(3)->force_delete());
        $this->assert_same(1, TrashPost::without_global_scopes()->where('id', 3)->count());
    }

    public function test_static_force_delete_with_the_trait_is_php_error(): void
    {
        $this->assert_exception_message_contains('Non-static method TrashPost::force_delete() cannot be called statically', fn() => TrashPost::force_delete(), Error::class);
        $this->assert_same(4, TrashPost::without_global_scopes()->count());
    }

    public function test_restore_without_primary_key_throws(): void
    {
        $item = TrashPkless::find('first');
        $this->assert_exception_message_contains('Cannot restore, no primary key defined for: TrashPkless', fn() => $item->restore(), ActiveRecordException::class);
        $this->assert_exception_message_contains('Cannot delete, no primary key defined for: TrashPkless', fn() => $item->delete(), ActiveRecordException::class);
        $this->assert_exception_message_contains('Cannot delete, no primary key defined for: TrashPkless', fn() => $item->force_delete(), ActiveRecordException::class);
        $this->assert_false($item->is_force_deleting());

        // nothing written
        $this->assert_same(1, TrashPkless::count());
        $this->assert_same(1, TrashPkless::only_trashed()->count());
    }

    public function test_restore_without_soft_delete_and_error_order(): void
    {
        // error 6 (the registry) comes before error 15, for every entry point
        $trait_only = new TrashTraitOnly(['id' => 1, 'name' => 'live'], false, true, false);
        $this->assert_exception_message_contains('TrashTraitOnly uses SoftDeletes but does not implement SoftDeletable', fn() => $trait_only->restore(), ConfigException::class);
        $this->assert_exception_message_contains('TrashTraitOnly uses SoftDeletes but does not implement SoftDeletable', fn() => $trait_only->force_delete(), ConfigException::class);
        $this->assert_same(1, TrashDated::count());   // nothing written

        // 15: the entry point reached by a model without soft delete
        $plain = TrashRestoreWithoutSoftDelete::find(2);
        $this->assert_exception_message_contains('Cannot restore, TrashRestoreWithoutSoftDelete has no soft delete', fn() => $plain->restore(), ActiveRecordException::class);
        $this->assert_not_null(TrashRestoreWithoutSoftDelete::find(2)->removed_on);

        // 7 when the column is read, after the primary key checks
        $no_column = new TrashDatedNoColumn(['id' => 1, 'name' => 'live', 'removed_on' => null], false, true, false);
        $this->assert_exception_message_contains('TrashDatedNoColumn::soft_delete_column() names nope', fn() => $no_column->restore(), ConfigException::class);
        $this->assert_exception_message_contains('TrashDatedNoColumn::soft_delete_column() names nope', fn() => $no_column->delete(), ConfigException::class);
        $this->assert_same(2, TrashDated::without_global_scopes()->count());
    }

    public function test_non_public_before_restore_breaks_table_load(): void
    {
        $this->assert_exception_message_contains('Callback methods need to be public', fn() => TrashPostPrivateCallback::find(1), ActiveRecordException::class);
    }

    public function test_without_timestamps_and_table_without_updated_at(): void
    {
        Timestamps::freeze('2026-11-18 10:00:00');
        $post = TrashPost::find(1);
        $this->assert_true(TrashPost::without_timestamps(fn() => $post->delete()));
        $sql = str_replace(['`', '"'], '', TrashPost::table()->last_sql);
        $this->assert_same(1, preg_match('/^UPDATE trash_posts SET deleted_at=\? WHERE id\s*=\s*\?$/', $sql), $sql);
        $this->assert_null($post->updated_at);
        $this->assert_null(TrashPost::with_trashed()->find(1)->updated_at);

        $this->assert_true(TrashPost::without_timestamps(fn() => $post->restore()));
        $this->assert_null(TrashPost::find(1)->updated_at);

        // trash_comments has no updated_at: nothing added
        $comment = TrashComment::find(1);
        $this->assert_true($comment->delete());
        $sql = str_replace(['`', '"'], '', TrashComment::table()->last_sql);
        $this->assert_same(1, preg_match('/^UPDATE trash_comments SET deleted_at=\? WHERE id\s*=\s*\?$/', $sql), $sql);
        $this->assert_false(array_key_exists('updated_at', $comment->attributes()));
        $this->assert_true($comment->restore());
        $this->assert_null(TrashComment::find(1)->deleted_at);
    }

    public function test_a_column_named_in_another_case_is_written_under_its_attribute(): void
    {
        if (TrashCommentUpperColumn::connection()->column_names_are_case_sensitive()) {
            $comment = new TrashCommentUpperColumn(['id' => 1, 'body' => 'first', 'deleted_at' => null], false, true, false);
            $this->assert_exception_message_contains('names DELETED_AT, which is not a column of trash_comments', fn() => $comment->trashed(), ConfigException::class);
            $this->assert_exception_message_contains('names DELETED_AT, which is not a column of trash_comments', fn() => $comment->delete(), ConfigException::class);
            $this->assert_same(3, TrashComment::without_global_scopes()->count());   // nothing deleted

            return;
        }

        $comment = TrashCommentUpperColumn::find(1);
        $this->assert_false($comment->trashed());
        $this->assert_true($comment->delete());
        $this->assert_true($comment->trashed());
        $this->assert_instance_of(ActiveRecord\DateTime::class, $comment->deleted_at);
        $this->assert_false(array_key_exists('DELETED_AT', $comment->attributes()));
        $this->assert_null($comment->dirty_attributes());
        $this->assert_true(TrashComment::only_trashed()->find(1)->trashed());

        $this->assert_true($comment->restore());
        $this->assert_false($comment->trashed());
        $this->assert_null($comment->dirty_attributes());
        $this->assert_false(TrashComment::find(1)->trashed());
    }

    public function test_transaction_rollback_leaves_in_memory_state(): void
    {
        $post = TrashPost::find(1);
        TrashPost::transaction(function () use ($post) {
            $post->delete();

            return false;
        });
        $this->assert_true($post->trashed());   // §5.6: no in-memory rollback
        $this->assert_not_null(TrashPost::find(1));
        $post->reload();
        $this->assert_false($post->trashed());

        $trashed = TrashPost::with_trashed()->find(2);
        TrashPost::transaction(function () use ($trashed) {
            $trashed->restore();

            return false;
        });
        $this->assert_false($trashed->trashed());
        $trashed->reload();
        $this->assert_true($trashed->trashed());
    }

    public function test_uniqueness_counts_trashed_rows(): void
    {
        $post = new TrashPost(['code' => 'B', 'title' => 'again']);
        $this->assert_false($post->is_valid());
        $this->assert_true($post->errors->is_invalid('code'));
        $this->assert_true((new TrashPost(['code' => 'Z', 'title' => 'new']))->is_valid());
    }
}
