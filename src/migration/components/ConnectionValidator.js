/**
 * Connection Validator Component
 *
 * Validates target database connection.
 *
 * @package WP_DBAL
 */

import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Connection validator component.
 *
 * @param {Object} props Component props.
 * @param {boolean} props.isValidating Whether validation is in progress.
 * @param {Object|null} props.validationResult Validation result.
 * @param {Function} props.onValidate Callback to validate connection.
 * @return {JSX.Element} Connection validator.
 */
export default function ConnectionValidator({
	isValidating,
	validationResult,
	onValidate,
}) {
	if (!validationResult && !isValidating) {
		return (
			<div className="wp-dbal-connection-validator">
				<Button
					variant="secondary"
					onClick={onValidate}
					disabled={isValidating}
				>
					{__('Validate Connection', 'wp-dbal')}
				</Button>
			</div>
		);
	}

	return (
		<div className="wp-dbal-connection-validator">
			{isValidating && (
				<div>
					<Spinner />
					<span>{__('Validating connection...', 'wp-dbal')}</span>
				</div>
			)}

			{validationResult && !isValidating && (
				<Notice
					status={validationResult.success ? 'success' : 'error'}
					isDismissible={false}
				>
					{validationResult.message}
				</Notice>
			)}
		</div>
	);
}

