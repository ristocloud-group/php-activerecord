<?php

/**
 * @property int      $id
 * @property string   $name
 * @property int|null $flag
 *
 * @method static array<int, Task> find_all_by_flag(mixed $flag)
 */
class Task extends ActiveRecord\Model {}
