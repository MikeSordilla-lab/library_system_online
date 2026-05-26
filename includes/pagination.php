<?php

declare(strict_types=1);

function pagination_clamp_page(int $requested_page, int $total_pages): int
{
  $last_page = max(1, $total_pages);
  return min(max(1, $requested_page), $last_page);
}

/**
 * @return array{0:int,1:int}
 */
function pagination_result_range(int $total_rows, int $offset, int $per_page): array
{
  if ($total_rows < 1) {
    return [0, 0];
  }

  $start = max(1, $offset + 1);
  $end = min($total_rows, $offset + max(1, $per_page));

  return [$start, $end];
}

/**
 * @return int[]
 */
function pagination_page_window(int $current_page, int $total_pages, int $radius = 2): array
{
  $current_page = pagination_clamp_page($current_page, $total_pages);
  $total_pages = max(1, $total_pages);
  $radius = max(0, $radius);
  $start = max(1, $current_page - $radius);
  $end = min($total_pages, $current_page + $radius);

  return range($start, $end);
}

function pagination_build_url(string $base_url, array $params, string $page_param, int $target_page): string
{
  $query = [];
  foreach ($params as $key => $value) {
    if ($value === '' || $value === null) {
      continue;
    }
    $query[(string) $key] = $value;
  }
  $query[$page_param] = $target_page;

  return $base_url . '?' . http_build_query($query);
}
