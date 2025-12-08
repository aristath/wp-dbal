/**
 * Migration React App Entry Point
 *
 * @package WP_DBAL
 */

import { render } from '@wordpress/element';
import MigrationUI from './components/MigrationUI';

// Wait for DOM to be ready.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initMigration);
} else {
	initMigration();
}

/**
 * Initialize the migration UI.
 *
 * @return void
 */
function initMigration() {
	const container = document.getElementById('wp-dbal-migration-root');
	
	if (!container) {
		console.warn('WP-DBAL: Migration container not found. Looking for #wp-dbal-migration-root');
		return;
	}

	// Render migration UI.
	try {
		render(<MigrationUI />, container);
		console.log('WP-DBAL: Migration UI rendered successfully');
	} catch (error) {
		console.error('WP-DBAL: Error rendering migration UI', error);
	}
}

