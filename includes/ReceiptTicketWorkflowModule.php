<?php

require_once __DIR__ . '/receipts.php';

/**
 * ReceiptTicketWorkflowModule — Workflow and listing seam for receipt adapters.
 */
class ReceiptTicketWorkflowModule
{
  /**
   * @param array{id:int, role:string} $actor
   * @param array<string,mixed> $body
   * @return array<string,mixed>
   */
  public static function createFromRequest(PDO $pdo, array $actor, array $body): array
  {
    $actorId = (int) ($actor['id'] ?? 0);
    $actorRole = (string) ($actor['role'] ?? '');

    if (!in_array($actorRole, ['admin', 'librarian', 'borrower'], true)) {
      return ['success' => false, 'reason_code' => 'role_not_allowed'];
    }

    $patronUserId = isset($body['patron_user_id']) ? (int) $body['patron_user_id'] : $actorId;
    if ($actorRole === 'borrower' && $patronUserId !== $actorId) {
      return ['success' => false, 'reason_code' => 'borrower_patron_mismatch'];
    }

    $type = trim((string) ($body['type'] ?? ''));
    $referenceTable = trim((string) ($body['reference_table'] ?? ''));
    $referenceId = (int) ($body['reference_id'] ?? 0);

    if ($type === '' || $referenceTable === '' || $referenceId < 1 || $patronUserId < 1) {
      return ['success' => false, 'reason_code' => 'missing_required_fields'];
    }

    try {
      $receipt = issue_receipt_ticket($pdo, [
        'type' => $type,
        'actor_user_id' => $actorId,
        'actor_role' => $actorRole,
        'patron_user_id' => $patronUserId,
        'reference_table' => $referenceTable,
        'reference_id' => $referenceId,
        'idempotency_key' => (string) ($body['idempotency_key'] ?? ''),
        'amount' => isset($body['amount']) ? (float) $body['amount'] : null,
        'currency' => (string) ($body['currency'] ?? receipt_default_currency()),
        'payload' => is_array($body['payload'] ?? null) ? $body['payload'] : [],
        'status' => (string) ($body['status'] ?? 'issued'),
        'format' => (string) ($body['format'] ?? 'thermal'),
        'locale' => (string) ($body['locale'] ?? 'en_US'),
        'timezone' => (string) ($body['timezone'] ?? 'UTC'),
        'branch_id' => isset($body['branch_id']) ? (int) $body['branch_id'] : null,
        'channel' => (string) ($body['channel'] ?? 'api'),
        'reason' => (string) ($body['reason'] ?? ''),
      ]);

      if (!can_access_receipt_ticket($receipt, $actorId, $actorRole)) {
        return ['success' => false, 'reason_code' => 'created_receipt_forbidden'];
      }

      return ['success' => true, 'receipt' => $receipt];
    } catch (InvalidArgumentException $e) {
      return ['success' => false, 'reason_code' => 'invalid_payload', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
      error_log('[ReceiptTicketWorkflowModule] create failed: ' . $e->getMessage());
      return ['success' => false, 'reason_code' => 'create_failed'];
    }
  }

  /**
   * @param array{id:int, role:string} $actor
   * @param array<string,mixed> $body
   * @return array<string,mixed>
   */
  public static function reprintFromRequest(PDO $pdo, array $actor, array $body, string $channel = 'api'): array
  {
    $actorId = (int) ($actor['id'] ?? 0);
    $actorRole = (string) ($actor['role'] ?? '');
    $receiptId = (int) ($body['id'] ?? 0);
    $receiptNo = trim((string) ($body['no'] ?? ''));
    $reason = trim((string) ($body['reason'] ?? ''));

    $receipt = $receiptId > 0
      ? get_receipt_ticket_by_id($pdo, $receiptId)
      : ($receiptNo !== '' ? get_receipt_ticket_by_number($pdo, $receiptNo) : null);

    if (!$receipt) {
      return ['success' => false, 'reason_code' => 'not_found'];
    }

    if (!can_access_receipt_ticket($receipt, $actorId, $actorRole)) {
      return ['success' => false, 'reason_code' => 'access_denied', 'receipt' => $receipt];
    }

    if ($reason === '') {
      return ['success' => false, 'reason_code' => 'reason_required', 'receipt' => $receipt];
    }

    try {
      $result = request_receipt_reprint($pdo, $receipt, $actorId, $actorRole, $reason, [
        'target' => (string) ($body['target'] ?? 'browser_print'),
        'channel' => $channel,
      ]);

      return ['success' => true, 'receipt' => $receipt, 'reprint' => $result];
    } catch (InvalidArgumentException $e) {
      return ['success' => false, 'reason_code' => 'invalid_payload', 'message' => $e->getMessage(), 'receipt' => $receipt];
    } catch (Throwable $e) {
      error_log('[ReceiptTicketWorkflowModule] reprint failed: ' . $e->getMessage());
      return ['success' => false, 'reason_code' => 'reprint_failed', 'receipt' => $receipt];
    }
  }

  /**
   * Build receipt index list model.
   *
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  public static function listModel(PDO $pdo, int $viewerId, string $viewerRole, array $input = []): array
  {
    $q = isset($input['q']) ? trim((string) $input['q']) : '';
    $filterStatus = isset($input['status']) ? trim((string) $input['status']) : '';
    $filterType = isset($input['type']) ? trim((string) $input['type']) : '';
    $dateFromRaw = isset($input['date_from']) ? trim((string) $input['date_from']) : '';
    $dateToRaw = isset($input['date_to']) ? trim((string) $input['date_to']) : '';
    $page = max(1, (int) ($input['page'] ?? 1));
    $perPage = isset($input['per_page']) ? max(5, min(100, (int) $input['per_page'])) : 10;
    if (!in_array($perPage, [10, 25, 50], true)) {
      $perPage = 10;
    }

    [$allowedStatuses, $allowedTypes] = self::allowedOptions($pdo, $viewerId, $viewerRole);

    $dateFromSql = self::dateToSql($dateFromRaw);
    $dateToSqlBase = self::dateToSql($dateToRaw);
    $dateFrom = self::dateDisplay($dateFromSql);
    $dateTo = self::dateDisplay($dateToSqlBase);

    $conditions = [];
    $params = [];
    if ($viewerRole === 'borrower') {
      $conditions[] = 'patron_user_id = :viewer_id';
      $params[':viewer_id'] = $viewerId;
    }

    if ($q !== '') {
      $conditions[] = '(receipt_no LIKE :q_receipt OR type LIKE :q_type OR status LIKE :q_status)';
      $params[':q_receipt'] = '%' . $q . '%';
      $params[':q_type'] = '%' . $q . '%';
      $params[':q_status'] = '%' . $q . '%';
    }

    if ($filterStatus !== '' && in_array($filterStatus, $allowedStatuses, true)) {
      $conditions[] = 'status = :status';
      $params[':status'] = $filterStatus;
    } else {
      $filterStatus = '';
    }

    if ($filterType !== '' && in_array($filterType, $allowedTypes, true)) {
      $conditions[] = 'type = :type';
      $params[':type'] = $filterType;
    } else {
      $filterType = '';
    }

    $dateToSql = '';
    if ($dateTo !== '') {
      $dateToTs = strtotime($dateToSqlBase . ' +1 day');
      if ($dateToTs !== false) {
        $dateToSql = date('Y-m-d', $dateToTs);
      } else {
        $dateTo = '';
      }
    }

    if ($dateFrom !== '') {
      $conditions[] = 'issued_at >= :date_from';
      $params[':date_from'] = $dateFromSql . ' 00:00:00';
    }
    if ($dateTo !== '' && $dateToSql !== '') {
      $conditions[] = 'issued_at < :date_to';
      $params[':date_to'] = $dateToSql . ' 00:00:00';
    }

    $whereSql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM Receipt_Tickets {$whereSql}");
    self::bindParams($countStmt, $params);
    $countStmt->execute();
    $totalTickets = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalTickets / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $ticketsStmt = $pdo->prepare(
      'SELECT ' . receipt_select_clause($pdo) . '
         FROM Receipt_Tickets
         ' . $whereSql . '
        ORDER BY issued_at DESC, id DESC
        LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
    );
    self::bindParams($ticketsStmt, $params);
    $ticketsStmt->execute();

    return [
      'filters' => [
        'q' => $q,
        'status' => $filterStatus,
        'type' => $filterType,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'per_page' => $perPage,
        'page' => $page,
      ],
      'allowed_statuses' => $allowedStatuses,
      'allowed_types' => $allowedTypes,
      'tickets' => $ticketsStmt->fetchAll() ?: [],
      'total_tickets' => $totalTickets,
      'total_pages' => $totalPages,
      'offset' => $offset,
      'shown_start' => $totalTickets > 0 ? $offset + 1 : 0,
      'shown_end' => min($offset + $perPage, $totalTickets),
      'has_filters' => $q !== '' || $filterStatus !== '' || $filterType !== '' || $dateFrom !== '' || $dateTo !== '',
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
   * @return array{0: array<int,string>, 1: array<int,string>}
   */
  private static function allowedOptions(PDO $pdo, int $viewerId, string $viewerRole): array
  {
    $baseOptionSql = '';
    $baseOptionParams = [];
    if ($viewerRole === 'borrower') {
      $baseOptionSql = ' WHERE patron_user_id = ?';
      $baseOptionParams = [$viewerId];
    }

    try {
      $statusStmt = $pdo->prepare("SELECT DISTINCT status FROM Receipt_Tickets{$baseOptionSql} ORDER BY status ASC");
      $statusStmt->execute($baseOptionParams);
      $allowedStatuses = array_values(array_filter(array_map('strval', $statusStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

      $typeStmt = $pdo->prepare("SELECT DISTINCT type FROM Receipt_Tickets{$baseOptionSql} ORDER BY type ASC");
      $typeStmt->execute($baseOptionParams);
      $allowedTypes = array_values(array_filter(array_map('strval', $typeStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) {
      $allowedStatuses = [];
      $allowedTypes = [];
    }

    return [$allowedStatuses, $allowedTypes];
  }

  /**
   * @param array<string,mixed> $params
   */
  private static function bindParams(PDOStatement $stmt, array $params): void
  {
    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
  }
}
