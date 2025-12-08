/**
 * Status Card Component
 *
 * Displays plugin status information.
 *
 * @package WP_DBAL
 */

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Status card component.
 *
 * @param {Object} props Component props.
 * @param {Object} props.status Status data.
 * @return {JSX.Element} Status card.
 */
export default function StatusCard({ status }) {
	return (
		<Card>
			<CardHeader>
				<h2>{__('Status', 'wp-dbal')}</h2>
			</CardHeader>
			<CardBody>
				<table className="form-table">
					<tbody>
						<tr>
							<th>{__('Drop-in Status', 'wp-dbal')}</th>
							<td>
								{status.dropin_installed ? (
									<span style={{ color: 'green' }}>
										&#10003; {__('Installed', 'wp-dbal')}
									</span>
								) : (
									<span style={{ color: 'red' }}>
										&#10007; {__('Not Installed', 'wp-dbal')}
									</span>
								)}
							</td>
						</tr>
						<tr>
							<th>{__('Database Engine', 'wp-dbal')}</th>
							<td>
								<code>{status.db_engine}</code>
							</td>
						</tr>
						<tr>
							<th>{__('DBAL Version', 'wp-dbal')}</th>
							<td>
								{status.dbal_loaded ? (
									<code>{status.dbal_version || __('Loaded', 'wp-dbal')}</code>
								) : (
									<span>{__('Not loaded (run composer install)', 'wp-dbal')}</span>
								)}
							</td>
						</tr>
					</tbody>
				</table>
			</CardBody>
		</Card>
	);
}

