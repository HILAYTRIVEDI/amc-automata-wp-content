module.exports = {
	rootDir: '../../',
	// Mirrors what @wordpress/jest-preset-default provided before
	// @wordpress/scripts 36 dropped it: a DOM, the same test discovery,
	// and style imports mapped to a mock. babel-jest is Jest's default
	// transform and reads babel.config.js.
	testEnvironment: 'jsdom',
	testMatch: [
		'**/__tests__/**/*.[jt]s?(x)',
		'**/test/*.[jt]s?(x)',
		'**/?(*.)test.[jt]s?(x)',
	],
	moduleNameMapper: {
		// npm installs several copies of @wordpress/private-apis (one per
		// dependent). Each keeps its own lock registry, so `unlock()` in
		// @wordpress/rich-text throws for objects locked by @wordpress/compose
		// when the real @wordpress/components is loaded. One copy fixes it.
		'^@wordpress/private-apis$':
			'<rootDir>/node_modules/@wordpress/compose/node_modules/@wordpress/private-apis',
		'^uuid$': '<rootDir>/tests/js/mocks/uuid-mock.js',
		'\\.(scss|css)$': '<rootDir>/tests/js/mocks/style-mock.js',
	},
	// Several @wordpress/* packages ship ESM-only `.mjs` builds that the real
	// @wordpress/components pulls in; compile them with the project's Babel
	// config. Everything else in node_modules stays untransformed.
	transform: {
		'\\.[cm]?[jt]sx?$': 'babel-jest',
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(@wordpress/(ui|theme|components|dataviews)|@base-ui)/)',
	],
	setupFiles: [ '<rootDir>/tests/js/setup-globals' ],
	testPathIgnorePatterns: [
		'<rootDir>/.git',
		'<rootDir>/node_modules',
		'<rootDir>/assets/build',
		'<rootDir>/vendor',
		// Add more specific patterns here if needed.
	],
	coveragePathIgnorePatterns: [
		'<rootDir>/node_modules',
		'<rootDir>/assets/build/',
		// Add more specific patterns here if needed.
	],
	modulePathIgnorePatterns: [
		// Add more specific patterns here if needed.
	],
	coverageReporters: [ 'lcov' ],
	coverageDirectory: '<rootDir>/tests/logs',
	reporters: [
		[ 'jest-silent-reporter', { useDots: true } ],
		'github-actions',
	],
};
