# Database migrations

## Phase 1 receipt/ticket migration

Apply the receipt schema migration using the existing runner:

```bash
php admin/migrations/runner.php receipts-phase1
```

This applies:

- `Receipt_Tickets` table
- `Receipt_Ticket_Logs` table

For existing deployments where the base receipt table already exists, run the
safe extension migration after the base migration:

```bash
php admin/migrations/runner.php receipts-phase1-safe
```

This adds phase-1 extension columns and print-job support in a re-runnable way.

You can also run all registered migrations:

```bash
php admin/migrations/runner.php all
```

See full rollout and test procedure: `docs/receipts-phase1.md`.
