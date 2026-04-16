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
	{ name: 'source-auth', title: __( 'Source & Auth', 'enterprise-api-importer' ) },
	{ name: 'data-rules', title: __( 'Data Rules', 'enterprise-api-importer' ) },
	{ name: 'mapping', title: __( 'Mapping & Templating', 'enterprise-api-importer' ) },
	{ name: 'automation', title: __( 'Automation', 'enterprise-api-importer' ) },
];

export default function ImportJobWorkspace() {
	const config = window.enterpriseApiImporterImportJob || {};
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
		apiFetch( { path: `/enterprise-api-importer/v1/import-jobs/${ importId }` } )
			.then( ( data ) => {
				setJob( ( prev ) => ( { ...prev, ...data } ) );
			} )
			.catch( () => {
				setNotice( {
					status: 'error',
					message: __( 'Failed to load import job.', 'enterprise-api-importer' ),
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
			? `/enterprise-api-importer/v1/import-jobs/${ importId }`
			: '/enterprise-api-importer/v1/import-jobs';

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
					? __( 'Import job updated.', 'enterprise-api-importer' )
					: __( 'Import job created.', 'enterprise-api-importer' ),
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
				message: err.message || __( 'Save failed.', 'enterprise-api-importer' ),
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
				path: `/enterprise-api-importer/v1/import-jobs/${ importId }/run`,
				method: 'POST',
			} );
			setNotice( {
				status: 'success',
				message: __( 'Import started.', 'enterprise-api-importer' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Run failed.', 'enterprise-api-importer' ),
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
				path: `/enterprise-api-importer/v1/import-jobs/${ importId }/template-sync`,
				method: 'POST',
			} );
			setNotice( {
				status: 'success',
				message: __( 'Template sync started.', 'enterprise-api-importer' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Template sync failed.', 'enterprise-api-importer' ),
			} );
		}
	}, [ isEdit, importId ] );

	if ( loading ) {
		return (
			<div className="eapi-ij-loading">
				<span className="spinner is-active" />
				{ __( 'Loading import job…', 'enterprise-api-importer' ) }
			</div>
		);
	}

	return (
		<div className="eapi-ij-workspace">
			<h1 className="eapi-ij-title">
				{ isEdit
					? __( 'Edit Import Job', 'enterprise-api-importer' )
					: __( 'Create Import Job', 'enterprise-api-importer' ) }
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
