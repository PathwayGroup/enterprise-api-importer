import { useState, useEffect, useCallback } from '@wordpress/element';
import { TabPanel, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import SourceAuthTab from './components/SourceAuthTab';
import DataRulesTab from './components/DataRulesTab';
import MappingTemplatingTab from './components/MappingTemplatingTab';
import AutomationTab from './components/AutomationTab';
import StickyFooter from './components/StickyFooter';

const DEFAULT_JOB = {
	name: '',
	endpoint_url: '',
	auth_method: 'none',
	auth_token: '',
	auth_header_name: '',
	auth_username: '',
	auth_password: '',
	array_path: '',
	unique_id_path: 'id',
	recurrence: 'off',
	custom_interval_minutes: 30,
	filter_rules: '[]',
	target_post_type: 'post',
	featured_image_source_path: 'image.url',
	lock_editing: 1,
	post_author: 0,
	title_template: '',
	mapping_template: '',
	post_status: 'draft',
	comment_status: 'closed',
	ping_status: 'closed',
	custom_meta_mappings: '[]',
};

const TABS = [
	{ name: 'source-auth', title: __( 'Source & Auth', 'tporret-api-data-importer' ) },
	{ name: 'data-rules', title: __( 'Data Rules', 'tporret-api-data-importer' ) },
	{ name: 'mapping', title: __( 'Mapping & Templating', 'tporret-api-data-importer' ) },
	{ name: 'automation', title: __( 'Automation', 'tporret-api-data-importer' ) },
];

export default function ImportJobWorkspace() {
	const config = window.tporapdiImportJob || {};
	const importId = config.importId || 0;
	const postTypes = config.postTypes || [];
	const authors = config.authors || [];
	const isEdit = importId > 0;

	const [ job, setJob ] = useState( { ...DEFAULT_JOB } );
	const [ loading, setLoading ] = useState( isEdit );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ previewData, setPreviewData ] = useState( null );

	useEffect( () => {
		if ( ! isEdit ) {
			return;
		}
		apiFetch( { path: `/tporret-api-data-importer/v1/import-jobs/${ importId }` } )
			.then( ( data ) => {
				setJob( ( prev ) => ( { ...prev, ...data } ) );
			} )
			.catch( () => {
				setNotice( {
					status: 'error',
					message: __( 'Failed to load import job.', 'tporret-api-data-importer' ),
				} );
			} )
			.finally( () => setLoading( false ) );
	}, [ isEdit, importId ] );

	const updateField = useCallback( ( field, value ) => {
		setJob( ( prev ) => ( { ...prev, [ field ]: value } ) );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );

		const method = isEdit ? 'PUT' : 'POST';
		const path = isEdit
			? `/tporret-api-data-importer/v1/import-jobs/${ importId }`
			: '/tporret-api-data-importer/v1/import-jobs';

		try {
			const result = await apiFetch( {
				path,
				method,
				data: job,
			} );

			const newId = result.id || importId;

			setNotice( {
				status: 'success',
				message: isEdit
					? __( 'Import job updated.', 'tporret-api-data-importer' )
					: __( 'Import job created.', 'tporret-api-data-importer' ),
			} );

			if ( ! isEdit && newId ) {
				const newUrl = new URL( window.location.href );
				newUrl.searchParams.set( 'action', 'edit' );
				newUrl.searchParams.set( 'id', newId );
				window.history.replaceState( null, '', newUrl.toString() );
				config.importId = newId;
			}
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Save failed.', 'tporret-api-data-importer' ),
			} );
		} finally {
			setSaving( false );
		}
	}, [ isEdit, importId, job, config ] );

	const handleRunImport = useCallback( async () => {
		if ( ! isEdit ) {
			return;
		}
		setNotice( null );
		try {
			await apiFetch( {
				path: `/tporret-api-data-importer/v1/import-jobs/${ importId }/run`,
				method: 'POST',
			} );
			setNotice( {
				status: 'success',
				message: __( 'Import started.', 'tporret-api-data-importer' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Run failed.', 'tporret-api-data-importer' ),
			} );
		}
	}, [ isEdit, importId ] );

	const handleTemplateSync = useCallback( async () => {
		if ( ! isEdit ) {
			return;
		}
		setNotice( null );
		try {
			await apiFetch( {
				path: `/tporret-api-data-importer/v1/import-jobs/${ importId }/template-sync`,
				method: 'POST',
			} );
			setNotice( {
				status: 'success',
				message: __( 'Template sync started.', 'tporret-api-data-importer' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Template sync failed.', 'tporret-api-data-importer' ),
			} );
		}
	}, [ isEdit, importId ] );

	if ( loading ) {
		return (
			<div className="eapi-ij-loading">
				<span className="spinner is-active" />
				{ __( 'Loading import job…', 'tporret-api-data-importer' ) }
			</div>
		);
	}

	return (
		<div className="eapi-ij-workspace">
			<h1 className="eapi-ij-title">
				{ isEdit
					? __( 'Edit Import Job', 'tporret-api-data-importer' )
					: __( 'Create Import Job', 'tporret-api-data-importer' ) }
			</h1>

			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<TabPanel
				className="eapi-ij-tabs"
				tabs={ TABS }
			>
				{ ( tab ) => {
					switch ( tab.name ) {
						case 'source-auth':
							return (
								<SourceAuthTab
									job={ job }
									updateField={ updateField }
									setNotice={ setNotice }
									setPreviewData={ setPreviewData }
								/>
							);
						case 'data-rules':
							return (
								<DataRulesTab
									job={ job }
									updateField={ updateField }
									previewData={ previewData }
									setPreviewData={ setPreviewData }
									setNotice={ setNotice }
								/>
							);
						case 'mapping':
							return (
								<MappingTemplatingTab
									job={ job }
									updateField={ updateField }
									previewData={ previewData }
									postTypes={ postTypes }
									authors={ authors }
									setNotice={ setNotice }
								/>
							);
						case 'automation':
							return (
								<AutomationTab
									job={ job }
									updateField={ updateField }
								/>
							);
						default:
							return null;
					}
				} }
			</TabPanel>

			<StickyFooter
				isEdit={ isEdit }
				saving={ saving }
				onSave={ handleSave }
				onRunImport={ handleRunImport }
				onTemplateSync={ handleTemplateSync }
			/>
		</div>
	);
}
