import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const AUTH_OPTIONS = [
	{ label: __( 'None', 'enterprise-api-importer' ), value: 'none' },
	{ label: __( 'Bearer Token', 'enterprise-api-importer' ), value: 'bearer' },
	{ label: __( 'API Key (Custom Header)', 'enterprise-api-importer' ), value: 'api_key_custom' },
	{ label: __( 'Basic Auth', 'enterprise-api-importer' ), value: 'basic_auth' },
];

export default function AuthSettings( { job, updateField } ) {
	return (
		<div className="eapi-ij-auth-settings">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Authentication Method', 'enterprise-api-importer' ) }
				value={ job.auth_method }
				options={ AUTH_OPTIONS }
				onChange={ ( val ) => updateField( 'auth_method', val ) }
				help={ __( 'Select the authentication method required by your API endpoint.', 'enterprise-api-importer' ) }
			/>

			{ job.auth_method === 'bearer' && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Bearer Token', 'enterprise-api-importer' ) }
					type="password"
					value={ job.auth_token }
					onChange={ ( val ) => updateField( 'auth_token', val ) }
					help={
						job.has_auth_token && ! job.auth_token
							? __( 'Credential saved. Leave blank to keep existing value.', 'enterprise-api-importer' )
							: __( 'OAuth or API bearer token sent as Authorization: Bearer <token>.', 'enterprise-api-importer' )
					}
					placeholder={
						job.has_auth_token && ! job.auth_token
							? __( '••••••••', 'enterprise-api-importer' )
							: undefined
					}
					autoComplete="new-password"
				/>
			) }

			{ job.auth_method === 'api_key_custom' && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Header Name', 'enterprise-api-importer' ) }
						value={ job.auth_header_name }
						onChange={ ( val ) => updateField( 'auth_header_name', val ) }
						placeholder="Authorization-Key"
						help={ __( 'Custom HTTP header name, e.g. Authorization-Key, X-API-Key.', 'enterprise-api-importer' ) }
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'API Key', 'enterprise-api-importer' ) }
						type="password"
						value={ job.auth_token }
						onChange={ ( val ) => updateField( 'auth_token', val ) }
						help={
							job.has_auth_token && ! job.auth_token
								? __( 'Credential saved. Leave blank to keep existing value.', 'enterprise-api-importer' )
								: __( 'The API key value sent in the custom header above.', 'enterprise-api-importer' )
						}
						placeholder={
							job.has_auth_token && ! job.auth_token
								? __( '••••••••', 'enterprise-api-importer' )
								: undefined
						}
						autoComplete="new-password"
					/>
				</>
			) }

			{ job.auth_method === 'basic_auth' && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Username', 'enterprise-api-importer' ) }
						value={ job.auth_username }
						onChange={ ( val ) => updateField( 'auth_username', val ) }
						autoComplete="off"
					/>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Password', 'enterprise-api-importer' ) }
						type="password"
						value={ job.auth_password }
						onChange={ ( val ) => updateField( 'auth_password', val ) }
						help={
							job.has_auth_password && ! job.auth_password
								? __( 'Credential saved. Leave blank to keep existing value.', 'enterprise-api-importer' )
								: undefined
						}
						placeholder={
							job.has_auth_password && ! job.auth_password
								? __( '••••••••', 'enterprise-api-importer' )
								: undefined
						}
						autoComplete="new-password"
					/>
				</>
			) }
		</div>
	);
}
