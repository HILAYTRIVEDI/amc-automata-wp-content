<?php
/**
 * Deterministic site facts for report tests.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\SiteFacts;

/**
 * Class StubSiteFacts
 *
 * A small fixed site, so snapshot tests never depend on the real plugins,
 * users or server of the test install.
 */
final class StubSiteFacts extends SiteFacts {

	/**
	 * Plugins the stub site "has installed".
	 *
	 * @var list<array{file: string, slug: string, name: string, version: string, active: bool}>
	 */
	public array $installed = [
		[
			'file'    => 'akismet/akismet.php',
			'slug'    => 'akismet',
			'name'    => 'Akismet',
			'version' => '5.3',
			'active'  => true,
		],
		[
			'file'    => 'hello/hello.php',
			'slug'    => 'hello',
			'name'    => 'Hello Dolly',
			'version' => '1.7',
			'active'  => false,
		],
		[
			'file'    => 'old-seo/old-seo.php',
			'slug'    => 'old-seo',
			'name'    => 'Old SEO',
			'version' => '2.0',
			'active'  => false,
		],
	];

	/**
	 * {@inheritDoc}
	 */
	public function home_url(): string {
		return 'https://example.test';
	}

	/**
	 * {@inheritDoc}
	 */
	public function site_name(): string {
		return 'North West Trust';
	}

	/**
	 * {@inheritDoc}
	 */
	public function wp_version(): string {
		return '6.9.2';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_multisite(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function plugins(): array {
		return $this->installed;
	}

	/**
	 * {@inheritDoc}
	 */
	public function themes_installed(): int {
		return 3;
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_theme(): array {
		return [
			'active' => [
				'name'    => 'Twenty Child',
				'slug'    => 'twenty-child',
				'version' => '1.1',
			],
			'parent' => [
				'name'    => 'Twenty Twenty-Five',
				'slug'    => 'twentytwentyfive',
				'version' => '1.4',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function editor_type(): string {
		return 'block';
	}

	/**
	 * {@inheritDoc}
	 */
	public function user_counts(): array {
		return [
			'total' => 12,
			'roles' => [
				[
					'role'  => 'administrator',
					'label' => 'Administrator',
					'count' => 2,
				],
				[
					'role'  => 'subscriber',
					'label' => 'Subscriber',
					'count' => 10,
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function administrators(): array {
		return [
			[
				'login'        => 'boss',
				'display_name' => 'The Boss',
				'email'        => 'boss@example.test',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function users_registered( string $since, string $until ): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function host(): ?string {
		return 'WP Engine';
	}

	/**
	 * {@inheritDoc}
	 */
	public function server_type(): ?string {
		return 'Linux';
	}

	/**
	 * {@inheritDoc}
	 */
	public function web_server(): ?string {
		return 'nginx/1.25';
	}

	/**
	 * {@inheritDoc}
	 */
	public function php_version(): string {
		return '8.3.4';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mysql_version(): ?string {
		return '8.0.36';
	}

	/**
	 * {@inheritDoc}
	 */
	public function memory_limit(): ?string {
		return '256M';
	}

	/**
	 * {@inheritDoc}
	 */
	public function php_recommendation(): ?string {
		return 'PHP 8.3.4 is supported and up to date.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function object_cache(): string {
		return 'Redis';
	}

	/**
	 * {@inheritDoc}
	 */
	public function autoload_size_bytes(): int {
		return 512000;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_author(): int {
		return 0;
	}
}
