<?php

class BookExclusion extends ActiveRecord\Model
{
    public static $table = 'books';
    public static $validates_exclusion_of = [
        ['name', 'in' => ['blah', 'alpha', 'bravo']],
    ];
};

class BookInclusion extends ActiveRecord\Model
{
    public static $table = 'books';
    public static $validates_inclusion_of = [
        ['name', 'in' => ['blah', 'tanker', 'shark']],
    ];
};

class ValidatesInclusionAndExclusionOfTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);
        BookInclusion::$validates_inclusion_of[0] = ['name', 'in' => ['blah', 'tanker', 'shark']];
        BookExclusion::$validates_exclusion_of[0] = ['name', 'in' => ['blah', 'alpha', 'bravo']];
    }

    public function test_inclusion()
    {
        $book = new BookInclusion();
        $book->name = 'blah';
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_exclusion()
    {
        $book = new BookExclusion();
        $book->name = 'blahh';
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_invalid_inclusion()
    {
        $book = new BookInclusion();
        $book->name = 'thanker';
        $book->save();
        $this->assert_true($book->errors->is_invalid('name'));
        $book->name = 'alpha ';
        $book->save();
        $this->assert_true($book->errors->is_invalid('name'));
    }

    public function test_invalid_exclusion()
    {
        $book = new BookExclusion();
        $book->name = 'alpha';
        $book->save();
        $this->assert_true($book->errors->is_invalid('name'));

        $book = new BookExclusion();
        $book->name = 'bravo';
        $book->save();
        $this->assert_true($book->errors->is_invalid('name'));
    }

    public function test_inclusion_with_numeric()
    {
        BookInclusion::$validates_inclusion_of[0]['in'] = [0, 1, 2];
        $book = new BookInclusion();
        $book->name = 2;
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_inclusion_with_boolean()
    {
        BookInclusion::$validates_inclusion_of[0]['in'] = [true];
        $book = new BookInclusion();
        $book->name = true;
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_inclusion_with_null()
    {
        BookInclusion::$validates_inclusion_of[0]['in'] = [null];
        $book = new BookInclusion();
        $book->name = null;
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_invalid_inclusion_with_numeric()
    {
        BookInclusion::$validates_inclusion_of[0]['in'] = [0, 1, 2];
        $book = new BookInclusion();
        $book->name = 5;
        $book->save();
        $this->assert_true($book->errors->is_invalid('name'));
    }

    public function test_inclusion_within_option()
    {
        BookInclusion::$validates_inclusion_of[0] = ['name', 'within' => ['okay']];
        $book = new BookInclusion();
        $book->name = 'okay';
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_inclusion_scalar_value()
    {
        BookInclusion::$validates_inclusion_of[0] = ['name', 'within' => 'okay'];
        $book = new BookInclusion();
        $book->name = 'okay';
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_valid_null()
    {
        BookInclusion::$validates_inclusion_of[0]['allow_null'] = true;
        $book = new BookInclusion();
        $book->name = null;
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_valid_blank()
    {
        BookInclusion::$validates_inclusion_of[0]['allow_blank'] = true;
        $book = new BookInclusion();
        $book->name = '';
        $book->save();
        $this->assert_false($book->errors->is_invalid('name'));
    }

    public function test_custom_message()
    {
        $msg = 'is using a custom message.';
        BookInclusion::$validates_inclusion_of[0]['message'] = $msg;
        BookExclusion::$validates_exclusion_of[0]['message'] = $msg;

        $book = new BookInclusion();
        $book->name = 'not included';
        $book->save();
        $this->assert_equals('is using a custom message.', $book->errors->on('name'));
        $book = new BookExclusion();
        $book->name = 'bravo';
        $book->save();
        $this->assert_equals('is using a custom message.', $book->errors->on('name'));
    }

    // #60: a null value failing inclusion must not raise a str_replace(null) deprecation.
    public function test_invalid_inclusion_with_null()
    {
        $book = new BookInclusion();
        $book->name = null;
        $this->assert_false($book->save());
        $this->assert_true($book->errors->is_invalid('name'));
        $this->assert_same('is not included in the list', $book->errors->on('name'));
    }

    // #60: same for a null value failing exclusion (null listed in `in`).
    public function test_invalid_exclusion_with_null()
    {
        BookExclusion::$validates_exclusion_of[0]['in'] = ['blah', null];
        $book = new BookExclusion();
        $book->name = null;
        $this->assert_false($book->save());
        $this->assert_true($book->errors->is_invalid('name'));
        $this->assert_same('is reserved', $book->errors->on('name'));
    }

    // #60: a `%s` placeholder keeps interpolating a null value as the empty string.
    public function test_custom_message_placeholder_with_null()
    {
        $msg = "value '%s' is not allowed";
        BookInclusion::$validates_inclusion_of[0]['message'] = $msg;
        BookExclusion::$validates_exclusion_of[0] = ['name', 'in' => [null], 'message' => $msg];

        $book = new BookInclusion();
        $book->name = null;
        $this->assert_false($book->is_valid());
        $this->assert_same("value '' is not allowed", $book->errors->on('name'));

        $book = new BookExclusion();
        $book->name = null;
        $this->assert_false($book->is_valid());
        $this->assert_same("value '' is not allowed", $book->errors->on('name'));
    }

    // Pins the `%s` interpolation of non-null values (string, int, Stringable) around the #60 fix.
    public function test_custom_message_placeholder_with_non_null_values()
    {
        $msg = "value '%s' is not allowed";
        BookInclusion::$validates_inclusion_of[0]['message'] = $msg;
        BookExclusion::$validates_exclusion_of[0]['message'] = $msg;

        $book = new BookInclusion();
        $book->name = 'thanker';
        $this->assert_false($book->is_valid());
        $this->assert_same("value 'thanker' is not allowed", $book->errors->on('name'));

        $book = new BookExclusion();
        $book->name = 'bravo';
        $this->assert_false($book->is_valid());
        $this->assert_same("value 'bravo' is not allowed", $book->errors->on('name'));

        $book = new BookInclusion();
        $book->name = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        };
        $this->assert_false($book->is_valid());
        $this->assert_same("value 'stringable' is not allowed", $book->errors->on('name'));

        BookInclusion::$validates_inclusion_of[0] = ['secondary_author_id', 'in' => [1, 2], 'message' => $msg];
        $book = new BookInclusion();
        $book->secondary_author_id = 5;
        $this->assert_same(5, $book->secondary_author_id);
        $this->assert_false($book->is_valid());
        $this->assert_same("value '5' is not allowed", $book->errors->on('secondary_author_id'));
    }

};
