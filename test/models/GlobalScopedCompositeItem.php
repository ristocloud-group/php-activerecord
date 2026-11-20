<?php

use ActiveRecord\GlobalScope;
use ActiveRecord\Query;

/**
 * A model on the composite-pk table news_read_receipts (user_id, story_id) whose scope hides user 3.
 */
class GlobalScopedCompositeItem extends ActiveRecord\Model
{
    public static $table_name = 'news_read_receipts';

    #[GlobalScope]
    protected static function not_user_three(Query $q): ?Query
    {
        return $q->where('user_id', '!=', 3);
    }
}
