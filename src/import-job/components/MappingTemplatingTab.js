import { useState, useCallback, useMemo, useRef } from '@wordpress/element';
import {
	SelectControl,
	TextControl,
	BaseControl,
	Button,
	Notice,
	Flex,
	FlexItem,
	FlexBlock,
	Panel,
	PanelBody,
	CheckboxControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export default function MappingTemplatingTab( {
	job,
	updateField,
	previewData,
	postTypes,
	authors,
	setNotice,
} ) {
	const [ dryRunning, setDryRunning ] = useState( false );
	const [ dryRunResult, setDryRunResult ] = useState( null );
	const [ templateScrollTop, setTemplateScrollTop ] = useState( 0 );
	const templateEditorRef = useRef( null );

	const postTypeOptions = ( postTypes || [] ).map( ( pt ) => ( {
		label: pt.label,
		value: pt.value,
	} ) );

	const authorOptions = [
		{ label: __( '— Default (current user) —', 'enterprise-api-importer' ), value: '0' },
		...( authors || [] ).map( ( a ) => ( {
			label: a.label,
			value: String( a.value ),
		} ) ),
	];

	const handleDryRun = useCallback( async () => {
		setDryRunning( true );
		setDryRunResult( null );

		try {
			const result = await apiFetch( {
				path: '/eapi/v1/dry-run',
				method: 'POST',
				data: {
					api_url: job.endpoint_url,
					title_template: job.title_template,
					body_template: job.mapping_template,
					auth_method: job.auth_method,
					auth_token: job.auth_token,
					auth_header_name: job.auth_header_name,
					auth_username: job.auth_username,
					auth_password: job.auth_password,
					data_filters: {
						array_path: job.array_path,
						rules: (() => {
							try {
								return JSON.parse( job.filter_rules );
							} catch {
								return [];
							}
						})(),
					},
				},
			} );

			setDryRunResult( {
				rawData: result.raw_data,
				renderedTitle: result.rendered_title,
				renderedBody: result.rendered_body,
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message || __( 'Dry run failed.', 'enterprise-api-importer' ),
			} );
		} finally {
			setDryRunning( false );
		}
	}, [ job, setNotice ] );

	const sampleJson = previewData
		? JSON.stringify( previewData, null, 2 )
		: null;

	const mappingTemplateLineNumbers = useMemo( () => {
		const templateValue = ( job.mapping_template || '' ).replace( /\r\n/g, '\n' );
		const lineCount = Math.max( 1, templateValue.split( '\n' ).length );

		return Array.from( { length: lineCount }, ( value, index ) => index + 1 );
	}, [ job.mapping_template ] );

	const handleTemplateScroll = useCallback( ( event ) => {
		setTemplateScrollTop( event.target.scrollTop );
	}, [] );

	return (
		<div className="eapi-ij-tab-content">
			<SelectControl
				label={ __( 'Target Post Type', 'enterprise-api-importer' ) }
				value={ job.target_post_type }
				options={ postTypeOptions }
				onChange={ ( val ) => updateField( 'target_post_type', val ) }
				help={ __( 'Select which public WordPress post type receives imported records.', 'enterprise-api-importer' ) }
			/>

			<SelectControl
				label={ __( 'Author', 'enterprise-api-importer' ) }
				value={ String( job.post_author || 0 ) }
				options={ authorOptions }
				onChange={ ( val ) => updateField( 'post_author', parseInt( val, 10 ) ) }
				help={ __( 'Assign imported posts to this author. If unset, the user triggering the import will be used (which may be empty for scheduled runs).', 'enterprise-api-importer' ) }
			/>

			<CheckboxControl
				label={ __( 'Lock editing of imported posts', 'enterprise-api-importer' ) }
				checked={ !! job.lock_editing }
				onChange={ ( val ) => updateField( 'lock_editing', val ? 1 : 0 ) }
				help={ __( 'When enabled, posts created by this import cannot be edited or deleted in wp-admin. Disable to allow manual editing.', 'enterprise-api-importer' ) }
			/>

			<Flex className="eapi-ij-split" align="stretch">
				<FlexBlock className="eapi-ij-split-editor">
					<TextControl
						label={ __( 'Post Title Template', 'enterprise-api-importer' ) }
						value={ job.title_template }
						onChange={ ( val ) => updateField( 'title_template', val ) }
						help={ __( 'Supports Twig syntax (for example: {{ data.first_name }} {{ data.last_name }}). If left blank, defaults to Imported Item {ID}.', 'enterprise-api-importer' ) }
					/>

					<TextControl
						label={ __( 'Featured Image Source Path', 'enterprise-api-importer' ) }
						value={ job.featured_image_source_path || '' }
						onChange={ ( val ) => updateField( 'featured_image_source_path', val ) }
						help={ __( 'Dot-notation path used to set post featured image (for example: image.url). Leave blank to use image.url.', 'enterprise-api-importer' ) }
					/>

					<BaseControl
						label={ __( 'Mapping Template', 'enterprise-api-importer' ) }
						help={ __( 'Twig template for the post body. Use data.field_name to reference API fields.', 'enterprise-api-importer' ) }
					>
						<div className="eapi-ij-template-editor">
							<div
								className="eapi-ij-template-editor__gutter"
								aria-hidden="true"
							>
								<div
									className="eapi-ij-template-editor__gutter-inner"
									style={ { transform: `translateY(-${ templateScrollTop }px)` } }
								>
									{ mappingTemplateLineNumbers.map( ( lineNumber ) => (
										<div
											key={ lineNumber }
											className="eapi-ij-template-editor__gutter-line"
										>
											{ lineNumber }
										</div>
									) ) }
								</div>
							</div>

							<textarea
								ref={ templateEditorRef }
								rows={ 16 }
								className="eapi-ij-template-editor__textarea"
								value={ job.mapping_template }
								onChange={ ( event ) => updateField( 'mapping_template', event.target.value ) }
								onScroll={ handleTemplateScroll }
								spellCheck={ false }
							/>
						</div>
					</BaseControl>

					<Button
						variant="primary"
						isBusy={ dryRunning }
						disabled={ dryRunning || ! job.endpoint_url }
						onClick={ handleDryRun }
					>
						{ dryRunning
							? __( 'Running…', 'enterprise-api-importer' )
							: __( 'Test Template (Dry Run)', 'enterprise-api-importer' ) }
					</Button>
				</FlexBlock>

				<FlexItem className="eapi-ij-split-dictionary">
					<Panel>
						<PanelBody
							title={ __( 'Data Dictionary', 'enterprise-api-importer' ) }
							initialOpen={ true }
						>
							{ sampleJson ? (
								<pre className="eapi-ij-json-preview">
									<code>{ sampleJson }</code>
								</pre>
							) : (
								<p className="eapi-ij-empty-state">
									{ __( 'Use "Test Endpoint" on the Source & Auth tab or "Preview First Record" on the Data Rules tab to load sample data here.', 'enterprise-api-importer' ) }
								</p>
							) }
						</PanelBody>
					</Panel>
				</FlexItem>
			</Flex>

			{ dryRunResult && (
				<div className="eapi-ij-dry-run-result">
					<h3>{ __( 'Dry Run Result', 'enterprise-api-importer' ) }</h3>

					<Panel>
						<PanelBody
							title={ __( 'Rendered Title', 'enterprise-api-importer' ) }
							initialOpen={ true }
						>
							<p>{ dryRunResult.renderedTitle }</p>
						</PanelBody>
						<PanelBody
							title={ __( 'Rendered Body', 'enterprise-api-importer' ) }
							initialOpen={ true }
						>
							<div
								className="eapi-ij-rendered-body"
								dangerouslySetInnerHTML={ {
									__html: dryRunResult.renderedBody,
								} }
							/>
						</PanelBody>
						<PanelBody
							title={ __( 'Raw Data', 'enterprise-api-importer' ) }
							initialOpen={ false }
						>
							<pre className="eapi-ij-json-preview">
								<code>
									{ JSON.stringify(
										dryRunResult.rawData,
										null,
										2
									) }
								</code>
							</pre>
						</PanelBody>
					</Panel>
				</div>
			) }
		</div>
	);
}
