<?php
/**
 * Plugin bootstrap file.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC;

use rtCamp\WPPrimitives\Contracts\Traits\Loader;
use rtCamp\WPPrimitives\Contracts\Traits\Singleton;

/**
 * Class - Main
 *
 * FROZEN IN WAVE 0. Lanes never edit this list. Each domain has its own
 * module (an AbstractModule) that the owning lane edits instead; see
 * docs/CONTRACTS.md § "Who may edit what".
 *
 * @since 0.1.0
 */
final class Main {

	use Singleton;
	use Loader;

	/*
	 * List of classes to load, in load order.
	 */
	public const CLASSES = [
		// Core infrastructure: always loaded (Wave 0).
		Core\Assets::class,
		Core\PluginSetup::class,
		Core\Components::class,
		Core\Templates::class,
		Core\Encryption::class,
		Core\Logger::class,
		Core\CoreModule::class,

		// One module per domain. The owning lane edits the module's get_classes().
		Checks\ChecksModule::class,           // Wave 0 + B1 + B2 (via their own provider files).
		Runner\RunnerModule::class,           // Wave 0.
		Runner\Cli\CliModule::class,          // Wave 0 (D1 owns its report command file).
		Rest\RestModule::class,               // Wave 0 (route map frozen; handlers owned per controller).
		Capture\CaptureModule::class,         // A1.
		Storage\StorageModule::class,         // A1 (Retention).
		Notify\NotifyModule::class,           // A2.
		Abilities\AbilitiesModule::class,     // B2.
		Admin\AdminModule::class,             // C.
		Reports\ReportsModule::class,         // D1.
		Reports\Blocks\SectionBlocksModule::class, // D2 (Wave 0 ships Overview).
	];

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->load( self::CLASSES );
	}
}
