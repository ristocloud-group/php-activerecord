<?php

use ActiveRecord\Config;

/**
 * The optional soft-delete migration warning (spec §3.7): off by default; on, a condition on the soft-delete column of a
 * SoftDeletable model, or a write that sets it, is logged once through the configured PSR logger. It never throws and
 * never changes the SQL.
 */
class SoftDeleteWarningTest extends DatabaseTest
{
    private ?Psr\Log\LoggerInterface $original_logger = null;

    /** @var object{records: list<string>}&Psr\Log\LoggerInterface */
    private $logger;

    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        $this->original_logger = Config::instance()->get_logger();
        $this->logger = new class extends Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = "$level: $message";
            }
        };
        Config::instance()->set_logger($this->logger);
    }

    public function tear_down()
    {
        Config::instance()->set_warn_soft_delete_conditions(false);

        if (null !== $this->original_logger) {
            Config::instance()->set_logger($this->original_logger);
        }
        parent::tear_down();
    }

    /**
     * @return list<string>
     */
    private function warnings(callable $run): array
    {
        Config::instance()->set_warn_soft_delete_conditions(true);
        $this->logger->records = [];
        $run();

        return $this->logger->records;
    }

    public function test_default_is_off_and_logs_nothing(): void
    {
        $this->assert_false(Config::instance()->get_warn_soft_delete_conditions());
        TrashPost::all(['conditions' => 'deleted_at IS NOT NULL']);
        $this->assert_same([], $this->logger->records);
    }

    public function test_legacy_string_condition(): void
    {
        $records = $this->warnings(fn() => TrashPost::all(['conditions' => 'deleted_at IS NOT NULL']));
        $this->assert_same(1, count($records));
        $this->assert_true(str_starts_with($records[0], 'warning: TrashPost: the condition deleted_at IS NOT NULL names the soft-delete column deleted_at'));
        $this->assert_true(str_contains($records[0], 'only_trashed(), with_trashed() or restore()'));
    }

    public function test_legacy_positional_and_hash_conditions(): void
    {
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::all(['conditions' => ['deleted_at < ?', '2026-01-01']]))));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::all(['conditions' => ['deleted_at' => null]]))));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::all(['conditions' => ['trash_posts.deleted_at' => null]]))));
    }

    public function test_builder_wheres(): void
    {
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_not_null('deleted_at')->get())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where('trash_posts.deleted_at', '!=', null)->get())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_raw('DELETED_AT is not null')->get())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where('code', 'A')->where(fn($q) => $q->where_not_null('deleted_at'))->get())));
    }

    public function test_from_alias_qualifier_and_between_columns(): void
    {
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::new_query()->from('trash_posts', 'p')->where('p.deleted_at', '!=', null)->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::new_query()->from('trash_posts', 'p')->where_null('p.deleted_at')->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_between_columns('id', ['deleted_at', 'draft'])->to_sql())));
    }

    public function test_writes(): void
    {
        $records = $this->warnings(fn() => TrashPost::update_all(['set' => ['deleted_at' => null], 'conditions' => ['code' => 'B']]));
        $this->assert_same(1, count($records));
        $this->assert_true(str_contains($records[0], 'the update of deleted_at names the soft-delete column deleted_at'));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::new_query()->update(['deleted_at' => null]))));
    }

    public function test_unrelated_statements_and_the_library_own_writes_do_not_warn(): void
    {
        $this->assert_same([], $this->warnings(function () {
            TrashPost::all(['conditions' => ['code' => 'A']]);
            TrashPost::where('code', 'A')->get();
            TrashPost::where_raw('code = ? OR deleted_at_x = 1', ['A'])->to_sql();
            TrashPost::only_trashed()->get();
            TrashPost::with_trashed()->get();
            TrashPost::find(1)->delete();
            TrashPost::where('code', 'D')->delete();
        }));
    }

    public function test_a_chosen_trashed_state_does_not_warn(): void
    {
        // with_trashed() and only_trashed() lift or replace the filter: a condition on the column is what they are for
        $this->assert_same([], $this->warnings(function () {
            TrashPost::only_trashed()->where('deleted_at', '<', '2026-06-01')->to_sql();
            TrashPost::only_trashed()->where('deleted_at', '<', '2026-06-01')->force_delete();
            TrashPost::with_trashed()->where('deleted_at', '<', '2026-06-01')->get();
            TrashPost::with_trashed()->where('code', 'B')->update(['deleted_at' => null]);
            TrashPost::without_global_scopes()->where_not_null('deleted_at')->to_sql();
            TrashPost::new_query()->without_global_scope('soft_deletes')->where_not_null('deleted_at')->to_sql();
            TrashPost::where('deleted_at', '<', '2026-06-01')->force_delete();
            TrashPost::force_delete_all(['conditions' => ['deleted_at < ?', '2026-06-01']]);
        }));

        // without_trashed() applies the filter, as the default does
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::without_trashed()->where_not_null('deleted_at')->to_sql())));
    }

    public function test_the_through_middle_predicate_does_not_warn(): void
    {
        // the library's own `<middle>.deleted_at IS NULL`, eager and lazy, where middle and target share the column name
        $this->assert_same([], $this->warnings(function () {
            TrashPost::find('all', ['include' => ['trash_tags', 'trash_votes']]);
            $post = TrashPost::find(1);
            $post->trash_tags;
            $post->trash_votes;
        }));
    }

    public function test_a_column_of_another_table_does_not_warn_and_the_model_own_does(): void
    {
        $this->assert_same([], $this->warnings(function () {
            TrashPost::where_raw('trash_taggings.deleted_at IS NULL')->to_sql();
            TrashPost::where_raw('"other" . deleted_at IS NULL OR `x`.`deleted_at` = 1')->to_sql();
        }));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_raw('trash_posts.deleted_at IS NOT NULL')->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_raw('`trash_posts`.`deleted_at` IS NOT NULL')->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_raw('"TRASH_POSTS" . "deleted_at" IS NOT NULL')->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::new_query()->from('trash_posts', 'p')->where_raw('p.deleted_at IS NOT NULL')->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::where_raw('other.id = 1 OR deleted_at IS NOT NULL')->to_sql())));
        $this->assert_same(1, count($this->warnings(fn() => TrashPost::all(['conditions' => 'trash_posts.deleted_at IS NOT NULL']))));
    }

    public function test_instance_soft_delete_and_restore_do_not_warn(): void
    {
        $this->assert_same([], $this->warnings(function () {
            $post = TrashPost::find(1);
            $post->delete();
            $post->restore();
        }));
    }

    public function test_a_model_that_is_not_soft_deletable_does_not_warn(): void
    {
        $this->assert_same([], $this->warnings(fn() => TrashPostPlain::new_query()->where_not_null('deleted_at')->to_sql()));
    }

    public function test_without_a_logger_nothing_happens(): void
    {
        $property = new ReflectionProperty(Config::class, 'logger');
        $property->setValue(Config::instance(), null);

        try {
            Config::instance()->set_warn_soft_delete_conditions(true);
            $this->assert_same(2, count(TrashPost::all(['conditions' => 'deleted_at IS NULL'])));
        } finally {
            $property->setValue(Config::instance(), $this->original_logger);
        }
    }

    public function test_the_sql_is_unchanged(): void
    {
        $off = TrashPost::where_not_null('deleted_at')->to_sql();
        $on = '';
        $this->warnings(function () use (&$on) {
            $on = TrashPost::where_not_null('deleted_at')->to_sql();
        });
        $this->assert_same($off, $on);
    }
}
