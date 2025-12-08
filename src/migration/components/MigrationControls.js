/**
 * Migration Controls Component
 *
 * Start/cancel migration buttons.
 *
 * @package WP_DBAL
 */

import { Button, ButtonGroup } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Migration controls component.
 *
 * @param {Object} props Component props.
 * @param {boolean} props.isMigrating Whether migration is in progress.
 * @param {boolean} props.canStart Whether migration can be started.
 * @param {Function} props.onStart Callback to start migration.
 * @param {Function} props.onCancel Callback to cancel migration.
 * @param {Object|null} props.progress Current progress.
 * @return {JSX.Element} Migration controls.
 */
export default function MigrationControls({
	isMigrating,
	canStart,
	onStart,
	onCancel,
	progress,
}) {
	// Don't show controls when migration is completed (ConfigUpdatePrompt handles that).
	if (progress?.status === 'completed') {
		return null;
	}

	return (
		<div className="wp-dbal-migration-controls">
			<ButtonGroup>
				<Button
					variant="primary"
					onClick={onStart}
					disabled={!canStart || isMigrating}
				>
					{isMigrating ? __('Migrating...', 'wp-dbal') : __('Start Migration', 'wp-dbal')}
				</Button>

				{isMigrating && (
					<Button
						variant="secondary"
						onClick={onCancel}
					>
						{__('Cancel', 'wp-dbal')}
					</Button>
				)}
			</ButtonGroup>
		</div>
	);
}

