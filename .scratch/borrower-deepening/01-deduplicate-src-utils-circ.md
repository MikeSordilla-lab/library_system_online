# AFK: Deduplicate src/utils/circulation.php

## What to build

Remove `src/utils/circulation.php` (inactive duplicate). Confirm `includes/circulation.php` is the canonical file and update any stray require paths if found. No behaviour changes.

## Acceptance criteria

- [ ] `src/utils/circulation.php` is deleted
- [ ] No PHP fatal errors on any borrower page after deletion
- [ ] `includes/circulation.php` is the single canonical implementation

## Blocked by

None - can start immediately