<?php

use ActiveRecord\Errors;

class ErrorsTest extends DatabaseTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up($connection_name);

        // Errors is defined in Validations.php, which Model require_once's lazily
        // during the validation flow. Trigger that load so `new Errors()` resolves
        // regardless of test ordering (Author has no validations, so this is a no-op).
        (new Author())->is_valid();
    }

    private function make_errors(?Author $author = null): Errors
    {
        return new Errors($author ?? new Author());
    }

    public function test_starts_empty()
    {
        $errors = $this->make_errors();
        $this->assert_true($errors->is_empty());
        $this->assert_equals(0, $errors->size());
        $this->assert_null($errors->get_raw_errors());
    }

    public function test_add_size_and_raw_errors()
    {
        $errors = $this->make_errors();
        $errors->add('name', 'is bad');
        $errors->add('name', 'is really bad');
        $errors->add('some_text', null); // null message falls back to "is invalid"

        $this->assert_false($errors->is_empty());
        $this->assert_equals(3, $errors->size());
        $this->assert_equals([
            'name' => ['is bad', 'is really bad'],
            'some_text' => ['is invalid'],
        ], $errors->get_raw_errors());
    }

    public function test_get_returns_null_for_unknown_attribute()
    {
        $this->assert_null($this->make_errors()->name);
    }

    public function test_on_returns_string_for_one_error_and_array_for_many()
    {
        $errors = $this->make_errors();
        $errors->add('name', 'one');
        $this->assert_equals('one', $errors->on('name'));

        $errors->add('name', 'two');
        $this->assert_equals(['one', 'two'], $errors->on('name'));

        // an attribute with no errors yields null
        $this->assert_null($errors->on('some_text'));
    }

    public function test_clear()
    {
        $errors = $this->make_errors();
        $errors->add('name', 'boom');
        $this->assert_false($errors->is_empty());

        $errors->clear();
        $this->assert_true($errors->is_empty());
        $this->assert_equals(0, $errors->size());
    }

    public function test_add_on_empty()
    {
        // some_text is null on a fresh model -> empty() -> error added
        $errors = $this->make_errors();
        $errors->add_on_empty('some_text', 'cannot be empty');
        $this->assert_equals('cannot be empty', $errors->on('some_text'));

        // a populated attribute is not empty -> nothing added
        $author = new Author();
        $author->some_text = 'has content';
        $errors = $this->make_errors($author);
        $errors->add_on_empty('some_text', 'cannot be empty');
        $this->assert_true($errors->is_empty());
    }

    public function test_add_on_empty_falls_back_to_default_message()
    {
        $errors = $this->make_errors();
        $errors->add_on_empty('some_text', '');
        $this->assert_equals("can't be empty", $errors->on('some_text'));
    }

    public function test_add_on_blank()
    {
        // null is blank -> error added
        $errors = $this->make_errors();
        $errors->add_on_blank('some_text', 'is blank');
        $this->assert_equals('is blank', $errors->on('some_text'));

        // "0" is empty() but NOT blank -> nothing added (blank != empty)
        $author = new Author();
        $author->some_text = '0';
        $errors = $this->make_errors($author);
        $errors->add_on_blank('some_text', 'is blank');
        $this->assert_true($errors->is_empty());
    }

    public function test_add_on_blank_falls_back_to_default_message()
    {
        $errors = $this->make_errors();
        $errors->add_on_blank('some_text', '');
        $this->assert_equals("can't be blank", $errors->on('some_text'));
    }

    public function test_clear_model_keeps_existing_messages()
    {
        $errors = $this->make_errors();
        $errors->add('name', 'kept');
        $errors->clear_model(); // drops the circular model ref only
        $this->assert_equals('kept', $errors->on('name'));
    }
}
