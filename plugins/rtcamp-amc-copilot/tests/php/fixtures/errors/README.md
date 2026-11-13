# errors fixtures

`events.php` returns `EventsRepository::grouped()` rows keyed by event type, all with `last_seen` on 2026-11-14 between 10:05 and 10:23 UTC.

| Type | Rows | Used for |
| --- | --- | --- |
| `fatal` | 2 signatures (plugin:broken, theme:child) | one finding per signature |
| `warning` + `notice` | 3 + 1 rows across `plugin:noisy` and `core` | one finding per source |
| `deprecation` + `doing_it_wrong` | 3 rows from `plugin:old` (one file, two signatures) and `theme:child` | one finding per caller file |
