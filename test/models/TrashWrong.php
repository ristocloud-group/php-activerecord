<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

// soft delete on a VARCHAR column (error 8)
class TrashWrong extends Model implements SoftDeletable
{
    use SoftDeletes;
}
