<?php

class DateFormatTest extends DatabaseTest
{
    public function test_datefield_gets_converted_to_ar_datetime()
    {
        //make sure first author has a date
        $author = Author::first();
        $author->some_date = new DateTime();
        $author->save();

        // the same row: without an order, Postgres returns the updated row last (#92)
        $author = Author::find($author->id);
        $this->assert_is_a("ActiveRecord\\DateTime", $author->some_date);
    }

};
