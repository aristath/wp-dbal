/**
 * Admin React App Entry Point
 *
 * @package WP_DBAL
 */

import { render } from '@wordpress/element';
import AdminPage from './components/AdminPage';

// Wait for DOM to be ready.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initAdmin);
} else {
	initAdmin();
}

/**
 * Initialize the admin UI.
 *
 * @return void
 */
function initAdmin() {
	const container = document.getElementById('wp-dbal-admin-root');

	if (!container) {
		console.warn('WP-DBAL: Admin container not found. Looking for #wp-dbal-admin-root');
		return;
	}

	// Render admin page.
	try {
		render(<AdminPage />, container);
		console.log('WP-DBAL: Admin UI rendered successfully');
	} catch (error) {
		console.error('WP-DBAL: Error rendering admin UI', error);
	}
}

