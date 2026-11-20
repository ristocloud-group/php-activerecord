<?php

/**
 * Soft delete and relationship loads (spec §1.2 Q6, §3.5, §3.6, §4.6): a load is filtered by the target's soft delete
 * unless 'unscoped' lifts it, and a :through load always skips the rows linked through a trashed middle row, in both
 * :through shapes (the join-table one and the has_many→has_many chain), lazily and eagerly, whatever 'unscoped' says.
 *
 * Fixtures: trash_posts 1 live, 2 trashed; trash_comments 1 (post 1), 2 (post 1, trashed), 3 (post 2); trash_votes
 * 1 (comment 1), 2 (comment 2), 3 (comment 1, trashed); trash_tags 1 ruby, 2 perl (trashed), 3 php; trash_taggings
 * 1 (post 1, tag 1), 2 (post 1, tag 2), 3 (post 1, tag 3, trashed).
 */
class SoftDeleteRelationshipTest extends DatabaseTest
{
    use CapturesStatements;

    /**
     * @param list<ActiveRecord\Model> $models
     * @return list<int>
     */
    private function ids(array $models): array
    {
        $ids = array_map(static fn($m) => (int) $m->id, $models);
        sort($ids);

        return $ids;
    }

    public function test_middle_trashed_link_hidden_lazy_and_eager_both_shapes(): void
    {
        $post = TrashPost::find(1);
        $this->assert_equals([1], $this->ids($post->trash_tags));      // tag 2 trashed (target), tag 3 via trashed link
        $this->assert_equals([1], $this->ids($post->trash_votes));     // vote 2 via trashed comment, vote 3 trashed
        $eager = TrashPost::find(1, ['include' => ['trash_tags', 'trash_votes']]);
        $this->assert_equals([1], $this->ids($eager->trash_tags));
        $this->assert_equals([1], $this->ids($eager->trash_votes));
    }

    public function test_middle_filter_with_several_owners_eagerly(): void
    {
        // post 4 has no comment and no tagging; the drafts and the trashed post are hidden by TrashPost's own scopes
        $posts = TrashPost::find('all', ['order' => 'id', 'include' => ['trash_tags', 'trash_votes']]);
        $this->assert_same([1, 4], array_map(static fn($p) => (int) $p->id, $posts));
        $this->assert_equals([1], $this->ids($posts[0]->trash_tags));
        $this->assert_equals([1], $this->ids($posts[0]->trash_votes));
        $this->assert_same([], $posts[1]->trash_tags);
        $this->assert_same([], $posts[1]->trash_votes);
    }

    public function test_unscoped_never_lifts_the_middle_filter(): void
    {
        $post = TrashPostUnscoped::find(1);
        // 'unscoped' on the owner→middle relationships: their trashed rows load
        $this->assert_equals([1, 2, 3], $this->ids($post->trash_taggings));
        $this->assert_equals([1, 2], $this->ids($post->trash_comments));
        // 'unscoped' on the targets: the trashed tag 2 and vote 3 load, tag 3 and vote 2 stay hidden (trashed middle)
        $this->assert_equals([1, 2], $this->ids($post->trash_tags));
        $this->assert_equals([1, 3], $this->ids($post->trash_votes));

        $eager = TrashPostUnscoped::find(1, ['include' => ['trash_taggings', 'trash_comments', 'trash_tags', 'trash_votes']]);
        $this->assert_equals([1, 2, 3], $this->ids($eager->trash_taggings));
        $this->assert_equals([1, 2], $this->ids($eager->trash_comments));
        $this->assert_equals([1, 2], $this->ids($eager->trash_tags));
        $this->assert_equals([1, 3], $this->ids($eager->trash_votes));
    }

    public function test_middle_filter_added_once_per_load_not_to_the_frozen_join(): void
    {
        // the relationship builds its join once and keeps it: each lazy load adds the middle predicate to its own
        // conditions, so a later load (another owner, then the same one again) carries it once, as the first did
        $sql = $this->statements(function () {
            foreach ([1, 4, 1] as $id) {
                TrashPost::find($id)->trash_tags;
                TrashPost::find($id)->trash_votes;
            }
        });
        $loads = array_values(array_filter($sql, static fn($s) => str_contains($s, ' INNER JOIN ')));

        $this->assert_count(6, $loads);
        foreach ($loads as $statement) {
            $this->assert_same(1, preg_match_all('/deleted_at\W* IS NULL\)?$/', $statement));
            $this->assert_same(2, substr_count($statement, 'IS NULL'));       // the target's scope and the middle's
        }
    }

    public function test_belongs_to_trashed_parent_and_unscoped(): void
    {
        $comment = TrashComment::find(3);                               // its post 2 is trashed
        $this->assert_null($comment->trash_post);
        $this->assert_same(2, $comment->any_post->id);

        $eager = TrashComment::find(3, ['include' => ['trash_post', 'any_post']]);
        $this->assert_null($eager->trash_post);
        $this->assert_same(2, $eager->any_post->id);
    }

    public function test_shapes_pinned(): void
    {
        $conn = TrashPost::connection();
        $q = static fn(string $name) => $conn->quote_name($name);
        [$comments, $votes, $tags, $taggings] = array_map($q, ['trash_comments', 'trash_votes', 'trash_tags', 'trash_taggings']);
        [$deleted_at, $post_id] = array_map($q, ['deleted_at', 'trash_post_id']);

        $lazy = [];
        $lazy_sql = $this->statements(function () use (&$lazy) {
            $post = TrashPostConditioned::find(1);
            $lazy = [$this->ids($post->trash_tags), $this->ids($post->trash_votes)];
        });

        $eager = [];
        $eager_sql = $this->statements(function () use (&$eager) {
            $post = TrashPostConditioned::find(1, ['include' => ['trash_tags', 'trash_votes']]);
            $eager = [$this->ids($post->trash_tags), $this->ids($post->trash_votes)];
        });

        // the declared OR matches tags 1 and 3 and votes 1 and 2: tag 3 and vote 2 come through a trashed middle row
        $this->assert_same([[1], [1]], $lazy);
        $this->assert_same($lazy, $eager);

        // the two :through loads of each run, as the target's find sends them (schema reads and the owner's find aside)
        $joins = static fn(array $sql) => array_values(array_filter($sql, static fn($s) => str_contains($s, ' INNER JOIN ')));

        // (target scope) AND ((declared) AND <key> AND <middle soft delete>)
        $this->assert_same([
            "SELECT $tags.* FROM $tags INNER JOIN $taggings ON($tags.id = $taggings.trash_tag_id) WHERE ($tags.$deleted_at IS NULL) AND ((name = 'ruby' OR name = 'php') AND $taggings.$post_id=? AND $taggings.$deleted_at IS NULL)",
            "SELECT $votes.* FROM $votes INNER JOIN $comments ON($votes.trash_comment_id = $comments.id) WHERE ($votes.$deleted_at IS NULL) AND ((value = 5 OR value = 3) AND $comments.$post_id=? AND $comments.$deleted_at IS NULL)",
        ], $joins($lazy_sql));
        $this->assert_same([
            "SELECT $tags.*, $taggings.trash_post_id AS trash_post_id FROM $tags INNER JOIN $taggings ON($tags.id = $taggings.trash_tag_id) WHERE ($tags.$deleted_at IS NULL) AND ((name = 'ruby' OR name = 'php') AND $taggings.$post_id IN(?) AND $taggings.$deleted_at IS NULL)",
            "SELECT $votes.*, $comments.trash_post_id AS trash_post_id FROM $votes INNER JOIN $comments ON($votes.trash_comment_id = $comments.id) WHERE ($votes.$deleted_at IS NULL) AND ((value = 5 OR value = 3) AND $comments.$post_id IN(?) AND $comments.$deleted_at IS NULL)",
        ], $joins($eager_sql));
    }
}
