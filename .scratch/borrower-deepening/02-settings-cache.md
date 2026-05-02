# AFK: Add request-scoped cache to get_setting()

## What to build

Add a static array cache inside `get_setting()` so repeated calls within the same request return the cached value instead of querying the database. `set_setting()` must invalidate the cache for the updated key. No call-site changes.

## Acceptance criteria

- [ ] First `get_setting($pdo, $key)` call hits DB; subsequent calls in same request return cached value
- [ ] `set_setting($pdo, $key, $value)` invalidates the cache for that key
- [ ] No existing call sites require changes
- [ ] Page load with 5 `get_setting()` calls generates at most 1 query per key