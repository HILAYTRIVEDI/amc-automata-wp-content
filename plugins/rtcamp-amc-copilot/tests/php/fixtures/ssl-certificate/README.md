# ssl-certificate fixtures

`certs.php` returns a function `( now, days_left, serial = '0A1B2C', issuer = 'R3' )` that builds an `openssl_x509_parse()` array for `example.test` whose `validTo` is `days_left` days (plus one hour, so the floored day count is exact) after `now`. A negative `days_left` is an expired certificate.
