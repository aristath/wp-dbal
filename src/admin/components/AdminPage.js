/**
 * Admin Page Component
 *
 * Main admin page container for WP-DBAL.
 *
 * @package WP_DBAL
 */

import { useState, useEffect } from '@wordpress/element';
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import StatusCard from './StatusCard';
import DropinControls from './DropinControls';
import CurrentConfiguration from './CurrentConfiguration';
import ConfigurationCard from './ConfigurationCard';
import MigrationUI from '../../migration/components/MigrationUI';

// Set up API fetch nonce middleware.
if (typeof window !== 'undefined' && window.wpDbalAdmin?.restNonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(window.wpDbalAdmin.restNonce));
}

/**
 * Admin page component.
 *
 * @return {JSX.Element} Admin page.
 */
export default function AdminPage() {
	const [status, setStatus] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [showConfigCard, setShowConfigCard] = useState(false);
	const [configParams, setConfigParams] = useState(null);

	// Fetch status on mount.
	useEffect(() => {
		fetchStatus();
	}, []);

	/**
	 * Fetch admin status.
	 */
	const fetchStatus = async () => {
		setLoading(true);
		setError(null);

		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/admin/status',
			});

			if (response.success) {
				setStatus(response.data);
			} else {
				setError(__('Failed to fetch status', 'wp-dbal'));
			}
		} catch (err) {
			setError(err.message || __('Failed to fetch status', 'wp-dbal'));
		} finally {
			setLoading(false);
		}
	};

	/**
	 * Handle dropin action (install/remove).
	 *
	 * @param {string} action Action to perform ('install' or 'remove').
	 */
	const handleDropinAction = async (action) => {
		setError(null);

		try {
			const endpoint = action === 'install' ? '/wp-dbal/v1/admin/dropin/install' : '/wp-dbal/v1/admin/dropin/remove';
			const response = await apiFetch({
				path: endpoint,
				method: 'POST',
			});

			if (response.success) {
				// Refresh status after action.
				await fetchStatus();
			} else {
				setError(response.message || __('Action failed', 'wp-dbal'));
			}
		} catch (err) {
			setError(err.message || __('Action failed', 'wp-dbal'));
		}
	};

	if (loading && !status) {
		return (
			<div className="wrap">
				<h1>{__('WP-DBAL Settings', 'wp-dbal')}</h1>
				<p>{__('Loading...', 'wp-dbal')}</p>
			</div>
		);
	}

	return (
		<div className="wrap">
			<h1>{__('WP-DBAL Settings', 'wp-dbal')}</h1>

			{error && (
				<div className="notice notice-error">
					<p>{error}</p>
				</div>
			)}

			{status && (
				<>
					<CurrentConfiguration />
					<StatusCard status={status} />
					<DropinControls
						dropinInstalled={status.dropin_installed}
						onAction={handleDropinAction}
					/>
					{showConfigCard && configParams && (
						<ConfigurationCard
							targetEngine={configParams.targetEngine}
							connectionParams={configParams.connectionParams}
						/>
					)}
					<Card style={{ marginTop: '20px' }}>
						<CardHeader>
							<h2>{__('Database Migration', 'wp-dbal')}</h2>
						</CardHeader>
						<CardBody>
							<MigrationUI
								currentEngine={status.db_engine}
								onConfigChoice={(choice, params) => {
									if (choice === 'manual') {
										setShowConfigCard(true);
										setConfigParams(params);
									}
								}}
							/>
						</CardBody>
					</Card>
				</>
			)}
		</div>
	);
}

