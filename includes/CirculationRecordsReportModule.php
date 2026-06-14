<?php

require_once __DIR__ . '/circulation.php';

/**
 * CirculationRecordsReportModule — One report assembly module for screen and print adapters.
 */
class CirculationRecordsReportModule
{
  /**
   * Build a filtered, enriched circulation records report.
   *
   * @param array<string, mixed> $input
   * @return array<string, mixed>
   */
  public static function build(PDO $pdo, array $input = []): array
  {
    $filterStatus = trim((string) ($input['status'] ?? ''));
    $filterSearch = trim((string) ($input['search'] ?? ''));
    $filterDateFromRaw = trim((string) ($input['date_from'] ?? ''));
    $filterDateToRaw = trim((string) ($input['date_to'] ?? ''));
    $filterReportType = circulation_normalize_report_type(trim((string) ($input['report_type'] ?? 'circulation_records')));
    $rowsPerPage = (int) ($input['per_page'] ?? 10);
    $currentPage = max(1, (int) ($input['page'] ?? 1));
    $maxRows = max(1, min(5000, (int) ($input['max_rows'] ?? 5000)));

    $allowedStatuses = ['', CIRCULATION_STATUS_ACTIVE, CIRCULATION_STATUS_OVERDUE, CIRCULATION_STATUS_RETURNED, CIRCULATION_STATUS_RETURNED_LATE];
    if (!in_array($filterStatus, $allowedStatuses, true)) {
      $filterStatus = '';
    }

    if (!in_array($rowsPerPage, [10, 25, 50], true)) {
      $rowsPerPage = 10;
    }

    $filterDateFromSql = self::dateToSql($filterDateFromRaw);
    $filterDateToSql = self::dateToSql($filterDateToRaw);
    $filterDateFrom = self::dateDisplay($filterDateFromSql);
    $filterDateTo = self::dateDisplay($filterDateToSql);

    $whereParts = [];
    $params = [];

    if ($filterSearch !== '') {
      $whereParts[] = '(u.full_name LIKE ? OR b.title LIKE ? OR u.email LIKE ?)';
      $like = '%' . $filterSearch . '%';
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }

    if ($filterDateFrom !== '') {
      $whereParts[] = 'DATE(c.checkout_date) >= ?';
      $params[] = $filterDateFromSql;
    }

    if ($filterDateTo !== '') {
      $whereParts[] = 'DATE(c.checkout_date) <= ?';
      $params[] = $filterDateToSql;
    }

    $whereSql = count($whereParts) > 0 ? 'WHERE ' . implode(' AND ', $whereParts) : '';
    $stmt = $pdo->prepare(
      "SELECT
          c.id,
          c.status,
          c.checkout_date,
          c.due_date,
          c.return_date,
          c.fine_amount,
          c.fine_paid,
          u.full_name AS borrower_name,
          u.email AS borrower_email,
          b.title AS book_title,
          b.author AS book_author,
          b.isbn AS book_isbn
       FROM Circulation c
       JOIN Users u ON u.id = c.user_id
       JOIN Books b ON b.id = c.book_id
       {$whereSql}
       ORDER BY c.checkout_date DESC
       LIMIT {$maxRows}"
    );
    $stmt->execute($params);
    $rawRecords = $stmt->fetchAll();

    $dailyFineRate = (float) get_setting($pdo, 'fine_per_day', '0.00');
    $now = new DateTimeImmutable('now');
    $records = [];
    foreach ($rawRecords as $record) {
      $record = circulation_enrich_report_record($record, $dailyFineRate, $now);
      if ($filterStatus !== '' && (string) $record['effective_status'] !== $filterStatus) {
        continue;
      }
      if (!circulation_record_matches_report_type($record, $filterReportType)) {
        continue;
      }
      $records[] = $record;
    }

    $summary = circulation_report_summary($records);
    $totalRecords = (int) $summary['total_records'];
    $totalPages = max(1, (int) ceil($totalRecords / $rowsPerPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $rowsPerPage;
    $pagedRecords = array_slice($records, $offset, $rowsPerPage);

    return [
      'filters' => [
        'status' => $filterStatus,
        'search' => $filterSearch,
        'date_from' => $filterDateFrom,
        'date_to' => $filterDateTo,
        'date_from_sql' => $filterDateFromSql,
        'date_to_sql' => $filterDateToSql,
        'report_type' => $filterReportType,
      ],
      'records' => $records,
      'paged_records' => $pagedRecords,
      'summary' => $summary,
      'total_records' => $totalRecords,
      'total_pages' => $totalPages,
      'current_page' => $currentPage,
      'rows_per_page' => $rowsPerPage,
      'offset' => $offset,
      'page_start' => $totalRecords > 0 ? $offset + 1 : 0,
      'page_end' => min($offset + $rowsPerPage, $totalRecords),
      'active_filter_chips' => self::activeFilterChips($filterReportType, $filterStatus, $filterDateFrom, $filterDateTo, $filterSearch),
      'report_type_label' => circulation_report_type_label($filterReportType),
    ];
  }

  public static function dateToSql(string $value): string
  {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    foreach (['!m-d-y', '!Y-m-d'] as $format) {
      $date = DateTimeImmutable::createFromFormat($format, $value);
      $errors = DateTimeImmutable::getLastErrors();
      if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $date->format('Y-m-d');
      }
    }

    return '';
  }

  public static function dateDisplay(string $sqlDate): string
  {
    $date = self::dateToSql($sqlDate);
    if ($date === '') {
      return '';
    }
    return DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('m-d-y');
  }

  /**
   * @return array<int, string>
   */
  private static function activeFilterChips(string $reportType, string $status, string $dateFrom, string $dateTo, string $search): array
  {
    $chips = [
      'Report Type: ' . circulation_report_type_label($reportType),
      'Status: ' . ($status !== '' ? circulation_status_label($status) : 'All Statuses'),
      'Checkout: ' . (
        $dateFrom !== '' || $dateTo !== ''
          ? (($dateFrom !== '' ? $dateFrom : 'Any') . ' to ' . ($dateTo !== '' ? $dateTo : 'Any'))
          : 'All Dates'
      ),
    ];

    if ($search !== '') {
      $chips[] = 'Search: ' . $search;
    }

    return $chips;
  }
}
