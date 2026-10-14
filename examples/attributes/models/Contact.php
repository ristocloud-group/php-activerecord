<?php

/**
 * Columns whose names are not attribute names: an attribute is its column name
 * lowercased, with spaces and dashes as underscores. So the column "first name" is
 * the attribute first_name and the column lastName the attribute lastname.
 *
 * @property int|null    $id
 * @property string|null $first_name
 * @property string|null $lastname
 *
 * @method static Contact|null find_by_first_name(string $first_name)
 */
class Contact extends ActiveRecord\Model {}
