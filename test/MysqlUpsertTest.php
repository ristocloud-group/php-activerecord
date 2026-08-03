<?php

class MysqlUpsertTest extends UpsertTest
{
    public function set_up($connection_name = null)
    {
        parent::set_up('mysql');
    }
}
