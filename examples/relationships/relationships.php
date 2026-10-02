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

// belongs_to options. The two models below are declared here to keep this demo
// self-contained. A non-default association name needs class_name. The foreign
// key is inferred from the class ("author_id" here); foreign_key states it
// explicitly, and you need it whenever the column does not follow "<class>_id".
// belongs_to supports only class_name, class, foreign_key, conditions, select,
// readonly and namespace.
/**
 * @property-read Author $writer
 */
class PostWithWriter extends ActiveRecord\Model
{
    public static $table_name = 'posts';
    public static $belongs_to = [['writer', 'class_name' => 'Author', 'foreign_key' => 'author_id']];
}

/** @var PostWithWriter $post */
$post = PostWithWriter::find($p1->id);
out('post writer (class_name + foreign_key): ' . $post->writer->name);

// An unsupported option (here primary_key, which belongs_to does not implement)
// is rejected when the model's table is loaded, i.e. on the first finder call,
// with a RelationshipException that lists each valid option once. Before #39 the
// list was printed twice, and the BelongsTo docblock itself suggested primary_key.
class PostWithPrimaryKey extends ActiveRecord\Model
{
    public static $table_name = 'posts';
    public static $belongs_to = [['author', 'primary_key' => 'id']];
}

try {
    PostWithPrimaryKey::first();
} catch (ActiveRecord\RelationshipException $e) {
    out('unsupported belongs_to option: ' . $e->getMessage());
}

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

// Declared `conditions` that contain OR stay scoped to the owner: the library
// wraps them as "(<conditions>) AND <key>". Before that fix they were glued on
// as "a OR b AND author_id = ?", which SQL reads as "a OR (b AND ...)": Ada's
// featured posts also listed Babbage's post, and her featured author was Babbage.
/** @var Author $babbage */
$babbage = Author::create(['name' => 'Babbage']);
$engine = $babbage->create_posts(['title' => 'On the Analytical Engine']);
$featured = $ada->featured_posts;
out('Ada featured posts (has_many, OR conditions): ' . implode(', ', ActiveRecord\collect($featured, 'title')));
out('  SQL: ' . Post::table()->last_sql);
out('Ada post featured author (belongs_to, OR conditions): ' . ($p1->featured_author->name ?? '(none)'));
out('  SQL: ' . Author::table()->last_sql);
out('Babbage post featured author: ' . ($engine->featured_author->name ?? '(none)'));

// has_many with a declared primary_key keys off that column, not the owner's id.
// Here a post lists the posts by its author (posts.author_id = this post's
// author_id), and the lazy load, the eager include and create_* all agree. Before
// #40 only the lazy load did: include matched on the post id, so "On Notes" (post
// 2) got the posts of author 2, and create_* stored the post id in author_id.
/**
 * @property int    $id
 * @property int    $author_id
 * @property string $title
 * @property-read array<int, PostBySameAuthor> $same_author_posts
 * @property-read array<int, PostBySameAuthor> $latest_same_author_posts
 *
 * @method PostBySameAuthor create_same_author_posts(array<string, mixed> $attributes) has_many builder
 */
class PostBySameAuthor extends ActiveRecord\Model
{
    public static $table_name = 'posts';
    public static $has_many = [['same_author_posts', 'class_name' => 'PostBySameAuthor', 'foreign_key' => 'author_id',
        'primary_key' => 'author_id', 'order' => 'id'],
        ['latest_same_author_posts', 'class_name' => 'PostBySameAuthor', 'foreign_key' => 'author_id',
            'primary_key' => 'author_id', 'order' => 'id desc', 'limit' => 1]];
}

$titles = fn(array $posts): string => implode(', ', ActiveRecord\collect($posts, 'title'));
/** @var PostBySameAuthor $notes */
$notes = PostBySameAuthor::find($p2->id);
out('posts by the author of "On Notes" (lazy): ' . $titles($notes->same_author_posts));
/** @var array<int, PostBySameAuthor> $all_posts */
$all_posts = PostBySameAuthor::all(['order' => 'id', 'include' => 'same_author_posts']);
foreach ($all_posts as $post) {
    out('  ' . $post->title . ' (eager): ' . $titles($post->same_author_posts));
}
out('  SQL: ' . PostBySameAuthor::table()->last_sql);
/** @var PostBySameAuthor $engine_post */
$engine_post = PostBySameAuthor::find($engine->id);
$sequel = $engine_post->create_same_author_posts(['title' => 'On the Difference Engine']);
out('create_same_author_posts on post ' . $engine_post->id . ': author_id = ' . $sequel->author_id . ' (Babbage is ' . $babbage->id . ')');
/** @var PostBySameAuthor $reloaded */
$reloaded = PostBySameAuthor::find($engine->id);
out('  lazy reload: ' . $titles($reloaded->same_author_posts));
$sequel->delete(); // keep the rest of the demo's data as it was

// The eager include also applies a declared limit/offset to each owner, as the lazy
// load does: every post gets the latest post of its author. Before, the LIMIT went
// on the single IN(...) query, so only the first post got one.
/** @var array<int, PostBySameAuthor> $latest */
$latest = PostBySameAuthor::all(['order' => 'id', 'include' => 'latest_same_author_posts']);
foreach ($latest as $post) {
    out('  ' . $post->title . ' -> latest by its author (eager, limit 1): ' . $titles($post->latest_same_author_posts));
}
out('  SQL: ' . PostBySameAuthor::table()->last_sql);

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
