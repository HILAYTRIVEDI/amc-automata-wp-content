// `uuid` is ESM-only, which Jest cannot load without a transform. The real
// @wordpress/components imports it; random ids are all it needs.
const { randomUUID } = require( 'crypto' );

module.exports = { v4: randomUUID };
