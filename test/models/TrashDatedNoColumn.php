<?php

// soft_delete_column() names no column of the table (error 7)
class TrashDatedNoColumn extends TrashDated
{
    public static function soft_delete_column(): string
    {
        return 'nope';
    }
}
