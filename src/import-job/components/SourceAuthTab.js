import { useState, useCallback } from '@wordpress/element';
import { TextControl, SelectControl, Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import AuthSettings from './AuthSettings';

export default function SourceAuthTab( { job, updateField, setNotice, setPreviewData } ) {
	const [ testing, setTesting ] = useState( false );
	const [ testResult, setTestResult ] = useState( null );

	const handleTestEndpoint = useCallback( async () => {
		setTesting( true );
		setTestResult( null );

		try {
			const result = await apiFetch( {
				path: '/eapi/v1/test-api-connection',
				method: 'POST',
				data: {
					api_url: job.endpoint_url,
					array_path: job.array_path,
					auth_method: job.auth_method,
					auth_token: job.auth_token,
					auth_header_name: job.auth_header_name,
					auth_username: job.auth_username,
					auth_password: job.auth_password,
				},
			} );

			setTestResult( {
				status: 'success',
				message: result.message || __( 'Connection successful!', 'enterprise-api-importer' ),
				itemCount: result.item_count,
				keys: result.available_keys,
			} );

			setPreviewData( result.sample_data || null );
		} catch ( err ) {
			setTestResult( {
				status: 'error',
				message: err.message || __( 'Connection test failed.', 'enterprise-api-importer' ),
			} );
		} finally {
			setTesting( false );
		}
	}, [ job, setPreviewData ] );

	return (
		<div className="eapi-ij-tab-content">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Job Name', 'enterprise-api-importer' ) }
				value={ job.name }
				onChange={ ( val ) => updateField( 'name', val ) }
				required
			/>

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Endpoint URL', 'enterprise-api-importer' ) }
				type="url"
				value={ job.endpoint_url }
				onChange={ ( val ) => updateField( 'endpoint_url', val ) }
				required
			/>

			<AuthSettings job={ job } updateField={ updateField } />

			<div className="eapi-ij-test-section">
				<Button
					variant="secondary"
					isBusy={ testing }
					disabled={ testing || ! job.endpoint_url }
					onClick={ handleTestEndpoint }
				>
					{ testing
						? __( 'Testing…', 'enterprise-api-importer' )
						: __( 'Test Endpoint', 'enterprise-api-importer' ) }
				</Button>

				{ testResult && (
					<Notice
						status={ testResult.status }
						isDismissible
						onDismiss={ () => setTestResult( null ) }
						className="eapi-ij-inline-notice"
					>
						<p><strong>{ testResult.message }</strong></p>
						{ testResult.status === 'success' && (
							<>
								<p>
									{ __( 'Items Found:', 'enterprise-api-importer' ) }{ ' ' }
									<strong>{ testResult.itemCount }</strong>
								</p>
								{ testResult.keys && testResult.keys.length > 0 && (
									<p>
										{ __( 'Available Fields:', 'enterprise-api-importer' ) }{ ' ' }
										<code>
											{ testResult.keys.map( ( k ) => `data.${ k }` ).join( ', ' ) }
										</code>
									</p>
								) }
							</>
						) }
					</Notice>
				) }
			</div>
		</div>
	);
}
