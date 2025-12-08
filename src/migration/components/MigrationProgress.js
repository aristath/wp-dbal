/**
 * Migration Progress Component
 *
 * Displays migration progress.
 *
 * @package WP_DBAL
 */

import { ProgressBar, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Migration progress component.
 *
 * @param {Object} props Component props.
 * @param {Object} props.progress Progress data.
 * @return {JSX.Element} Migration progress.
 */
export default function MigrationProgress({ progress }) {
	if (!progress) {
		return null;
	}

	const getStepLabel = (step) => {
		const labels = {
			schema_export: __('Exporting schemas...', 'wp-dbal'),
			schema_import: __('Importing schemas...', 'wp-dbal'),
			data_export: __('Exporting data...', 'wp-dbal'),
			data_import: __('Importing data...', 'wp-dbal'),
			finalize: __('Finalizing migration...', 'wp-dbal'),
			completed: __('Migration completed!', 'wp-dbal'),
		};

		return labels[step] || step;
	};

	const calculateProgress = () => {
		if (progress.status === 'completed') {
			return 100;
		}

		if (progress.status === 'failed') {
			return 0;
		}

		// Calculate progress based on step and tables/rows.
		let baseProgress = 0;
		const stepWeights = {
			schema_export: 10,
			schema_import: 10,
			data_export: 40,
			data_import: 40,
		};

		// Add progress for completed steps.
		const steps = ['schema_export', 'schema_import', 'data_export', 'data_import'];
		const currentStepIndex = steps.indexOf(progress.step);

		for (let i = 0; i < currentStepIndex; i++) {
			baseProgress += stepWeights[steps[i]] || 0;
		}

		// Add progress for current step.
		if (currentStepIndex >= 0 && stepWeights[progress.step]) {
			const stepProgress = progress.tables_total > 0
				? (progress.tables_completed / progress.tables_total) * stepWeights[progress.step]
				: 0;
			baseProgress += stepProgress;
		}

		return Math.min(100, Math.max(0, baseProgress));
	};

	const progressPercent = calculateProgress();

	return (
		<div className="wp-dbal-migration-progress">
			<h3>{getStepLabel(progress.step)}</h3>

			{progress.status === 'failed' && (
				<Notice status="error" isDismissible={false}>
					{progress.error || __('Migration failed', 'wp-dbal')}
				</Notice>
			)}

			{progress.status === 'completed' && (
				<Notice status="success" isDismissible={false}>
					{__('Migration completed successfully!', 'wp-dbal')}
				</Notice>
			)}

			{progress.status === 'running' && (
				<>
					<ProgressBar value={progressPercent} />
					<p>
						{progress.current_table && (
							<span>
								{__('Current table:', 'wp-dbal')} <strong>{progress.current_table}</strong>
								<br />
							</span>
						)}
						{progress.tables_total > 0 && (
							<span>
								{__('Tables:', 'wp-dbal')} {progress.tables_completed} / {progress.tables_total}
							</span>
						)}
					</p>
				</>
			)}
		</div>
	);
}

