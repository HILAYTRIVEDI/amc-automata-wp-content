<?php
/**
 * openssl_x509_parse() arrays, as functions of "now" (a Unix time) so tests control the days left.
 *
 * @package AMC
 */

return static function ( int $now, int $days_left, string $serial = '0A1B2C', string $issuer = 'R3' ): array {
	return [
		'name'             => '/CN=example.test',
		'subject'          => [ 'CN' => 'example.test' ],
		'issuer'           => [
			'O'  => "Let's Encrypt",
			'CN' => $issuer,
		],
		'serialNumberHex'  => $serial,
		'extensions'       => [ 'subjectAltName' => 'DNS:example.test, DNS:www.example.test' ],
		'validFrom_time_t' => $now - 80 * DAY_IN_SECONDS,
		'validTo_time_t'   => $now + $days_left * DAY_IN_SECONDS + 3600,
	];
};
