<?php
/**
 * Report section blocks module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class SectionBlocksModule
 *
 * Owner: lane D2 (Wave 0 ships the Overview exemplar). Add one line per
 * section block class, in report order. See docs/CONTRACTS.md
 * § "How to add a section block".
 *
 * @since 0.1.0
 */
final class SectionBlocksModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			OverviewSection::class,
			CmsSection::class,
			ThemeSection::class,
			PluginsThemesSection::class,
			DatabaseBackupsSection::class,
			UserManagementSection::class,
			HostingSection::class,
			DomainsSection::class,
			SslSection::class,
			CachingCdnSection::class,
			SecuritySection::class,
			FormsSection::class,
			AnalyticsSection::class,
			AccessibilitySection::class,
			PhpErrorsSection::class,
			SmokeChecksSection::class,
			PerformanceSection::class,
			EngineerNotesSection::class,
		];
	}
}
