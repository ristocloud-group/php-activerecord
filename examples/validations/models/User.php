<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property int    $age
 * @property string $role
 * @property-read \ActiveRecord\Errors $errors
 */
class User extends ActiveRecord\Model
{
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_presence_of = [
        ['name'], ['email'],
    ];

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_length_of = [
        ['name', 'within' => [2, 50]],
    ];

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_uniqueness_of = [
        ['email'],
    ];

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_format_of = [
        ['email', 'with' => '/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/'],
    ];

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_numericality_of = [
        ['age', 'greater_than' => 0, 'less_than' => 150, 'allow_null' => true],
    ];

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_inclusion_of = [
        ['role', 'in' => ['admin', 'member', 'guest']],
    ];

    // Custom validation: runs on every save; add errors via $this->errors->add().
    public function validate(): void
    {
        if (strtolower((string) $this->name) === 'admin') {
            $this->errors->add('name', 'cannot be the reserved word "admin"');
        }
    }
}
