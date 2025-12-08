/**
 * Current Configuration Component
 *
 * Displays and allows editing of current database configuration.
 *
 * @package WP_DBAL
 */

import { useState, useEffect } from '@wordpress/element';
import { Card, CardBody, CardHeader, SelectControl, TextControl, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Current configuration component.
 *
 * @return {JSX.Element} Current configuration card.
 */
export default function CurrentConfiguration() {
	const [config, setConfig] = useState(null);
	const [loading, setLoading] = useState(true);
	const [editing, setEditing] = useState(false);
	const [saving, setSaving] = useState(false);
	const [error, setError] = useState(null);
	const [success, setSuccess] = useState(null);

	// Form state
	const [dbEngine, setDbEngine] = useState('mysql');
	const [connectionParams, setConnectionParams] = useState({});

	// Fetch configuration on mount.
	useEffect(() => {
		fetchConfiguration();
	}, []);

	/**
	 * Fetch current configuration.
	 */
	const fetchConfiguration = async () => {
		setLoading(true);
		setError(null);

		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/admin/configuration',
			});

			if (response.success) {
				setConfig(response.data);
				setDbEngine(response.data.db_engine || 'mysql');
				setConnectionParams(response.data.connection_params || {});
			} else {
				setError(__('Failed to fetch configuration', 'wp-dbal'));
			}
		} catch (err) {
			setError(err.message || __('Failed to fetch configuration', 'wp-dbal'));
		} finally {
			setLoading(false);
		}
	};

	/**
	 * Handle save configuration.
	 */
	const handleSave = async () => {
		setSaving(true);
		setError(null);
		setSuccess(null);

		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/admin/configuration',
				method: 'POST',
				data: {
					db_engine: dbEngine,
					connection_params: connectionParams,
				},
			});

			if (response.success) {
				setSuccess(__('Configuration updated successfully. The page will reload.', 'wp-dbal'));
				setEditing(false);
				// Reload after a short delay to show success message.
				setTimeout(() => {
					window.location.reload();
				}, 1500);
			} else {
				setError(response.message || __('Failed to update configuration', 'wp-dbal'));
			}
		} catch (err) {
			setError(err.message || __('Failed to update configuration', 'wp-dbal'));
		} finally {
			setSaving(false);
		}
	};

	/**
	 * Handle parameter change.
	 */
	const handleParamChange = (key, value) => {
		setConnectionParams((prev) => ({
			...prev,
			[key]: value,
		}));
	};

	if (loading) {
		return (
			<Card>
				<CardHeader>
					<h2>{__('Current Configuration', 'wp-dbal')}</h2>
				</CardHeader>
				<CardBody>
					<p>{__('Loading...', 'wp-dbal')}</p>
				</CardBody>
			</Card>
		);
	}

	if (!config) {
		return null;
	}

	const engineOptions = [
		{ label: __('MySQL', 'wp-dbal'), value: 'mysql' },
		{ label: __('PostgreSQL', 'wp-dbal'), value: 'pgsql' },
		{ label: __('SQLite', 'wp-dbal'), value: 'sqlite' },
		{ label: __('FileDB', 'wp-dbal'), value: 'filedb' },
		{ label: __('Cloudflare D1', 'wp-dbal'), value: 'd1' },
	];

	return (
		<Card>
			<CardHeader>
				<h2>{__('Current Configuration', 'wp-dbal')}</h2>
			</CardHeader>
			<CardBody>
				{error && (
					<Notice status="error" onRemove={() => setError(null)}>
						{error}
					</Notice>
				)}

				{success && (
					<Notice status="success" isDismissible={false}>
						{success}
					</Notice>
				)}

				{!editing ? (
					<>
						<table className="form-table">
							<tbody>
								<tr>
									<th>{__('Database Engine', 'wp-dbal')}</th>
									<td>
										<code>{config.db_engine || 'mysql'}</code>
									</td>
								</tr>
								{config.db_engine === 'mysql' && (
									<>
										{config.connection_params?.dbname && (
											<tr>
												<th>{__('Database Name', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.dbname}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.user && (
											<tr>
												<th>{__('Database User', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.user}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.host && (
											<tr>
												<th>{__('Database Host', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.host}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.charset && (
											<tr>
												<th>{__('Database Charset', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.charset}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.collate && (
											<tr>
												<th>{__('Database Collate', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.collate}</code>
												</td>
											</tr>
										)}
									</>
								)}
								{config.db_engine === 'sqlite' && config.connection_params?.path && (
									<tr>
										<th>{__('SQLite Path', 'wp-dbal')}</th>
										<td>
											<code>{config.connection_params.path}</code>
										</td>
									</tr>
								)}
								{config.db_engine === 'filedb' && (
									<>
										{config.connection_params?.path && (
											<tr>
												<th>{__('FileDB Path', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.path}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.format && (
											<tr>
												<th>{__('FileDB Format', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.format}</code>
												</td>
											</tr>
										)}
									</>
								)}
								{config.db_engine === 'd1' && (
									<>
										{config.connection_params?.account_id && (
											<tr>
												<th>{__('D1 Account ID', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.account_id}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.database_id && (
											<tr>
												<th>{__('D1 Database ID', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.database_id}</code>
												</td>
											</tr>
										)}
									</>
								)}
								{(config.db_engine === 'pgsql' || config.db_engine === 'postgresql') && (
									<>
										{config.connection_params?.host && (
											<tr>
												<th>{__('PostgreSQL Host', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.host}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.port && (
											<tr>
												<th>{__('PostgreSQL Port', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.port}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.dbname && (
											<tr>
												<th>{__('PostgreSQL Database', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.dbname}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.user && (
											<tr>
												<th>{__('PostgreSQL User', 'wp-dbal')}</th>
												<td>
													<code>{config.connection_params.user}</code>
												</td>
											</tr>
										)}
										{config.connection_params?.password && (
											<tr>
												<th>{__('PostgreSQL Password', 'wp-dbal')}</th>
												<td>
													<code>••••••••</code>
												</td>
											</tr>
										)}
									</>
								)}
							</tbody>
						</table>
						<Button variant="secondary" onClick={() => setEditing(true)} style={{ marginTop: '15px' }}>
							{__('Edit Configuration', 'wp-dbal')}
						</Button>
					</>
				) : (
					<>
						<SelectControl
							label={__('Database Engine', 'wp-dbal')}
							value={dbEngine}
							options={engineOptions}
							onChange={setDbEngine}
						/>

						{dbEngine === 'mysql' && (
							<>
								<TextControl
									label={__('Database Name', 'wp-dbal')}
									value={connectionParams.dbname || ''}
									onChange={(value) => handleParamChange('dbname', value)}
									help={__('The name of the database for WordPress.', 'wp-dbal')}
								/>
								<TextControl
									label={__('Database User', 'wp-dbal')}
									value={connectionParams.user || ''}
									onChange={(value) => handleParamChange('user', value)}
									help={__('Database username.', 'wp-dbal')}
								/>
								<TextControl
									label={__('Database Password', 'wp-dbal')}
									value={connectionParams.password || ''}
									onChange={(value) => handleParamChange('password', value)}
									type="password"
									help={__('Database password.', 'wp-dbal')}
								/>
								<TextControl
									label={__('Database Host', 'wp-dbal')}
									value={connectionParams.host || 'localhost'}
									onChange={(value) => handleParamChange('host', value)}
									help={__('Database hostname.', 'wp-dbal')}
								/>
								<TextControl
									label={__('Database Charset', 'wp-dbal')}
									value={connectionParams.charset || 'utf8'}
									onChange={(value) => handleParamChange('charset', value)}
									help={__('Database charset to use in creating database tables.', 'wp-dbal')}
								/>
								<TextControl
									label={__('Database Collate', 'wp-dbal')}
									value={connectionParams.collate || ''}
									onChange={(value) => handleParamChange('collate', value)}
									help={__('The database collate type. Leave empty if in doubt.', 'wp-dbal')}
								/>
							</>
						)}

						{dbEngine === 'sqlite' && (
							<TextControl
								label={__('SQLite Database Path', 'wp-dbal')}
								value={connectionParams.path || ''}
								onChange={(value) => handleParamChange('path', value)}
								help={__('Path to the SQLite database file (e.g., wp-content/database/.ht.sqlite).', 'wp-dbal')}
							/>
						)}

						{dbEngine === 'filedb' && (
							<>
								<TextControl
									label={__('FileDB Storage Path', 'wp-dbal')}
									value={connectionParams.path || ''}
									onChange={(value) => handleParamChange('path', value)}
									help={__('Path to the directory where FileDB will store data (e.g., wp-content/file-db).', 'wp-dbal')}
								/>
								<SelectControl
									label={__('FileDB Format', 'wp-dbal')}
									value={connectionParams.format || 'json'}
									options={[
										{ label: 'JSON', value: 'json' },
										{ label: 'PHP (serialized)', value: 'php' },
									]}
									onChange={(value) => handleParamChange('format', value)}
								/>
							</>
						)}

						{dbEngine === 'd1' && (
							<>
								<TextControl
									label={__('Cloudflare Account ID', 'wp-dbal')}
									value={connectionParams.account_id || ''}
									onChange={(value) => handleParamChange('account_id', value)}
								/>
								<TextControl
									label={__('Cloudflare D1 Database ID', 'wp-dbal')}
									value={connectionParams.database_id || ''}
									onChange={(value) => handleParamChange('database_id', value)}
								/>
								<TextControl
									label={__('Cloudflare API Token', 'wp-dbal')}
									value={connectionParams.api_token || ''}
									onChange={(value) => handleParamChange('api_token', value)}
									type="password"
								/>
							</>
						)}

						{(dbEngine === 'pgsql' || dbEngine === 'postgresql') && (
							<>
								<TextControl
									label={__('PostgreSQL Host', 'wp-dbal')}
									value={connectionParams.host || ''}
									onChange={(value) => handleParamChange('host', value)}
								/>
								<TextControl
									label={__('PostgreSQL Port', 'wp-dbal')}
									value={connectionParams.port || ''}
									onChange={(value) => handleParamChange('port', value)}
									type="number"
								/>
								<TextControl
									label={__('PostgreSQL Database Name', 'wp-dbal')}
									value={connectionParams.dbname || ''}
									onChange={(value) => handleParamChange('dbname', value)}
								/>
								<TextControl
									label={__('PostgreSQL User', 'wp-dbal')}
									value={connectionParams.user || ''}
									onChange={(value) => handleParamChange('user', value)}
								/>
								<TextControl
									label={__('PostgreSQL Password', 'wp-dbal')}
									value={connectionParams.password || ''}
									onChange={(value) => handleParamChange('password', value)}
									type="password"
								/>
							</>
						)}

						<div style={{ marginTop: '20px' }}>
							<Button
								variant="primary"
								onClick={handleSave}
								isBusy={saving}
								disabled={saving}
							>
								{__('Save Configuration', 'wp-dbal')}
							</Button>
							<Button
								variant="secondary"
								onClick={() => {
									setEditing(false);
									setError(null);
									// Reset to original values.
									setDbEngine(config.db_engine || 'mysql');
									setConnectionParams(config.connection_params || {});
								}}
								disabled={saving}
								style={{ marginLeft: '10px' }}
							>
								{__('Cancel', 'wp-dbal')}
							</Button>
						</div>
					</>
				)}
			</CardBody>
		</Card>
	);
}

