<?php

use ActiveRecord as AR;

class BookValidations extends ActiveRecord\Model
{
    public static $table_name = 'books';
    public static $alias_attribute = ['name_alias' => 'name', 'x' => 'secondary_author_id'];
    public static $validates_presence_of = [];
    public static $validates_uniqueness_of = [];
    public static $validates_numericality_of = [];
    public static $validates_inclusion_of = [];
    public static $custom_validator_error_msg = 'failed custom validation';

    // fired for every validation - but only used for custom validation test
    public function validate()
    {
        if ($this->name == 'test_custom_validation') {
            $this->errors->add('name', self::$custom_validator_error_msg);
        }
    }
}

class ValuestoreValidations extends ActiveRecord\Model
{
    public static $table_name = 'valuestore';
    public static $validates_uniqueness_of = [];
}

class ValidationsTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);

        // full assignments, not [0] =: tests replace these arrays wholesale
        // (e.g. a 3-element $validates_presence_of), and a partial reset
        // leaks the extra entries into whatever test runs next (GH-77)
        BookValidations::$validates_presence_of = ['name'];
        BookValidations::$validates_uniqueness_of = ['name'];
        BookValidations::$validates_numericality_of = [];
        BookValidations::$validates_inclusion_of = [];

        ValuestoreValidations::$validates_uniqueness_of[0] = 'key';
    }

    public function test_is_valid_invokes_validations()
    {
        $book = new Book();
        $this->assert_true(empty($book->errors));
        $book->is_valid();
        $this->assert_false(empty($book->errors));
    }

    public function test_is_valid_returns_true_if_no_validations_exist()
    {
        $book = new Book();
        $this->assert_true($book->is_valid());
    }

    public function test_is_valid_returns_false_if_failed_validations()
    {
        $book = new BookValidations();
        $this->assert_false($book->is_valid());
    }

    public function test_is_invalid()
    {
        $book = new Book();
        $this->assert_false($book->is_invalid());
    }

    public function test_is_invalid_is_true()
    {
        $book = new BookValidations();
        $this->assert_true($book->is_invalid());
    }

    public function test_is_iterable()
    {
        $book = new BookValidations();
        $book->is_valid();

        foreach ($book->errors as $name => $message) {
            $this->assert_equals("Name can't be blank", $message);
        }
    }

    public function test_full_messages()
    {
        $book = new BookValidations();
        $book->is_valid();

        $this->assert_equals(["Name can't be blank"], array_values($book->errors->full_messages(['hash' => true])));
    }

    public function test_to_array()
    {
        $book = new BookValidations();
        $book->is_valid();

        $this->assert_equals(["name" => ["Name can't be blank"]], $book->errors->to_array());
    }

    public function test_toString()
    {
        $book = new BookValidations();
        $book->is_valid();
        $book->errors->add('secondary_author_id', "is invalid");

        $this->assert_equals("Name can't be blank\nSecondary author id is invalid", (string) $book->errors);
    }

    public function test_validates_uniqueness_of()
    {
        BookValidations::create(['name' => 'bob']);
        $book = BookValidations::create(['name' => 'bob']);

        $this->assert_equals(["Name must be unique"], $book->errors->full_messages());
        $this->assert_equals(1, BookValidations::count(['conditions' => "name='bob'"]));
    }

    public function test_validates_uniqueness_of_excludes_self()
    {
        $book = BookValidations::first();
        $this->assert_equals(true, $book->is_valid());
    }

    public function test_validates_uniqueness_of_with_multiple_fields()
    {
        BookValidations::$validates_uniqueness_of[0] = [['name','special']];
        $book1 = BookValidations::first();
        $book2 = new BookValidations(['name' => $book1->name, 'special' => $book1->special + 1]);
        $this->assert_true($book2->is_valid());
    }

    public function test_validates_uniqueness_of_with_multiple_fields_is_not_unique()
    {
        BookValidations::$validates_uniqueness_of[0] = [['name','special']];
        $book1 = BookValidations::first();
        $book2 = new BookValidations(['name' => $book1->name, 'special' => $book1->special]);
        $this->assert_false($book2->is_valid());
        $this->assert_equals(['Name and special must be unique'], $book2->errors->full_messages());
    }

    public function test_validates_uniqueness_of_works_with_alias_attribute()
    {
        BookValidations::$validates_uniqueness_of[0] = [['name_alias','x']];
        $book = BookValidations::create(['name_alias' => 'Another Book', 'x' => 2]);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name alias and x must be unique'], $book->errors->full_messages());
    }

    public function test_validates_uniqueness_of_works_with_mysql_reserved_word_as_column_name()
    {
        ValuestoreValidations::create(['key' => 'GA_KEY', 'value' => 'UA-1234567-1']);
        $valuestore = ValuestoreValidations::create(['key' => 'GA_KEY', 'value' => 'UA-1234567-2']);

        $this->assert_equals(["Key must be unique"], $valuestore->errors->full_messages());

        $quoted_key = ValuestoreValidations::connection()->quote_name('key');
        $this->assert_equals(1, ValuestoreValidations::count(['conditions' => "{$quoted_key}='GA_KEY'"]));
    }

    public function test_validates_uniqueness_of_distinct_value_is_valid_and_bound()
    {
        $book = new BookValidations(['name' => 'a name nobody has']);
        $this->assert_true($book->is_valid());
        $this->assert_sql_has('book_id IS NOT NULL AND name=?', BookValidations::table()->last_sql);
    }

    public function test_validates_uniqueness_of_custom_message()
    {
        BookValidations::$validates_uniqueness_of = [['name', 'message' => 'is taken']];
        $book = new BookValidations(['name' => 'Another Book']);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name is taken'], $book->errors->full_messages());
    }

    public function test_validates_uniqueness_of_null_is_a_duplicate_of_null()
    {
        BookValidations::$validates_presence_of = [];
        Book::create(['name' => null]);

        $book = new BookValidations(['name' => null]);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name must be unique'], $book->errors->full_messages());
    }

    public function test_validates_uniqueness_of_null_is_compared_with_is_null()
    {
        BookValidations::$validates_presence_of = [];
        $book = new BookValidations(['name' => null]);
        $this->assert_true($book->is_valid());

        // no null is bound: the pk is null too, so no placeholder is left
        $sql = BookValidations::table()->last_sql;
        $this->assert_sql_has('book_id IS NOT NULL AND name IS NULL', $sql);
        $this->assert_equals(0, substr_count($sql, '?'));
    }

    public function test_validates_uniqueness_of_null_excludes_self()
    {
        BookValidations::$validates_presence_of = [];
        $id = Book::create(['name' => null])->book_id;

        $book = BookValidations::find($id);
        $this->assert_true($book->is_valid());
        $this->assert_sql_has('book_id != ? AND name IS NULL', BookValidations::table()->last_sql);
    }

    public function test_validates_uniqueness_of_allow_null_skips_null_only()
    {
        BookValidations::$validates_presence_of = [];
        BookValidations::$validates_uniqueness_of = [['name', 'allow_null' => true]];
        Book::create(['name' => null]);
        Book::create(['name' => '']);

        BookValidations::table()->last_sql = null;
        $book = new BookValidations(['name' => null]);
        $this->assert_true($book->is_valid());
        $this->assert_null(BookValidations::table()->last_sql);

        $book = new BookValidations(['name' => '']);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name must be unique'], $book->errors->full_messages());
    }

    public function test_validates_uniqueness_of_allow_blank_skips_blank_and_null()
    {
        BookValidations::$validates_presence_of = [];
        BookValidations::$validates_uniqueness_of = [['name', 'allow_blank' => true]];
        Book::create(['name' => null]);
        Book::create(['name' => '']);

        $book = new BookValidations(['name' => '']);
        $this->assert_true($book->is_valid());

        $book = new BookValidations(['name' => null]);
        $this->assert_true($book->is_valid());

        $book = new BookValidations(['name' => 'Another Book']);
        $this->assert_false($book->is_valid());
    }

    public function test_validates_uniqueness_of_with_multiple_fields_null_is_a_duplicate_of_null()
    {
        BookValidations::$validates_presence_of = [];
        BookValidations::$validates_uniqueness_of = [[['name', 'special']]];
        Book::create(['name' => 'pair', 'special' => null]);
        Book::create(['name' => null, 'special' => 5]);

        $book = new BookValidations(['name' => 'pair', 'special' => null]);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name and special must be unique'], $book->errors->full_messages());

        $book = new BookValidations(['name' => null, 'special' => 5]);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name and special must be unique'], $book->errors->full_messages());
    }

    public function test_validates_uniqueness_of_with_multiple_fields_null_part_without_duplicate_is_valid()
    {
        BookValidations::$validates_presence_of = [];
        BookValidations::$validates_uniqueness_of = [[['name', 'special']]];
        Book::create(['name' => 'pair', 'special' => null]);

        $book = new BookValidations(['name' => 'other', 'special' => null]);
        $this->assert_true($book->is_valid());

        $book = new BookValidations(['name' => 'pair', 'special' => 5]);
        $this->assert_true($book->is_valid());
    }

    public function test_validates_uniqueness_of_with_multiple_fields_allow_null_skips_when_any_field_is_null()
    {
        BookValidations::$validates_presence_of = [];
        BookValidations::$validates_uniqueness_of = [[['name', 'numeric_test'], 'allow_null' => true]];
        Book::create(['name' => 'pair', 'numeric_test' => null]);
        Book::create(['name' => 'blank', 'numeric_test' => '']);

        BookValidations::table()->last_sql = null;
        $book = new BookValidations(['name' => 'pair', 'numeric_test' => null]);
        $this->assert_true($book->is_valid());
        $this->assert_null(BookValidations::table()->last_sql);

        // allow_null does not cover a blank string
        $book = new BookValidations(['name' => 'blank', 'numeric_test' => '']);
        $this->assert_false($book->is_valid());
        $this->assert_equals(['Name and numeric test must be unique'], $book->errors->full_messages());
    }

    public function test_validates_uniqueness_of_with_multiple_fields_allow_blank_skips_when_any_field_is_blank()
    {
        BookValidations::$validates_presence_of = [];
        BookValidations::$validates_uniqueness_of = [[['name', 'numeric_test'], 'allow_blank' => true]];
        Book::create(['name' => 'pair', 'numeric_test' => null]);
        Book::create(['name' => 'blank', 'numeric_test' => '']);

        $book = new BookValidations(['name' => 'blank', 'numeric_test' => '']);
        $this->assert_true($book->is_valid());

        $book = new BookValidations(['name' => 'pair', 'numeric_test' => null]);
        $this->assert_true($book->is_valid());

        $book = new BookValidations(['name' => 'blank', 'numeric_test' => '0']);
        $this->assert_true($book->is_valid());

        $book = new BookValidations(['name' => 'Another Book', 'numeric_test' => '0']);
        $this->assert_false($book->is_valid());
    }

    public function test_get_validation_rules()
    {
        $validators = BookValidations::first()->get_validation_rules();
        $this->assert_true(in_array(['validator' => 'validates_presence_of'], $validators['name']));
    }

    public function test_model_is_nulled_out_to_prevent_memory_leak()
    {
        $book = new BookValidations();
        $book->is_valid();
        $this->assert_true(strpos(serialize($book->errors), 'model";N;') !== false);
    }

    public function test_validations_takes_strings()
    {
        BookValidations::$validates_presence_of = ['numeric_test', ['special'], 'name'];
        $book = new BookValidations(['numeric_test' => 1, 'special' => 1]);
        $this->assert_false($book->is_valid());
    }

    public function test_gh131_custom_validation()
    {
        $book = new BookValidations(['name' => 'test_custom_validation']);
        $book->save();
        $this->assert_true($book->errors->is_invalid('name'));
        $this->assert_equals(BookValidations::$custom_validator_error_msg, $book->errors->on('name'));
    }

    public function test_validates_numericality_of_throws_on_non_numeric_option()
    {
        BookValidations::$validates_numericality_of = [
            ['numeric_test', 'greater_than' => 'not_a_number'],
        ];

        $this->expectException(AR\ValidationsArgumentError::class);

        $book = new BookValidations(['numeric_test' => 5]);
        $book->is_valid();
    }

    public function test_validates_inclusion_of_accepts_scalar_in_option()
    {
        BookValidations::$validates_inclusion_of = [
            ['name', 'in' => 'active'],
        ];

        $book = new BookValidations(['name' => 'inactive']);
        $this->assert_false($book->is_valid());
        $this->assert_true($book->errors->is_invalid('name'));

        $book2 = new BookValidations(['name' => 'active']);
        $book2->is_valid();
        $this->assert_false($book2->errors->is_invalid('name'));
    }

    public function test_validates_inclusion_of_throws_when_in_and_within_both_missing()
    {
        BookValidations::$validates_inclusion_of = [
            ['name'],
        ];

        $this->expectException(AR\ValidationsArgumentError::class);

        $book = new BookValidations(['name' => 'whatever']);
        $book->is_valid();
    }
};
