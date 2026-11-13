# smoke fixtures

`responses.php` returns canned transport results (`code`, `body`, `seconds`, `error`) keyed by scenario. The smoke tests serve them through the check's HTTP seam.

| Key | Meaning |
| --- | --- |
| `ok` | 200, 0.4 s, clean body containing "Welcome" |
| `slow` | 200 but 3.4 s |
| `error500` | HTTP 500 |
| `php_error` | 200 with a PHP warning in the body |
| `blocked` | cURL error 7 (loopback refused) |
| `timeout` | cURL error 28 |
| `wall` | HTTP 401 |
