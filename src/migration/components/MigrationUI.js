/**
 * Migration UI Component
 *
 * Main container component for database migration.
 *
 * @package WP_DBAL
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import DatabaseSelector from './DatabaseSelector';
import ConnectionValidator from './ConnectionValidator';
import MigrationProgress from './MigrationProgress';
import MigrationControls from './MigrationControls';
import ConfigUpdatePrompt from './ConfigUpdatePrompt';

// Set up API fetch nonce middleware if available.
if (typeof window !== 'undefined' && window.wpDbalAdmin?.restNonce) {
	apiFetch.use(apiFetch.createNonceMiddleware(window.wpDbalAdmin.restNonce));
}

/**
 * Migration UI component.
 *
 * @param {Object} props Component props.
 * @param {string} props.currentEngine Current database engine (optional, will fetch if not provided).
 * @param {Function} props.onConfigChoice Callback when user chooses manual config (choice, params).
 * @return {JSX.Element} Migration UI.
 */
export default function MigrationUI({ currentEngine: propCurrentEngine, onConfigChoice }) {
	const [currentEngine, setCurrentEngine] = useState(propCurrentEngine || null);
	const [targetEngine, setTargetEngine] = useState('');
	const [connectionParams, setConnectionParams] = useState({});
	const [isValidating, setIsValidating] = useState(false);
	const [validationResult, setValidationResult] = useState(null);
	const [sessionId, setSessionId] = useState(null);
	const [progress, setProgress] = useState(null);
	const [isMigrating, setIsMigrating] = useState(false);
	const [error, setError] = useState(null);
	const [configUpdateChoice, setConfigUpdateChoice] = useState(null); // 'auto', 'manual', or null
	const [migrationParams, setMigrationParams] = useState(null); // Store target engine and connection params

	// Get current engine on mount if not provided as prop.
	useEffect(() => {
		if (!propCurrentEngine) {
			fetchCurrentEngine();
		}
	}, [propCurrentEngine]);

	// Pre-populate connection params from wp-config.php when target engine changes.
	useEffect(() => {
		if (!targetEngine) {
			// Clear connection params if no target engine selected.
			setConnectionParams({});
			return;
		}

		// Fetch connection params for the selected target engine.
		const fetchConnectionParams = async () => {
			try {
				const response = await apiFetch({
					path: `/wp-dbal/v1/admin/configuration?db_engine=${targetEngine}`,
				});

				if (response.success && response.data?.connection_params) {
					// Pre-populate with values from wp-config.php.
					// Replace with fetched values to show defaults for the selected engine.
					setConnectionParams(response.data.connection_params);
				}
			} catch (err) {
				// Silently fail - user can still enter values manually.
				console.error('Failed to fetch connection params:', err);
			}
		};

		fetchConnectionParams();
	}, [targetEngine]);

	// Poll for progress if migration is running.
	useEffect(() => {
		if (!sessionId || !isMigrating) {
			return;
		}

		const interval = setInterval(() => {
			fetchProgress();
		}, 1000); // Poll every second.

		return () => clearInterval(interval);
	}, [sessionId, isMigrating]);

	/**
	 * Fetch current database engine.
	 */
	const fetchCurrentEngine = async () => {
		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/migration/status',
			});

			if (response.success) {
				setCurrentEngine(response.current_engine);
			}
		} catch (err) {
			console.error('Failed to fetch current engine:', err);
		}
	};

	/**
	 * Validate target connection.
	 */
	const handleValidate = async () => {
		if (!targetEngine) {
			setError(__('Please select a target database engine', 'wp-dbal'));
			return;
		}

		setIsValidating(true);
		setError(null);
		setValidationResult(null);

		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/migration/validate',
				method: 'POST',
				data: {
					target_engine: targetEngine,
					connection_params: connectionParams,
				},
			});

			if (response.success) {
				setValidationResult({
					success: true,
					message: response.message,
				});
			} else {
				setValidationResult({
					success: false,
					message: response.message || __('Validation failed', 'wp-dbal'),
				});
			}
		} catch (err) {
			setValidationResult({
				success: false,
				message: err.message || __('Validation failed', 'wp-dbal'),
			});
		} finally {
			setIsValidating(false);
		}
	};

	/**
	 * Start migration.
	 */
	const handleStartMigration = async () => {
		if (!targetEngine) {
			setError(__('Please select a target database engine', 'wp-dbal'));
			return;
		}

		if (!validationResult || !validationResult.success) {
			setError(__('Please validate the connection first', 'wp-dbal'));
			return;
		}

		setError(null);
		setIsMigrating(true);
		setConfigUpdateChoice(null); // Reset choice when starting new migration

		// Store migration params for later use.
		setMigrationParams({
			targetEngine,
			connectionParams,
		});

		try {
			const response = await apiFetch({
				path: '/wp-dbal/v1/migration/start',
				method: 'POST',
				data: {
					target_engine: targetEngine,
					connection_params: connectionParams,
				},
			});

			if (response.success) {
				setSessionId(response.session_id);
				// Start processing chunks.
				processChunks(response.session_id);
			} else {
				setError(response.message || __('Failed to start migration', 'wp-dbal'));
				setIsMigrating(false);
			}
		} catch (err) {
			setError(err.message || __('Failed to start migration', 'wp-dbal'));
			setIsMigrating(false);
		}
	};

	/**
	 * Process migration chunks.
	 *
	 * @param {string} sessionId Session ID.
	 */
	const processChunks = async (sessionId) => {
		let complete = false;
		let iterations = 0;
		const MAX_ITERATIONS = 10000; // Safety limit
		const DELAY_BETWEEN_CHUNKS = 100; // 100ms delay between chunks

		while (!complete && iterations < MAX_ITERATIONS) {
			iterations++;

			try {
				const response = await apiFetch({
					path: '/wp-dbal/v1/migration/chunk',
					method: 'POST',
					data: {
						session_id: sessionId,
					},
				});

				if (response.success) {
					setProgress(response.progress);
					complete = response.complete;

					if (complete) {
						setIsMigrating(false);
						if (response.progress.status === 'failed') {
							setError(response.progress.error || __('Migration failed', 'wp-dbal'));
						}
						break;
					}

					// Wait before next chunk to avoid overwhelming the server.
					await new Promise((resolve) => setTimeout(resolve, DELAY_BETWEEN_CHUNKS));
				} else {
					setError(response.message || __('Chunk processing failed', 'wp-dbal'));
					setIsMigrating(false);
					complete = true;
					break;
				}
			} catch (err) {
				setError(err.message || __('Chunk processing failed', 'wp-dbal'));
				setIsMigrating(false);
				complete = true;
				break;
			}
		}

		if (iterations >= MAX_ITERATIONS) {
			setError(__('Migration timeout - maximum iterations exceeded', 'wp-dbal'));
			setIsMigrating(false);
		}
	};

	/**
	 * Fetch migration progress.
	 */
	const fetchProgress = async () => {
		if (!sessionId) {
			return;
		}

		try {
			const response = await apiFetch({
				path: `/wp-dbal/v1/migration/progress?session_id=${sessionId}`,
			});

			if (response.success) {
				setProgress(response.progress);
				
				if (response.progress.status === 'completed' || response.progress.status === 'failed') {
					setIsMigrating(false);
				}
			}
		} catch (err) {
			console.error('Failed to fetch progress:', err);
		}
	};

	/**
	 * Cancel migration.
	 */
	const handleCancel = async () => {
		if (!sessionId) {
			return;
		}

		try {
			await apiFetch({
				path: '/wp-dbal/v1/migration/cancel',
				method: 'POST',
				data: {
					session_id: sessionId,
				},
			});

			setIsMigrating(false);
			setSessionId(null);
			setProgress(null);
		} catch (err) {
			console.error('Failed to cancel migration:', err);
		}
	};

	return (
		<div className="wp-dbal-migration-ui">
			{currentEngine && (
				<p>
					{__('Current database engine:', 'wp-dbal')} <strong>{currentEngine}</strong>
				</p>
			)}

			{error && (
				<div className="notice notice-error">
					<p>{error}</p>
				</div>
			)}

			{!isMigrating && !progress && (
				<>
					<DatabaseSelector
						currentEngine={currentEngine}
						targetEngine={targetEngine}
						onTargetEngineChange={setTargetEngine}
						connectionParams={connectionParams}
						onConnectionParamsChange={setConnectionParams}
					/>

					<ConnectionValidator
						isValidating={isValidating}
						validationResult={validationResult}
						onValidate={handleValidate}
					/>
				</>
			)}

			{progress && (
				<MigrationProgress progress={progress} />
			)}

			{progress?.status === 'completed' && !configUpdateChoice && migrationParams && (
				<ConfigUpdatePrompt
					targetEngine={migrationParams.targetEngine}
					connectionParams={migrationParams.connectionParams}
					onComplete={(choice) => {
						setConfigUpdateChoice(choice);
						if (choice === 'manual' && onConfigChoice) {
							onConfigChoice(choice, migrationParams);
						}
					}}
				/>
			)}

			{progress?.status !== 'completed' && (
				<MigrationControls
					isMigrating={isMigrating}
					canStart={!!targetEngine && validationResult?.success}
					onStart={handleStartMigration}
					onCancel={handleCancel}
					progress={progress}
				/>
			)}
		</div>
	);
}

