<?php

require_once __DIR__ . '/../lib/Inflector.php';

class InflectorTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    /** @var ActiveRecord\Inflector */
    private $inflector;

    public function set_up()
    {
        $this->inflector = ActiveRecord\Inflector::instance();
    }

    public function test_underscorify()
    {
        $this->assert_equals('rm__name__bob', $this->inflector->variablize('rm--name  bob'));
        $this->assert_equals('One_Two_Three', $this->inflector->underscorify('OneTwoThree'));
    }

    public function test_tableize()
    {
        $this->assert_equals('angry_people', $this->inflector->tableize('AngryPerson'));
        $this->assert_equals('my_sqls', $this->inflector->tableize('MySQL'));
    }

    public function test_tableize_regular_look_alikes_of_irregulars()
    {
        // #48: a class named like a regular word that ends like an irregular
        $this->assert_equals('humans', $this->inflector->tableize('Human'));
        $this->assert_equals('sales_humans', $this->inflector->tableize('SalesHuman'));
        $this->assert_equals('mongooses', $this->inflector->tableize('Mongoose'));
        $this->assert_equals('germans', $this->inflector->tableize('German'));
    }

    public function test_tableize_keeps_irregular_suffix_match_for_compounds()
    {
        $this->assert_equals('firemen', $this->inflector->tableize('Fireman'));
        $this->assert_equals('women', $this->inflector->tableize('Woman'));
        $this->assert_equals('sales_men', $this->inflector->tableize('SalesMan'));
        $this->assert_equals('angry_people', $this->inflector->tableize('AngryPerson'));
    }

    public function test_keyify()
    {
        $this->assert_equals('building_type_id', $this->inflector->keyify('BuildingType'));
    }
};
