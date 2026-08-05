<?php

/**
 * @property int    $id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property int    $word_count
 */
class Article extends ActiveRecord\Model
{
    public static $before_validation = ['make_slug'];
    public static $before_save = ['count_words'];
    public static $after_create = ['log_created'];
    public static $before_update = ['log_updating'];
    public static $before_destroy = ['log_destroying'];

    public function make_slug(): void
    {
        $this->slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim((string) $this->title)) ?? '');
    }

    // Returning false from a before_ hook halts the save.
    public function count_words(): bool
    {
        $body = trim((string) $this->body);
        if ($body === '') {
            echo "  [before_save] empty body -> halting save\n";
            return false;
        }
        $this->word_count = count(preg_split('/\s+/', $body) ?: []);
        return true;
    }

    public function log_created(): void
    {
        echo "  [after_create] #{$this->id} '{$this->slug}'\n";
    }

    public function log_updating(): void
    {
        echo "  [before_update] #{$this->id}\n";
    }

    public function log_destroying(): void
    {
        echo "  [before_destroy] #{$this->id}\n";
    }
}
