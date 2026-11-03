<?php

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $nickname
 * @property string|null $badge
 * @property-read \ActiveRecord\Errors $errors
 */
class ClubMember extends ActiveRecord\Model
{
    public static $table_name = 'club_members';

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_uniqueness_of = [
        // optional: any number of members without a nickname, but no two with the same one
        ['nickname', 'allow_null' => true],
        // a null badge is a value like any other: a second member without one is a duplicate
        ['badge'],
    ];
}
