<?php

// a declared $sequence wins over the pk's own default (the renamed serial's sequence) (#47)
class T47DeclaredSequence extends ActiveRecord\Model
{
    public static $table_name = 't47_renamed';
    public static $sequence = 't47_declared_seq';
}
