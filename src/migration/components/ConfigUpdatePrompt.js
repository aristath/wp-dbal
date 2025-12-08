/**
 * Config Update Prompt Component
 *
 * Prompts user to choose automatic or manual wp-config.php update after migration.
 *
 * @package WP_DBAL
 */

import { useState } from '@wordpress/element';
import { Button, ButtonGroup, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Config update prompt component.
 *
 * @param {Object} props Component props.
 * @param {string} props.targetEngine Target database engine.
 * @param {Object} props.connectionParams Connection parameters.
 * @param {Function} props.onComplete Callback when update is complete.
 * @return {JSX.Element} Config update prompt.
 */
export default function ConfigUpdatePrompt({ targetEngine, connectionParams, onComplete }) {
	const [choice, setChoice] = useState(null); // 'auto', 'manual', or null
	const [updating, setUpdating] = useState(false);
	const [error, setError] = useState(null);

	/**
	 * Handle automatic update.
	 */
	const handleAutomatic = async () => {
		setUpdating(true);
		setError(null);

		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/migration/update-config',
				method: 'POST',
				data: {
					target_engine: targetEngine,
					connection_params: connectionParams,
				},
			});

			if (response.success) {
				// Reload page to apply new DB_ENGINE.
				window.location.reload();
			} else {
				setError(response.message || __('Failed to update wp-config.php', 'wp-dbal'));
				setUpdating(false);
			}
		} catch (err) {
			setError(err.message || __('Failed to update wp-config.php', 'wp-dbal'));
			setUpdating(false);
		}
	};

	/**
	 * Handle manual update choice.
	 */
	const handleManual = () => {
		setChoice('manual');
		onComplete('manual');
	};

	if (choice === 'manual') {
		return null; // ConfigurationCard will be shown instead.
	}

	return (
		<div className="wp-dbal-config-update-prompt" style={{ marginTop: '20px' }}>
			<Notice status="success" isDismissible={false}>
				<p>
					<strong>{__('Migration completed successfully!', 'wp-dbal')}</strong>
				</p>
				<p>
					{__(
						'Would you like us to automatically update your wp-config.php file, or would you prefer to update it manually?',
						'wp-dbal'
					)}
				</p>
			</Notice>

			{error && (
				<Notice status="error" isDismissible={false} style={{ marginTop: '10px' }}>
					{error}
				</Notice>
			)}

			<ButtonGroup style={{ marginTop: '15px' }}>
				<Button
					variant="primary"
					onClick={handleAutomatic}
					isBusy={updating}
					disabled={updating}
				>
					{__('Update Automatically', 'wp-dbal')}
				</Button>
				<Button
					variant="secondary"
					onClick={handleManual}
					disabled={updating}
				>
					{__('I\'ll Update Manually', 'wp-dbal')}
				</Button>
			</ButtonGroup>
		</div>
	);
}

