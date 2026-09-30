<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
foreach (['Author', 'Profile', 'Post', 'Comment', 'Tag', 'Tagging', 'Version'] as $m) {
    require_once __DIR__ . '/models/' . $m . '.php';
}

$db = __DIR__ . '/relationships.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/relationships.sql'));
$pdo = null;

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger(new Psr\Log\NullLogger());
});

function out(string $s): void
{
    echo $s . "\n";
}

// Seed: an author with a profile (has_one), two posts (has_many),
// comments on a post, and tags via a join table (has_many through).
/** @var Author $ada */
$ada = Author::create(['name' => 'Ada']);
$ada->create_profile(['bio' => 'Mathematician']);                 // has_one builder
$p1 = $ada->create_posts(['title' => 'On Engines']);             // has_many builder
$ada->create_posts(['title' => 'On Notes']);
$p1->create_comments(['body' => 'Fascinating']);
$p1->create_comments(['body' => 'Agreed']);

$php = Tag::create(['name' => 'php']);
Tagging::create(['post_id' => $p1->id, 'tag_id' => $php->id]);

// belongs_to + has_one
out('post author: ' . $p1->author->name);
out('author bio (has_one): ' . $ada->profile->bio);

// has_many
out('post count: ' . count($ada->posts));

// has_many with composite keys + declared conditions (Post::own_versions): a row
// must match BOTH (post_id, author_id) = the post's (id, author_id) AND the
// condition, one bound value per `?`. (Before the fix this lazy load threw
// ExpressionsException: both key values were bound to the first key marker.)
/** @var Post $p2 */
$p2 = Post::first(['conditions' => ['title = ?', 'On Notes']]);
Version::create(['post_id' => $p1->id, 'author_id' => $ada->id, 'body' => 'v1', 'status' => 'published']);
Version::create(['post_id' => $p1->id, 'author_id' => $ada->id, 'body' => 'v2', 'status' => 'draft']);       // fails the condition
Version::create(['post_id' => $p1->id, 'author_id' => null, 'body' => 'guest', 'status' => 'published']);    // other author_id
Version::create(['post_id' => $p2->id, 'author_id' => $ada->id, 'body' => 'notes', 'status' => 'published']); // other post_id
$own_versions = $p1->own_versions;
out('own published versions: ' . implode(', ', ActiveRecord\collect($own_versions, 'body')));
out('  SQL: ' . Version::table()->last_sql);

// Note: this fork's has_many :through only supports the join-table shape (see
// tags/taggings below) -- not a plain one-to-many chain like "comments through
// posts" -- so comments are aggregated across the author's posts directly.
$comment_total = array_sum(array_map(fn(Post $post): int => count($post->comments), $ada->posts));
out('author comment count (via posts): ' . $comment_total);

// has_many :through many-to-many (post <-> tags via taggings)
$p1_tags = $p1->tags;
out('first post tags: ' . implode(', ', ActiveRecord\collect($p1_tags, 'name')));

// Eager loading with include (avoids N+1); iterate the loaded graph.
/** @var array<int, Author> $authors */
$authors = Author::all(['include' => ['posts', 'profile']]);
foreach ($authors as $author) {
    out($author->name . ' has ' . count($author->posts) . ' posts');
}
