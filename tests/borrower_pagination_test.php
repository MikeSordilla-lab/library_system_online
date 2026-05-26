<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/pagination.php';

function assert_same_borrower_pagination($expected, $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
  }
}

function test_borrower_pagination_case(string $name, callable $fn): void
{
  try {
    $fn();
    echo "PASS {$name}\n";
  } catch (Throwable $e) {
    echo "FAIL {$name}: {$e->getMessage()}\n";
    throw $e;
  }
}

test_borrower_pagination_case('clamps requested page inside total pages', function (): void {
  assert_same_borrower_pagination(1, pagination_clamp_page(-4, 3), 'Low pages clamp to first page.');
  assert_same_borrower_pagination(3, pagination_clamp_page(9, 3), 'High pages clamp to last page.');
  assert_same_borrower_pagination(1, pagination_clamp_page(1, 0), 'Empty results still expose page one.');
});

test_borrower_pagination_case('calculates visible result range', function (): void {
  assert_same_borrower_pagination([26, 50], pagination_result_range(64, 25, 25), 'Middle page range should match offset.');
  assert_same_borrower_pagination([51, 64], pagination_result_range(64, 50, 25), 'Last page range should clamp end.');
  assert_same_borrower_pagination([0, 0], pagination_result_range(0, 0, 25), 'Empty range should be zeroed.');
});

test_borrower_pagination_case('builds compact page window', function (): void {
  assert_same_borrower_pagination([3, 4, 5, 6, 7], pagination_page_window(5, 10), 'Middle pages show two neighbors.');
  assert_same_borrower_pagination([1, 2, 3], pagination_page_window(1, 3), 'Short ranges include all pages.');
});

test_borrower_pagination_case('preserves filters while changing page', function (): void {
  $url = pagination_build_url('/librarian/borrowers.php', [
    'q' => 'Ana',
    'status' => 'verified',
    'per_page' => 25,
    'empty' => '',
  ], 'page', 2);

  assert_same_borrower_pagination(
    '/librarian/borrowers.php?q=Ana&status=verified&per_page=25&page=2',
    $url,
    'Pagination URLs should keep non-empty filters.'
  );
});

echo "All borrower pagination tests passed.\n";
