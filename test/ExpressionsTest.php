<?php

require_once __DIR__ . '/../lib/Expressions.php';

use ActiveRecord\Expressions;
use ActiveRecord\ConnectionManager;
use ActiveRecord\DatabaseException;

class ExpressionsTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_values()
    {
        $c = new Expressions(null, 'a=? and b=?', 1, 2);
        $this->assert_equals([1,2], $c->values());
    }

    public function test_one_variable()
    {
        $c = new Expressions(null, 'name=?', 'Tito');
        $this->assert_equals('name=?', $c->to_s());
        $this->assert_equals(['Tito'], $c->values());
    }

    public function test_array_variable()
    {
        $c = new Expressions(null, 'name IN(?) and id=?', ['Tito','George'], 1);
        $this->assert_equals([['Tito','George'],1], $c->values());
    }

    public function test_multiple_variables()
    {
        $c = new Expressions(null, 'name=? and book=?', 'Tito', 'Sharks');
        $this->assert_equals('name=? and book=?', $c->to_s());
        $this->assert_equals(['Tito','Sharks'], $c->values());
    }

    public function test_to_string()
    {
        $c = new Expressions(null, 'name=? and book=?', 'Tito', 'Sharks');
        $this->assert_equals('name=? and book=?', $c->to_s());
    }

    public function test_to_string_with_array_variable()
    {
        $c = new Expressions(null, 'name IN(?) and id=?', ['Tito','George'], 1);
        $this->assert_equals('name IN(?,?) and id=?', $c->to_s());
    }

    public function test_to_string_with_null_options()
    {
        $c = new Expressions(null, 'name=? and book=?', 'Tito', 'Sharks');
        $x = null;
        $this->assert_equals('name=? and book=?', $c->to_s(false, $x));
    }

    public function test_insufficient_variables()
    {
        $this->expectException(ActiveRecord\ExpressionsException::class);

        $c = new Expressions(null, 'name=? and id=?', 'Tito');
        $c->to_s();
    }

    public function test_no_values()
    {
        $c = new Expressions(null, "name='Tito'");
        $this->assert_equals("name='Tito'", $c->to_s());
        $this->assert_equals(0, count($c->values()));
    }

    public function test_null_variable()
    {
        $a = new Expressions(null, 'name=?', null);
        $this->assert_equals('name=?', $a->to_s());
        $this->assert_equals([null], $a->values());
    }

    public function test_zero_variable()
    {
        $a = new Expressions(null, 'name=?', 0);
        $this->assert_equals('name=?', $a->to_s());
        $this->assert_equals([0], $a->values());
    }

    public function test_ignore_invalid_parameter_marker()
    {
        $a = new Expressions(null, "question='Do you love backslashes?' and id in(?)", [1,2]);
        $this->assert_equals("question='Do you love backslashes?' and id in(?,?)", $a->to_s());
    }

    public function test_ignore_parameter_marker_with_escaped_quote()
    {
        $a = new Expressions(null, "question='Do you love''s backslashes?' and id in(?)", [1,2]);
        $this->assert_equals("question='Do you love''s backslashes?' and id in(?,?)", $a->to_s());
    }

    public function test_ignore_parameter_marker_with_backspace_escaped_quote()
    {
        $a = new Expressions(null, "question='Do you love\\'s backslashes?' and id in(?)", [1,2]);
        $this->assert_equals("question='Do you love\\'s backslashes?' and id in(?,?)", $a->to_s());
    }

    public function test_substitute()
    {
        $a = new Expressions(null, 'name=? and id=?', 'Tito', 1);
        $this->assert_equals("name='Tito' and id=1", $a->to_s(true));
    }

    public function test_substitute_quotes_scalars_but_not_others()
    {
        $a = new Expressions(null, 'id in(?)', [1,'2',3.5]);
        $this->assert_equals("id in(1,'2',3.5)", $a->to_s(true));
    }

    public function test_substitute_where_value_has_question_mark()
    {
        $a = new Expressions(null, 'name=? and id=?', '??????', 1);
        $this->assert_equals("name='??????' and id=1", $a->to_s(true));
    }

    public function test_substitute_array_value()
    {
        $a = new Expressions(null, 'id in(?)', [1,2]);
        $this->assert_equals("id in(1,2)", $a->to_s(true));
    }

    public function test_substitute_escapes_quotes()
    {
        $a = new Expressions(null, 'name=? or name in(?)', "Tito's Guild", [1,"Tito's Guild"]);
        $this->assert_equals("name='Tito''s Guild' or name in(1,'Tito''s Guild')", $a->to_s(true));
    }

    public function test_substitute_escape_quotes_with_connections_escape_method()
    {
        $conn = ConnectionManager::get_connection();
        $a = new Expressions(null, 'name=?', "Tito's Guild");
        $a->set_connection($conn);
        $escaped = $conn->escape("Tito's Guild");
        $this->assert_equals("name=$escaped", $a->to_s(true));
    }

    public function test_bind()
    {
        $a = new Expressions(null, 'name=? and id=?', 'Tito');
        $a->bind(2, 1);
        $this->assert_equals(['Tito',1], $a->values());
    }

    public function test_bind_overwrite_existing()
    {
        $a = new Expressions(null, 'name=? and id=?', 'Tito', 1);
        $a->bind(2, 99);
        $this->assert_equals(['Tito',99], $a->values());
    }

    public function test_bind_invalid_parameter_number()
    {
        $this->expectException(ActiveRecord\ExpressionsException::class);

        $a = new Expressions(null, 'name=?');
        $a->bind(0, 99);
    }

    public function test_subsitute_using_alternate_values()
    {
        $a = new Expressions(null, 'name=?', 'Tito');
        $this->assert_equals("name='Tito'", $a->to_s(true));
        $x = ['values' => ['Hocus']];
        $this->assert_equals("name='Hocus'", $a->to_s(true, $x));
    }

    public function test_null_value()
    {
        $a = new Expressions(null, 'name=?', null);
        $this->assert_equals('name=NULL', $a->to_s(true));
    }

    public function test_hash_with_default_glue()
    {
        $a = new Expressions(null, ['id' => 1, 'name' => 'Tito']);
        $this->assert_equals('id=? AND name=?', $a->to_s());
    }

    public function test_hash_with_glue()
    {
        $a = new Expressions(null, ['id' => 1, 'name' => 'Tito'], ', ');
        $this->assert_equals('id=?, name=?', $a->to_s());
    }

    public function test_hash_with_array()
    {
        $a = new Expressions(null, ['id' => 1, 'name' => ['Tito','Mexican']]);
        $this->assert_equals('id=? AND name IN(?,?)', $a->to_s());
    }

    // --- regression locks for the level-8 refactor of Expressions ---

    // Exercises every branch of substitute() in a single to_s(true): scalar,
    // array, apostrophe-escaped string, and null. Guards the substitute()
    // change that now returns the '?' marker directly instead of re-reading
    // $this->expressions.
    public function test_substitute_all_value_types_in_one_expression()
    {
        $a = new Expressions(null, 'a=? AND b IN(?) AND c=? AND d=?', 5, [1, 'x'], "O'Brien", null);
        $this->assert_equals("a=5 AND b IN(1,'x') AND c='O''Brien' AND d=NULL", $a->to_s(true));
    }

    // A '?' inside a quoted literal must not be substituted even in to_s(true);
    // guards the quote-tracking loop that reads the (local copy of) expressions.
    public function test_substitute_ignores_marker_inside_quotes()
    {
        $a = new Expressions(null, "note='really?' AND id=?", 7);
        $this->assert_equals("note='really?' AND id=7", $a->to_s(true));
    }

    // bind() fills positions sequentially and the result feeds substitution;
    // guards the copy-then-reassign form of bind().
    public function test_bind_sequential_fill_then_substitute()
    {
        $a = new Expressions(null, 'x=? AND y=? AND z=?');
        $a->bind(1, 10);
        $a->bind(2, 'hi');
        $a->bind(3, null);
        $this->assert_equals([10, 'hi', null], $a->values());
        $this->assert_equals("x=10 AND y='hi' AND z=NULL", $a->to_s(true));
    }

    // build_sql_from_hash() across all three branches (scalar =?, array IN(?),
    // null IS NULL literal) in one hash; the null branch had no prior direct
    // coverage.
    public function test_hash_with_null_and_array_and_scalar()
    {
        $a = new Expressions(null, ['id' => 1, 'name' => ['Tito', 'Mexican'], 'deleted_at' => null]);
        $this->assert_equals('id=? AND name IN(?,?) AND deleted_at IS NULL', $a->to_s());
        $this->assert_equals([1, ['Tito', 'Mexican']], $a->values());
    }

    // build_sql_from_hash() must emit a literal 'IS NULL' for a null hash value
    // (not a bound '?' marker): MySQL's emulated prepares rewrite '?' -> NULL on
    // the wire so the bug was invisible there, but Postgres real-prepares reject
    // 'IS $1'. The null must also be excluded from the returned bind values so
    // positional alignment with any remaining '?' markers is preserved.
    public function test_build_sql_from_hash_renders_null_as_literal_is_null()
    {
        $expressions = new Expressions(null, ['id' => null]);
        $this->assert_equals('id IS NULL', $expressions->to_s());
        // null must NOT be a bound value — it is inlined as a literal predicate
        $this->assert_equals([], $expressions->values());
    }

    public function test_all_null_hash_with_explicit_glue_has_no_bind_values()
    {
        $expressions = new Expressions(null, ['id' => null, 'deleted_at' => null], ' OR ');
        $this->assert_equals('id IS NULL OR deleted_at IS NULL', $expressions->to_s());
        // the glue argument must not leak into the bind values
        $this->assert_equals([], $expressions->values());
    }

    // build_sql_from_hash() must render an empty array as an always-false literal
    // predicate (Rails semantics: empty IN list matches nothing) instead of
    // 'IN(?)', which substitute() expands to the syntax error 'IN()' on
    // MySQL/MariaDB. Like the IS NULL literal above, nothing is pushed onto the
    // bind values.
    public function test_build_sql_from_hash_renders_empty_array_as_always_false()
    {
        $expressions = new Expressions(null, ['id' => []]);
        $this->assert_equals('1=0', $expressions->to_s());
        $this->assert_equals([], $expressions->values());
    }

    // The always-false literal must compose with the glue and keep the remaining
    // '?' markers positionally aligned with the returned bind values.
    public function test_build_sql_from_hash_empty_array_keeps_positional_alignment()
    {
        $expressions = new Expressions(null, ['id' => [], 'name' => 'Tito']);
        $this->assert_equals('1=0 AND name=?', $expressions->to_s());
        $this->assert_equals(['Tito'], $expressions->values());
        $this->assert_equals("1=0 AND name='Tito'", $expressions->to_s(true));
    }

    // In a user-authored fragment, an empty array bound to a '?' inside IN()
    // must expand to the literal NULL — 'IN(NULL)' is valid SQL everywhere and
    // matches nothing — instead of zero placeholders ('IN()', a syntax error on
    // MySQL/MariaDB). The fragment cannot be rewritten to '1=0' like the hash
    // path since the library does not parse user SQL.
    public function test_substitute_empty_array_renders_in_null()
    {
        $a = new Expressions(null, 'a IN(?)', []);
        $this->assert_equals('a IN(NULL)', $a->to_s());
        $this->assert_equals('a IN(NULL)', $a->to_s(true));
    }

    // The NULL literal consumes its marker without emitting one, so the
    // surrounding '?' markers stay positionally aligned with their values.
    public function test_substitute_empty_array_keeps_positional_alignment()
    {
        $a = new Expressions(null, 'id = ? AND a IN(?) AND b = ?', 1, [], 2);
        $this->assert_equals('id = ? AND a IN(NULL) AND b = ?', $a->to_s());
        $this->assert_equals('id = 1 AND a IN(NULL) AND b = 2', $a->to_s(true));
    }

    // bind() feeds the same expansion path as constructor-supplied values.
    public function test_bind_empty_array_renders_in_null()
    {
        $a = new Expressions(null, 'a IN(?)');
        $a->bind(1, []);
        $this->assert_equals('a IN(NULL)', $a->to_s());
    }
}
