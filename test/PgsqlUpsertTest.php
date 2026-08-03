<?php

class PgsqlUpsertTest extends UpsertTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('pgsql');
    }
}
