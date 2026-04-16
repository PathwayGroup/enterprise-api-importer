import { useState, useCallback } from '@wordpress/element';
import {
	TextControl,
	SelectControl,
	Button,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const OPERATOR_OPTIONS = [
	{ label: __( 'Equals', 'enterprise-api-importer' ), value: 'equals' },
	{ label: __( 'Not Equals', 'enterprise-api-importer' ), value: 'not_equals' },
	{ label: __( 'Contains', 'enterprise-api-importer' ), value: 'contains' },
	{ label: __( 'Not Contains', 'enterprise-api-importer' ), value: 'not_contains' },
	{ label: __( 'Is Empty', 'enterprise-api-importer' ), value: 'is_empty' },
	{ label: __( 'Not Empty', 'enterprise-api-importer' ), value: 'not_empty' },
	{ label: __( 'Greater Than', 'enterprise-api-importer' ), value: 'greater_than' },
	{ label: __( 'Less Than', 'enterprise-api-importer' ), value: 'less_than' },
];

function parseFilterRules( raw ) {
	try {
		const parsed = JSON.parse( raw );
		return Array.isArray( parsed ) && parsed.length > 0
			? parsed
			: [ { key: '', operator: 'equals', value: '' } ];
	} catch {
		return [ { key: '', operator: 'equals', value: '' } ];
	}
}

export default function DataRulesTab( {
	job,
	updateField,
	previewData,
	setPreviewData,
	setNotice,
} ) {
	const [ previewing, setPreviewing ] = useState( false );
	const rules = parseFilterRules( job.filter_rules );

	const setRules = useCallback(
		( newRules ) => {
			updateField( 'filter_rules', JSON.stringify( newRules ) );
		},
		[ updateField ]
	);

	const updateRule = useCallback(
		( index, field, value ) => {
			const updated = [ ...rules ];
			updated[ index ] = { ...updated[ index ], [ field ]: value };
			setRules( updated );
		},
		[ rules, setRules ]
	);

	const addRule = useCallback( () => {
		setRules( [ ...rules, { key: '', operator: 'equals', value: '' } ] );
	}, [ rules, setRules ] );

	const removeRule = useCallback(
		( index ) => {
			if ( rules.length <= 1 ) {
				setRules( [ { key: '', operator: 'equals', value: '' } ] );
				return;
			}
			setRules( rules.filter( ( _, i ) => i !== index ) );
		},
		[ rules, setRules ]
	);

	const handlePreviewRecord = useCallback( async () => {
		setPreviewing( true );
		try {
			const result = await apiFetch( {
				path: '/enterprise-api-importer/v1/test-api-connection',
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
			setPreviewData( result.sample_data || null );
			setNotice( {
				status: 'success',
				message: __( 'Preview data loaded.', 'enterprise-api-importer' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Preview failed.', 'enterprise-api-importer' ),
			} );
		} finally {
			setPreviewing( false );
		}
	}, [ job, setPreviewData, setNotice ] );

	return (
		<div className="eapi-ij-tab-content">
			<div className="eapi-ij-field-with-action">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'JSON Array Path', 'enterprise-api-importer' ) }
					value={ job.array_path }
					onChange={ ( val ) => updateField( 'array_path', val ) }
					help={ __( 'Example: data.employees. Leave empty if the API root is already an array.', 'enterprise-api-importer' ) }
				/>
				<Button
					isSmall
					variant="secondary"
					isBusy={ previewing }
					disabled={ previewing || ! job.endpoint_url }
					onClick={ handlePreviewRecord }
					className="eapi-ij-preview-btn"
				>
					{ previewing
						? __( 'Loading…', 'enterprise-api-importer' )
						: __( 'Preview First Record', 'enterprise-api-importer' ) }
				</Button>
			</div>

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Unique ID Path', 'enterprise-api-importer' ) }
				value={ job.unique_id_path }
				onChange={ ( val ) => updateField( 'unique_id_path', val ) }
				help={ __( 'Dot-path to the source unique identifier (example: CourseIDFull or data.course.id). Defaults to id when empty.', 'enterprise-api-importer' ) }
			/>

			<div className="eapi-ij-filters">
				<h3>{ __( 'Data Filters', 'enterprise-api-importer' ) }</h3>
				<p className="description">
					{ __( 'Only records matching every filter are staged for import (AND logic).', 'enterprise-api-importer' ) }
				</p>

				<table className="widefat striped eapi-ij-filter-table">
					<thead>
						<tr>
							<th>{ __( 'Key', 'enterprise-api-importer' ) }</th>
							<th>{ __( 'Operator', 'enterprise-api-importer' ) }</th>
							<th>{ __( 'Value', 'enterprise-api-importer' ) }</th>
							<th>{ __( 'Actions', 'enterprise-api-importer' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rules.map( ( rule, index ) => (
							<tr key={ index }>
								<td>
									<input
										type="text"
										className="regular-text"
										value={ rule.key }
										placeholder="department"
										onChange={ ( e ) =>
											updateRule( index, 'key', e.target.value )
										}
									/>
								</td>
								<td>
									<select
										value={ rule.operator }
										onChange={ ( e ) =>
											updateRule( index, 'operator', e.target.value )
										}
									>
										{ OPERATOR_OPTIONS.map( ( opt ) => (
											<option key={ opt.value } value={ opt.value }>
												{ opt.label }
											</option>
										) ) }
									</select>
								</td>
								<td>
									<input
										type="text"
										className="regular-text"
										value={ rule.value }
										placeholder="Engineering"
										onChange={ ( e ) =>
											updateRule( index, 'value', e.target.value )
										}
									/>
								</td>
								<td>
									<Button
										isSmall
										isDestructive
										variant="secondary"
										onClick={ () => removeRule( index ) }
									>
										{ __( 'Remove', 'enterprise-api-importer' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>

				<Button
					isSmall
					variant="secondary"
					onClick={ addRule }
					className="eapi-ij-add-filter"
				>
					{ __( 'Add Filter', 'enterprise-api-importer' ) }
				</Button>
			</div>
		</div>
	);
}
