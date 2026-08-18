<?php

require_once __DIR__ . '/../lib/adapters/MysqlAdapter.php'; // adapters load on demand, not via the manifest

use ActiveRecord\Connection;
use ActiveRecord\DatabaseException;

/**
 * Unit-level coverage of the transaction depth machinery in Connection,
 * against a stubbed PDO (no database): the begin/commit/rollback failure
 * branches — unreachable with a real PDO in ERRMODE_EXCEPTION mode, which
 * throws instead of returning false — plus the exact savepoint SQL emitted
 * at each depth and the depth bookkeeping around failures.
 *
 * The real-database behavior of the same paths is covered in AdapterTest
 * (all four adapters) and ActiveRecordTest (Model::transaction).
 */
class ConnectionTransactionTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    /** @var list<string> SQL statements the stubbed PDO was asked to prepare */
    private $prepared_sql = [];

    private function connection_with_pdo(PDO $pdo): Connection
    {
        // Connection is abstract — instantiate a concrete adapter without
        // connecting; the transaction machinery under test lives on the base
        $connection = (new ReflectionClass(ActiveRecord\MysqlAdapter::class))->newInstanceWithoutConstructor();
        $connection->connection = $pdo;
        (new ReflectionClass(Connection::class))->getProperty('logger')->setValue($connection, new Psr\Log\NullLogger());
        return $connection;
    }

    /**
     * A PDO stub whose transaction primitives succeed and whose prepare()
     * records the SQL it receives (savepoint statements go through query()).
     *
     * @param list<string> $failing_sql prepare() throws for these statements
     */
    private function working_pdo(array $failing_sql = []): PDO
    {
        $this->prepared_sql = [];

        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('errorInfo')->willReturn(['HY000', 1, 'stubbed failure']);
        $pdo->method('errorCode')->willReturn('HY000');
        $pdo->method('prepare')->willReturnCallback(
            function ($sql) use ($statement, $failing_sql) {
                if (in_array($sql, $failing_sql, true)) {
                    throw new PDOException("stubbed prepare failure for: $sql");
                }
                $this->prepared_sql[] = $sql;
                return $statement;
            }
        );
        return $pdo;
    }

    private function failing_pdo(string $method): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn($method !== 'beginTransaction');
        $pdo->method($method)->willReturn(false);
        $pdo->method('errorInfo')->willReturn(['HY000', 1, "stubbed $method failure"]);
        $pdo->method('errorCode')->willReturn('HY000');
        return $pdo;
    }

    public function test_transaction_throws_database_exception_when_begin_fails()
    {
        $connection = $this->connection_with_pdo($this->failing_pdo('beginTransaction'));

        $this->expect_exception(DatabaseException::class);
        $connection->transaction();
    }

    public function test_failed_begin_leaves_depth_at_zero()
    {
        $pdo = $this->createMock(PDO::class);
        // both calls must reach the REAL begin: a savepoint attempt instead
        // would mean the failed first call leaked a depth increment
        $pdo->expects($this->exactly(2))->method('beginTransaction')->willReturn(false);
        $pdo->method('errorInfo')->willReturn(['HY000', 1, 'stubbed begin failure']);
        $pdo->method('errorCode')->willReturn('HY000');
        $connection = $this->connection_with_pdo($pdo);

        foreach ([1, 2] as $attempt) {
            try {
                $connection->transaction();
                $this->fail('expected DatabaseException on attempt ' . $attempt);
            } catch (DatabaseException) {
            }
        }
    }

    public function test_commit_throws_database_exception_when_commit_fails()
    {
        $connection = $this->connection_with_pdo($this->failing_pdo('commit'));
        $connection->transaction();

        $this->expect_exception(DatabaseException::class);
        $connection->commit();
    }

    public function test_rollback_throws_database_exception_when_rollback_fails()
    {
        $connection = $this->connection_with_pdo($this->failing_pdo('rollBack'));
        $connection->transaction();

        $this->expect_exception(DatabaseException::class);
        $connection->rollback();
    }

    public function test_savepoint_sql_sequence_over_three_depths()
    {
        $pdo = $this->working_pdo();
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');
        $pdo->expects($this->never())->method('rollBack');
        $connection = $this->connection_with_pdo($pdo);

        $connection->transaction();  // depth 1: real begin, no SQL
        $connection->transaction();  // depth 2
        $connection->transaction();  // depth 3
        $connection->rollback();     // back to depth 2
        $connection->commit();       // back to depth 1
        $connection->commit();       // real commit, no SQL

        $this->assert_equals([
            'SAVEPOINT ar_sp_1',
            'SAVEPOINT ar_sp_2',
            'ROLLBACK TO SAVEPOINT ar_sp_2',
            'RELEASE SAVEPOINT ar_sp_1',
        ], $this->prepared_sql);
    }

    public function test_depth_resets_after_outermost_commit()
    {
        $pdo = $this->working_pdo();
        $pdo->expects($this->exactly(2))->method('beginTransaction');
        $connection = $this->connection_with_pdo($pdo);

        $connection->transaction();
        $connection->commit();
        $connection->transaction();  // must be a real begin again, not a savepoint
        $connection->commit();

        $this->assert_equals([], $this->prepared_sql);
    }

    public function test_depth_resets_after_outermost_rollback()
    {
        $pdo = $this->working_pdo();
        $pdo->expects($this->exactly(2))->method('beginTransaction');
        $pdo->expects($this->once())->method('rollBack');
        $connection = $this->connection_with_pdo($pdo);

        $connection->transaction();
        $connection->rollback();
        $connection->transaction();
        $connection->commit();

        $this->assert_equals([], $this->prepared_sql);
    }

    public function test_failed_release_keeps_scope_addressable_for_rollback()
    {
        // depth stays put when RELEASE SAVEPOINT fails, so the rollback that
        // follows still targets the same savepoint instead of the outer scope
        $pdo = $this->working_pdo(['RELEASE SAVEPOINT ar_sp_1']);
        $pdo->expects($this->never())->method('rollBack'); // scope closes via ROLLBACK TO, never the real rollback
        $connection = $this->connection_with_pdo($pdo);

        $connection->transaction();
        $connection->transaction();
        try {
            $connection->commit();
            $this->fail('expected DatabaseException from the failed RELEASE');
        } catch (DatabaseException) {
        }

        $connection->rollback();
        $this->assert_equals(['SAVEPOINT ar_sp_1', 'ROLLBACK TO SAVEPOINT ar_sp_1'], $this->prepared_sql);
    }

    public function test_in_transaction_delegates_to_pdo()
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $connection = $this->connection_with_pdo($pdo);

        $this->assert_true($connection->inTransaction());
    }
}
