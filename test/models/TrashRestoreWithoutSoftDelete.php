<?php

use ActiveRecord\Model;

/**
 * A model without soft delete that reaches Model's restore entry point anyway: error 15.
 */
class TrashRestoreWithoutSoftDelete extends Model
{
    public static $table_name = 'trash_dated';

    public function restore()
    {
        return $this->perform_restore_on_model();
    }
}
