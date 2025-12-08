/**
 * Configuration Card Component
 *
 * Displays configuration instructions with actual migration settings.
 *
 * @package WP_DBAL
 */

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Configuration card component.
 *
 * @param {Object} props Component props.
 * @param {string} props.targetEngine Target database engine.
 * @param {Object} props.connectionParams Connection parameters.
 * @return {JSX.Element} Configuration card.
 */
export default function ConfigurationCard({ targetEngine, connectionParams = {} }) {
	if (!targetEngine) {
		return null;
	}

	/**
	 * Generate configuration code based on engine and params.
	 */
	const generateConfigCode = () => {
		let code = `define( 'DB_ENGINE', '${targetEngine}' );\n\n`;

		switch (targetEngine) {
			case 'sqlite':
				if (connectionParams.path) {
					code += `define( 'DB_SQLITE_PATH', '${connectionParams.path}' );`;
				}
				break;

			case 'filedb':
				if (connectionParams.path) {
					code += `define( 'DB_FILEDB_PATH', '${connectionParams.path}' );`;
				}
				if (connectionParams.format) {
					code += `\ndefine( 'DB_FILEDB_FORMAT', '${connectionParams.format}' );`;
				}
				break;

			case 'd1':
				if (connectionParams.account_id) {
					code += `define( 'DB_D1_ACCOUNT_ID', '${connectionParams.account_id}' );`;
				}
				if (connectionParams.database_id) {
					code += `\ndefine( 'DB_D1_DATABASE_ID', '${connectionParams.database_id}' );`;
				}
				if (connectionParams.api_token) {
					code += `\ndefine( 'DB_D1_API_TOKEN', '${connectionParams.api_token}' );`;
				}
				break;

			case 'pgsql':
			case 'postgresql':
				code += `// PostgreSQL connection options\n`;
				code += `define( 'DB_DBAL_OPTIONS', [\n`;
				code += `    'driver' => 'pdo_pgsql',\n`;
				if (connectionParams.host) {
					code += `    'host' => '${connectionParams.host}',\n`;
				}
				if (connectionParams.port) {
					code += `    'port' => ${connectionParams.port},\n`;
				}
				if (connectionParams.dbname) {
					code += `    'dbname' => '${connectionParams.dbname}',\n`;
				}
				if (connectionParams.user) {
					code += `    'user' => '${connectionParams.user}',\n`;
				}
				if (connectionParams.password) {
					code += `    'password' => '${connectionParams.password}',\n`;
				}
				code += `] );`;
				break;

			case 'mysql':
			default:
				// MySQL uses default DB_* constants, no extra config needed.
				break;
		}

		return code;
	};

	return (
		<Card style={{ marginTop: '20px' }}>
			<CardHeader>
				<h2>{__('Configuration', 'wp-dbal')}</h2>
			</CardHeader>
			<CardBody>
				<p>
					{__(
						'Add the following to your wp-config.php file to use the new database:',
						'wp-dbal'
					)}
				</p>
				<pre
					style={{
						background: '#f0f0f0',
						padding: '15px',
						overflowX: 'auto',
						marginTop: '10px',
						whiteSpace: 'pre-wrap',
					}}
				>
					{generateConfigCode()}
				</pre>
				<p style={{ marginTop: '15px' }}>
					{__(
						'After adding these lines, reload this page to use the new database.',
						'wp-dbal'
					)}
				</p>
			</CardBody>
		</Card>
	);
}

