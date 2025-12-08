/**
 * Dropin Controls Component
 *
 * Controls for installing/removing the db.php drop-in.
 *
 * @package WP_DBAL
 */

import { Card, CardBody, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Dropin controls component.
 *
 * @param {Object} props Component props.
 * @param {boolean} props.dropinInstalled Whether dropin is installed.
 * @param {Function} props.onAction Callback when action is performed.
 * @return {JSX.Element} Dropin controls.
 */
export default function DropinControls({ dropinInstalled, onAction }) {
	const [isProcessing, setIsProcessing] = useState(false);

	/**
	 * Handle button click.
	 *
	 * @param {string} action Action to perform.
	 */
	const handleClick = async (action) => {
		setIsProcessing(true);
		await onAction(action);
		setIsProcessing(false);
	};

	return (
		<Card style={{ marginTop: '20px' }}>
			<CardBody>
				<h3>{__('Drop-in Management', 'wp-dbal')}</h3>
				<p>
					{__(
						'The db.php drop-in is required for WP-DBAL to function. It intercepts WordPress database calls and routes them through Doctrine DBAL.',
						'wp-dbal'
					)}
				</p>
				{dropinInstalled ? (
					<Button
						variant="secondary"
						onClick={() => handleClick('remove')}
						isBusy={isProcessing}
						disabled={isProcessing}
					>
						{__('Remove Drop-in', 'wp-dbal')}
					</Button>
				) : (
					<Button
						variant="primary"
						onClick={() => handleClick('install')}
						isBusy={isProcessing}
						disabled={isProcessing}
					>
						{__('Install Drop-in', 'wp-dbal')}
					</Button>
				)}
			</CardBody>
		</Card>
	);
}

