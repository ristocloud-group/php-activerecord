<?php

// The fluent query builder: Eloquent's API in snake_case, on the engine that the finders also
// run through. See the "Query builder" section of the README.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../ActiveRecord.php';
require_once __DIR__ . '/models/Customer.php';
require_once __DIR__ . '/models/Invoice.php';

// Create a throwaway SQLite database and load the schema, so this runs with no
// database server to configure.
$db = __DIR__ . '/query_builder.db';
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec((string) file_get_contents(__DIR__ . '/query_builder.sql'));
$pdo = null;

/**
 * Keeps the statements the library sends: it logs each one at the debug level.
 */
final class StatementLog extends Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $statements = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->statements[] = (string) $message;
    }
}

$log = new StatementLog();

ActiveRecord\Config::initialize(function (ActiveRecord\Config $cfg) use ($db, $log) {
    $cfg->set_connections(['development' => 'sqlite://unix(' . $db . ')']);
    $cfg->set_logger($log);
});

// A string is printed as it is, anything else as JSON: a string of digits keeps its quotes.
function show(string $label, mixed $value): void
{
    echo str_pad($label . ':', 40) . (is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES)) . "\n";
}

/** @param array<int, Invoice> $invoices */
function invoice_numbers(array $invoices): string
{
    return [] === $invoices ? '(none)' : implode(', ', array_map(fn(Invoice $invoice): string => $invoice->number, $invoices));
}

/** @param array<int, Customer> $customers */
function customer_names(array $customers): string
{
    return [] === $customers ? '(none)' : implode(', ', array_map(fn(Customer $customer): string => $customer->name, $customers));
}

// Loading a model's table reads its schema with a statement of its own: do it now, so that the
// statements counted below are those of the queries.
Customer::table();
Invoice::table();

// Rows: customers Acme (IT), Globex (DE), Initech (IT) and Umbrella (US, no invoice), and six
// invoices, INV-001 ... INV-006: paid, open, paid, void, open, open.

// 1. The entry points ------------------------------------------------------------------------
// Invoice::where(...) is Invoice::new_query()->where(...): the builder methods are forwarded
// statically. get() returns an array of models, so each is typed, and so is $invoice->number.
foreach (Invoice::where('status', 'open')->order_by('id')->get() as $invoice) {
    show('open', $invoice->number . ' (' . $invoice->total_cents . ' cents)');
}

// A query is mutable: a builder method changes it and returns that same object, and the
// terminals leave it as it was. clone() forks one.
$open = Invoice::where('status', 'open')->order_by('id');
$first_two = $open->clone()->limit(2)->get();
show('clone: limited, then the query', count($first_two) . ' and ' . count($open->get()) . ' rows');

// The legacy statics keep their meaning: Invoice::find(99) throws, where the builder's find()
// answers null.
try {
    Invoice::find(99);
} catch (ActiveRecord\RecordNotFound $e) {
    show('legacy find(99)', $e->getMessage());
}
show('builder find(99)', Invoice::new_query()->find(99)?->number);

// 2. The where family ------------------------------------------------------------------------
show('where, an operator', invoice_numbers(Invoice::where('total_cents', '>', 10000)->order_by('id')->get()));
// 'amount' is an $alias_attribute of Invoice for total_cents: aliases work in every condition
show('where, an alias', invoice_numbers(Invoice::where('amount', '>=', 20000)->order_by('id')->get()));
show('where_in', invoice_numbers(Invoice::where_in('status', ['paid', 'void'])->order_by('id')->get()));
// A DateTime is bound in the format of its column's type: a DATE column gets '2026-02-01'
show('where_between, DateTimes', invoice_numbers(Invoice::where_between('issued_on', [new DateTime('2026-02-01'), new DateTime('2026-02-28')])->order_by('id')->get()));
show('where_null', invoice_numbers(Invoice::where_null('note')->order_by('id')->get()));
// The hash form: a list is IN, and a null in it also matches the NULL rows
show('a hash with a null in a list', invoice_numbers(Invoice::where(['note' => ['rush', null]])->order_by('id')->get()));
show('where_like, in any case', invoice_numbers(Invoice::where_like('note', 'RU%')->order_by('id')->get()));
show('where_like, case-sensitive', invoice_numbers(Invoice::where_like('note', 'RU%', true)->order_by('id')->get()));

// A closure is a parenthesized group: status = 'open' AND (note = 'rush' OR total_cents >= 20000)
$wanted = Invoice::where('status', 'open')
    ->where(fn(ActiveRecord\Query $query) => $query->where('note', 'rush')->or_where('total_cents', '>=', 20000))
    ->order_by('id');
show('a closure group', invoice_numbers($wanted->get()));
show('  SQL', $wanted->to_sql());

// when() applies a clause only when its value is truthy: the shape of an optional filter
foreach ([null, 'paid'] as $status) {
    $filtered = Invoice::when($status, fn(ActiveRecord\Query $query, mixed $status) => $query->where('status', $status));
    show('when(' . json_encode($status) . ')', $filtered->count() . ' invoices');
}

// An unknown operator is refused at the call (Laravel would take it for the value)
try {
    Invoice::where('status', 'frob', 'open');
} catch (ActiveRecord\ActiveRecordException $e) {
    show('an unknown operator', $e->getMessage());
}

// 3. Selecting, ordering and paging ----------------------------------------------------------
show('order_by_desc, limit, offset', invoice_numbers(Invoice::order_by_desc('total_cents')->order_by('id')->limit(2)->offset(1)->get()));
show('for_page(2, 2)', invoice_numbers(Invoice::order_by('id')->for_page(2, 2)->get()));

// "column as alias" names the attribute of the models
foreach (Invoice::select('number', 'total_cents as cents')->order_by('id')->limit(2)->get() as $invoice) {
    show('select ... as', $invoice->number . ' -> ' . $invoice->cents);
}

// 4. Joins -----------------------------------------------------------------------------------
// With joins the model's columns are qualified with its table, so where('id', ...) is never
// ambiguous, and the default select is the model's columns only.
$italian = Invoice::join('customers', 'customers.id', '=', 'invoices.customer_id')
    ->where('customers.country', 'IT')
    ->where('id', '>', 1)
    ->order_by('id');
show('join', invoice_numbers($italian->get()));
show('  SQL', $italian->to_sql());

// A closure takes the conditions of the ON as a JoinClause, and select() brings back a column of
// the joined table under an attribute name of your choice. The LEFT JOIN keeps every invoice.
$with_names = Invoice::left_join('customers', fn(ActiveRecord\JoinClause $join) => $join
        ->on('customers.id', '=', 'invoices.customer_id')
        ->where('customers.country', 'DE'))
    ->select('invoices.*', 'customers.name as customer_name')
    ->order_by('id')
    ->get();
show('left_join, a closure', implode(', ', array_map(fn(Invoice $invoice): string => $invoice->number . ' ' . ($invoice->customer_name ?? '-'), $with_names)));

// joins() and left_joins() name a relationship the model declares
show('joins by relationship', invoice_numbers(Invoice::joins('customer')->where('customers.country', 'DE')->order_by('id')->get()));
show('left_joins: no invoice', customer_names(Customer::left_joins('invoices')->where_null('invoices.id')->get()));

// 5. Eager loading ---------------------------------------------------------------------------
$log->statements = [];

foreach (Invoice::with('customer')->where('status', 'paid')->order_by('id')->get() as $invoice) {
    show('with(customer)', $invoice->number . ' -> ' . $invoice->customer->name);
}

show('  statements sent', count($log->statements));

// 6. The read terminals ----------------------------------------------------------------------
show('find(3)', Invoice::new_query()->find(3)?->number);
show('find([1, 3, 99])', invoice_numbers(Invoice::new_query()->find([1, 3, 99])));
show('first', Invoice::where('status', 'open')->order_by('issued_on')->first()?->number);

try {
    Invoice::where('status', 'lost')->first_or_fail();
} catch (ActiveRecord\RecordNotFound $e) {
    show('first_or_fail', $e->getMessage());
}

show('sole', Invoice::where('number', 'INV-004')->sole()->status);

try {
    Invoice::where('status', 'open')->sole();
} catch (ActiveRecord\MultipleRecordsFound $e) {
    show('sole, with several rows', $e->getMessage());
}

show('value', Invoice::where('number', 'INV-003')->value('total_cents'));
show('pluck', Invoice::where('status', 'open')->order_by('id')->pluck('number'));
show('pluck, keyed', Invoice::order_by('id')->limit(3)->pluck('total_cents', 'number'));
show('exists, doesnt_exist', [Invoice::where('status', 'void')->exists(), Invoice::where('status', 'void')->doesnt_exist()]);
show('count', Invoice::where('status', 'open')->count());
// Invoice::count('note') is the legacy finder (a primary key): COUNT(note) is a query's
show('count(note), the non-null notes', Invoice::new_query()->count('note'));
// count() counts the rows of the result: a grouped query counts its groups (the legacy
// Invoice::count(['group' => ...]) counts the rows of the first group)
show('count, grouped', Invoice::group_by('customer_id')->count());
show('legacy count, grouped', Invoice::count(['group' => 'customer_id']));

// 7. Aggregates ------------------------------------------------------------------------------
// The sum of an integer column is an int, and avg() is exact: a string, rounded half up at the
// scale of the SUM plus 4, the same on every database (a float would be 12000.166666666666).
show('sum', Invoice::sum('total_cents'));
show('min, max', [Invoice::min('total_cents'), Invoice::max('total_cents')]);
show('avg', json_encode(Invoice::avg('total_cents')));
show('avg of the paid', json_encode(Invoice::where('status', 'paid')->avg('total_cents')));
show('sum of no row', Invoice::where('status', 'lost')->sum('total_cents'));
show('avg of no row', json_encode(Invoice::where('status', 'lost')->avg('total_cents')));
// A limited query aggregates the rows of its result (Eloquent sums every row)
show('sum of the 2 biggest', Invoice::order_by_desc('total_cents')->limit(2)->sum('total_cents'));

$totals = Invoice::select('customer_id')
    ->select_raw('SUM(total_cents) AS total')
    ->group_by('customer_id')
    ->having_raw('SUM(total_cents) > ?', [22000])
    ->order_by('customer_id')
    ->get();

foreach ($totals as $row) {
    show('group_by, having_raw', 'customer ' . $row->customer_id . ': ' . $row->total);
}

// 8. Writes ----------------------------------------------------------------------------------
// insert() writes rows: no model, no validation, no callback. It fills created_at and updated_at.
Invoice::new_query()->insert([
    ['customer_id' => 4, 'number' => 'INV-007', 'status' => 'open', 'total_cents' => 1500, 'issued_on' => new DateTime('2026-04-01')],
    ['customer_id' => 4, 'number' => 'INV-008', 'status' => 'open', 'total_cents' => 2500, 'issued_on' => new DateTime('2026-04-02')],
]);
$new = Invoice::where('number', 'INV-007')->sole();
show('insert: created_at, updated_at set', [null !== $new->created_at, null !== $new->updated_at]);

// update() and delete() take the where, order_by() and limit() of the query, and return the number
// of rows written. update() sets updated_at too: a seed row has none until a write stamps it.
show('update: updated_at before', Invoice::where('number', 'INV-002')->sole()->updated_at);
Invoice::where('number', 'INV-002')->update(['note' => 'rush']);
show('update: updated_at after', null !== Invoice::where('number', 'INV-002')->sole()->updated_at);
show('update', Invoice::where('customer_id', 4)->update(['status' => 'void']));

// without_timestamps() suspends created_at and updated_at for the model while the closure runs
Invoice::without_timestamps(fn() => Invoice::where('number', 'INV-003')->update(['note' => 'checked']));
show('without_timestamps: updated_at set', null !== Invoice::where('number', 'INV-003')->sole()->updated_at);
Invoice::where('number', 'INV-001')->update(['note' => 'checked']);
show('with timestamps: updated_at set', null !== Invoice::where('number', 'INV-001')->sole()->updated_at);

show('delete', Invoice::where('customer_id', 4)->delete());

// A static Invoice::update([...]) or Invoice::delete() would write every row, so neither is forwarded: the
// first throws an ActiveRecordException, the second is PHP's own Error (and PHPStan reports both). The way
// to write every row is to say so, with Invoice::new_query(), as the last line of this example does.

// A write whose where() calls all came out empty (a filter form with nothing filled in) is
// refused, so that it cannot write every row
$filters = [];

try {
    Invoice::where($filters)->delete();
} catch (ActiveRecord\ActiveRecordException) {
    show('delete with an empty where', 'refused');
}

// 9. Debugging -------------------------------------------------------------------------------
$query = Invoice::where('status', 'open')->where('total_cents', '>', 5000)->order_by_desc('issued_on')->limit(2);
show('to_sql', $query->to_sql());
show('get_bindings', $query->get_bindings());
show('to_raw_sql', $query->to_raw_sql());

// The one way to write every row is to say so: new_query() with no where() at all
show('delete every invoice', Invoice::new_query()->delete());
