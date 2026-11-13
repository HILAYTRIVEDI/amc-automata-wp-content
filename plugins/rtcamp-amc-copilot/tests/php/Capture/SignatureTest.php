<?php
/**
 * Tests for signatures, sources and normalisation.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\Signature;
use AMC\Tests\TestCase;

/**
 * Class SignatureTest
 */
final class SignatureTest extends TestCase {

	public function test_numbers_and_paths_do_not_change_the_signature(): void {
		$a = Signature::of( 'warning', 'wp-content/plugins/x/x.php', 10, 'Undefined array key 5 in /var/www/a/wp-content/plugins/x/x.php' );
		$b = Signature::of( 'warning', 'wp-content/plugins/x/x.php', 10, 'Undefined array key 912 in C:\\sites\\b\\wp-content\\plugins\\x\\x.php' );

		$this->assertSame( $a, $b );
		$this->assertSame( 32, strlen( $a ) );
	}

	public function test_type_file_and_line_change_the_signature(): void {
		$base = Signature::of( 'warning', 'a.php', 1, 'm' );

		$this->assertNotSame( $base, Signature::of( 'notice', 'a.php', 1, 'm' ) );
		$this->assertNotSame( $base, Signature::of( 'warning', 'b.php', 1, 'm' ) );
		$this->assertNotSame( $base, Signature::of( 'warning', 'a.php', 2, 'm' ) );
		$this->assertNotSame( $base, Signature::of( 'warning', 'a.php', 1, 'other' ) );
	}

	public function test_signature_follows_the_contract_formula(): void {
		$this->assertSame( md5( 'fatal|a.php|7|Call to undefined function N()' ), Signature::of( 'fatal', 'a.php', 7, 'Call to undefined function 42()' ) );
	}

	public function test_message_keeps_urls_and_words(): void {
		$this->assertSame( 'Could not fetch https://example.com/feed (code N)', Signature::message( 'Could not fetch https://example.com/feed (code 404)' ) );
		$this->assertSame( 'include(<path>): Failed to open stream', Signature::message( 'include(/srv/www/x.php): Failed to open stream' ) );
	}

	public function test_sources(): void {
		$this->assertSame( 'plugin:akismet', Signature::source( WP_PLUGIN_DIR . '/akismet/class.akismet.php' ) );
		$this->assertSame( 'plugin:hello', Signature::source( WP_PLUGIN_DIR . '/hello.php' ) );
		$this->assertSame( 'theme:twentytwentyfive', Signature::source( WP_CONTENT_DIR . '/themes/twentytwentyfive/functions.php' ) );
		$this->assertSame( 'mu-plugin:loader.php', Signature::source( WPMU_PLUGIN_DIR . '/loader.php' ) );
		$this->assertSame( 'core', Signature::source( ABSPATH . 'wp-includes/functions.php' ) );
		$this->assertSame( 'core', Signature::source( ABSPATH . 'wp-admin/includes/plugin.php' ) );
		$this->assertSame( 'core', Signature::source( ABSPATH . 'wp-settings.php' ) );
		$this->assertSame( 'unknown', Signature::source( '/somewhere/else.php' ) );
		$this->assertSame( 'unknown', Signature::source( '' ) );
	}

	public function test_relative_file_is_relative_to_the_wordpress_root(): void {
		$this->assertSame( 'wp-includes/functions.php', Signature::relative_file( ABSPATH . 'wp-includes/functions.php' ) );
		$this->assertSame( '/somewhere/else.php', Signature::relative_file( '/somewhere/else.php' ) );
		$this->assertSame( '', Signature::relative_file( '' ) );
	}

	public function test_sql_normalisation_removes_literals(): void {
		$a = Signature::sql( "SELECT * FROM wp_posts WHERE ID IN (1, 2, 3) AND post_title = 'It''s'  /* x */ LIMIT 10" );
		$b = Signature::sql( "SELECT * FROM wp_posts WHERE ID IN (9) AND post_title = 'other' LIMIT 50" );

		$this->assertSame( 'SELECT * FROM wp_posts WHERE ID IN (?) AND post_title = ? LIMIT ?', $a );
		$this->assertSame( $a, $b );
		$this->assertStringContainsString( 'wp_2_posts', Signature::sql( 'SELECT 1 FROM wp_2_posts' ) );
	}
}
