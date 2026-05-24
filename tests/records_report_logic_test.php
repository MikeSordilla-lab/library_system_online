<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/circulation.php';

function assert_same($expected, $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
  }
}

function assert_float(float $expected, float $actual, string $message): void
{
  if (abs($expected - $actual) > 0.001) {
    throw new RuntimeException($message . ' Expected ' . $expected . ', got ' . $actual . '.');
  }
}

function test_records_case(string $name, callable $fn): void
{
  try {
    $fn();
    echo "PASS {$name}\n";
  } catch (Throwable $e) {
    echo "FAIL {$name}: {$e->getMessage()}\n";
    throw $e;
  }
}

$now = new DateTimeImmutable('2026-05-25 09:00:00');

test_records_case('open past-due loan is overdue', function () use ($now): void {
  $record = circulation_enrich_report_record([
    'status' => 'active',
    'due_date' => '2026-05-22 09:00:00',
    'return_date' => null,
    'fine_paid' => 0,
  ], 5.0, $now);

  assert_same('overdue', $record['effective_status'], 'Expected overdue effective status.');
  assert_same('Overdue', $record['status_label'], 'Expected readable overdue label.');
  assert_same(3, $record['days_late'], 'Expected three late days.');
  assert_float(15.0, $record['calculated_fine'], 'Expected daily fine calculation.');
});

test_records_case('late returned loan is returned late', function () use ($now): void {
  $record = circulation_enrich_report_record([
    'status' => 'returned',
    'due_date' => '2026-05-20 10:00:00',
    'return_date' => '2026-05-22 09:00:00',
    'fine_paid' => 1,
  ], 5.0, $now);

  assert_same('returned_late', $record['effective_status'], 'Expected returned late effective status.');
  assert_same('Returned Late', $record['status_label'], 'Expected readable returned late label.');
  assert_same(2, $record['days_late'], 'Expected two late days.');
  assert_float(10.0, $record['calculated_fine'], 'Expected late return fine.');
});

test_records_case('on-time returned loan is returned with no fine', function () use ($now): void {
  $record = circulation_enrich_report_record([
    'status' => 'returned',
    'due_date' => '2026-05-20 10:00:00',
    'return_date' => '2026-05-20 09:59:00',
    'fine_paid' => 1,
  ], 5.0, $now);

  assert_same('returned', $record['effective_status'], 'Expected returned effective status.');
  assert_same(0, $record['days_late'], 'Expected no late days.');
  assert_float(0.0, $record['calculated_fine'], 'Expected no fine.');
});

test_records_case('summary separates collected and unpaid fines', function () use ($now): void {
  $records = [
    circulation_enrich_report_record([
      'status' => 'returned',
      'due_date' => '2026-05-20 10:00:00',
      'return_date' => '2026-05-22 10:00:00',
      'fine_paid' => 1,
    ], 5.0, $now),
    circulation_enrich_report_record([
      'status' => 'overdue',
      'due_date' => '2026-05-23 09:00:00',
      'return_date' => null,
      'fine_paid' => 0,
    ], 5.0, $now),
  ];

  $summary = circulation_report_summary($records);

  assert_float(20.0, $summary['total_fines'], 'Expected total fines.');
  assert_float(10.0, $summary['collected_fines'], 'Expected paid fines only.');
  assert_float(10.0, $summary['unpaid_fines'], 'Expected unpaid fines only.');
});

echo "All records report logic tests passed.\n";
