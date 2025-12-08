/**
 * Database Selector Component
 *
 * Allows user to select target database backend and configure connection.
 *
 * @package WP_DBAL
 */

import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Database selector component.
 *
 * @param {Object} props Component props.
 * @param {string} props.currentEngine Current database engine.
 * @param {string} props.targetEngine Selected target engine.
 * @param {Function} props.onTargetEngineChange Callback when target engine changes.
 * @param {Object} props.connectionParams Connection parameters.
 * @param {Function} props.onConnectionParamsChange Callback when connection params change.
 * @return {JSX.Element} Database selector.
 */
export default function DatabaseSelector({
	currentEngine,
	targetEngine,
	onTargetEngineChange,
	connectionParams,
	onConnectionParamsChange,
}) {
	const engines = [
		{ label: __('MySQL', 'wp-dbal'), value: 'mysql' },
		{ label: __('PostgreSQL', 'wp-dbal'), value: 'pgsql' },
		{ label: __('SQLite', 'wp-dbal'), value: 'sqlite' },
		{ label: __('FileDB', 'wp-dbal'), value: 'filedb' },
		{ label: __('Cloudflare D1', 'wp-dbal'), value: 'd1' },
	].filter((engine) => engine.value !== currentEngine);

	/**
	 * Update connection parameter.
	 *
	 * @param {string} key Parameter key.
	 * @param {string|number} value Parameter value.
	 */
	const updateConnectionParam = (key, value) => {
		onConnectionParamsChange({
			...connectionParams,
			[key]: value,
		});
	};

	return (
		<div className="wp-dbal-database-selector">
			<SelectControl
				label={__('Target Database Engine', 'wp-dbal')}
				value={targetEngine}
				options={[
					{ label: __('Select target engine...', 'wp-dbal'), value: '' },
					...engines,
				]}
				onChange={onTargetEngineChange}
			/>

			{targetEngine === 'sqlite' && (
				<TextControl
					label={__('SQLite Database Path', 'wp-dbal')}
					value={connectionParams.path || ''}
					onChange={(value) => updateConnectionParam('path', value)}
					help={__('Path to SQLite database file (relative to WordPress root)', 'wp-dbal')}
				/>
			)}

			{targetEngine === 'filedb' && (
				<>
					<TextControl
						label={__('FileDB Storage Path', 'wp-dbal')}
						value={connectionParams.path || ''}
						onChange={(value) => updateConnectionParam('path', value)}
						help={__('Path to FileDB storage directory (relative to WordPress root)', 'wp-dbal')}
					/>
					<SelectControl
						label={__('FileDB Format', 'wp-dbal')}
						value={connectionParams.format || 'json'}
						options={[
							{ label: __('JSON', 'wp-dbal'), value: 'json' },
							{ label: __('PHP', 'wp-dbal'), value: 'php' },
						]}
						onChange={(value) => updateConnectionParam('format', value)}
					/>
				</>
			)}

			{targetEngine === 'd1' && (
				<>
					<TextControl
						label={__('D1 Account ID', 'wp-dbal')}
						value={connectionParams.account_id || ''}
						onChange={(value) => updateConnectionParam('account_id', value)}
					/>
					<TextControl
						label={__('D1 Database ID', 'wp-dbal')}
						value={connectionParams.database_id || ''}
						onChange={(value) => updateConnectionParam('database_id', value)}
					/>
					<TextControl
						label={__('D1 API Token', 'wp-dbal')}
						value={connectionParams.api_token || ''}
						onChange={(value) => updateConnectionParam('api_token', value)}
						type="password"
					/>
				</>
			)}

			{(targetEngine === 'mysql' || targetEngine === 'pgsql') && (
				<>
					<TextControl
						label={__('Host', 'wp-dbal')}
						value={connectionParams.host || 'localhost'}
						onChange={(value) => updateConnectionParam('host', value)}
					/>
					<TextControl
						label={__('Port', 'wp-dbal')}
						value={connectionParams.port || (targetEngine === 'pgsql' ? '5432' : '3306')}
						onChange={(value) => updateConnectionParam('port', parseInt(value, 10))}
						type="number"
					/>
					<TextControl
						label={__('Database Name', 'wp-dbal')}
						value={connectionParams.dbname || ''}
						onChange={(value) => updateConnectionParam('dbname', value)}
					/>
					<TextControl
						label={__('Username', 'wp-dbal')}
						value={connectionParams.user || ''}
						onChange={(value) => updateConnectionParam('user', value)}
					/>
					<TextControl
						label={__('Password', 'wp-dbal')}
						value={connectionParams.password || ''}
						onChange={(value) => updateConnectionParam('password', value)}
						type="password"
					/>
				</>
			)}
		</div>
	);
}

