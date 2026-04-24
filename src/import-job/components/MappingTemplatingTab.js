import { useState, useCallback, useMemo, useRef, useEffect } from '@wordpress/element';
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
	isEdit,
	previewData,
	postTypes,
	authors,
	postTypeDefaults,
	defaultsLoading,
	onApplyRecommendedDefaults,
	setNotice,
} ) {
	const [ dryRunning, setDryRunning ] = useState( false );
	const [ dryRunResult, setDryRunResult ] = useState( null );
	const [ templateScrollTop, setTemplateScrollTop ] = useState( 0 );
	const templateEditorRef = useRef( null );

	const customMetaMappings = useMemo( () => {
		try {
			const parsed = typeof job.custom_meta_mappings === 'string'
				? JSON.parse( job.custom_meta_mappings )
				: job.custom_meta_mappings;
			return Array.isArray( parsed ) ? parsed : [];
		} catch {
			return [];
		}
	}, [ job.custom_meta_mappings ] );

	const setCustomMetaMappings = useCallback( ( mappings ) => {
		updateField( 'custom_meta_mappings', JSON.stringify( mappings ) );
	}, [ updateField ] );

	const handleAddMapping = useCallback( () => {
		setCustomMetaMappings( [ ...customMetaMappings, { key: '', value: '' } ] );
	}, [ customMetaMappings, setCustomMetaMappings ] );

	const handleRemoveMapping = useCallback( ( index ) => {
		setCustomMetaMappings( customMetaMappings.filter( ( _, i ) => i !== index ) );
	}, [ customMetaMappings, setCustomMetaMappings ] );

	const handleUpdateMapping = useCallback( ( index, field, value ) => {
		const updated = customMetaMappings.map( ( mapping, i ) =>
			i === index ? { ...mapping, [ field ]: value } : mapping
		);
		setCustomMetaMappings( updated );
	}, [ customMetaMappings, setCustomMetaMappings ] );

	const postTypeOptions = ( postTypes || [] ).map( ( pt ) => ( {
		label: pt.label,
		value: pt.value,
	} ) );

	const authorOptions = [
		{ label: __( '— Default (current user) —', 'tporret-api-data-importer' ), value: '0' },
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
				path: '/tporret-api-data-importer/v1/dry-run',
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
				message: err.message || __( 'Dry run failed.', 'tporret-api-data-importer' ),
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

	const hasRecommendedDefaults = !! postTypeDefaults && 'object' === typeof postTypeDefaults;

	const supportsHierarchy = hasRecommendedDefaults && Object.prototype.hasOwnProperty.call( postTypeDefaults, 'post_parent' );
	const supportsComments = ! hasRecommendedDefaults || false !== postTypeDefaults.supports_comments;
	const supportsTrackbacks = ! hasRecommendedDefaults || false !== postTypeDefaults.supports_trackbacks;

	useEffect( () => {
		if ( ! supportsComments && 'open' === ( job.comment_status || 'closed' ) ) {
			updateField( 'comment_status', 'closed', { markTouched: false } );
		}
	}, [ supportsComments, job.comment_status, updateField ] );

	useEffect( () => {
		if ( ! supportsTrackbacks && 'open' === ( job.ping_status || 'closed' ) ) {
			updateField( 'ping_status', 'closed', { markTouched: false } );
		}
	}, [ supportsTrackbacks, job.ping_status, updateField ] );

	return (
		<div className="eapi-ij-tab-content">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Target Post Type', 'tporret-api-data-importer' ) }
				value={ job.target_post_type }
				options={ postTypeOptions }
				onChange={ ( val ) => updateField( 'target_post_type', val ) }
				help={ __( 'Select which public WordPress post type receives imported records.', 'tporret-api-data-importer' ) }
			/>

			<Panel className="eapi-ij-defaults-panel">
				<PanelBody
					title={ __( 'Recommended Defaults For Selected Post Type', 'tporret-api-data-importer' ) }
					initialOpen={ true }
				>
					{ defaultsLoading && (
						<p className="eapi-ij-defaults-muted">
							{ __( 'Loading recommended defaults…', 'tporret-api-data-importer' ) }
						</p>
					) }

					{ ! defaultsLoading && ! hasRecommendedDefaults && (
						<p className="eapi-ij-defaults-muted">
							{ __( 'Recommended defaults are unavailable for this post type.', 'tporret-api-data-importer' ) }
						</p>
					) }

					{ ! defaultsLoading && hasRecommendedDefaults && (
						<>
							<Flex className="eapi-ij-defaults-grid" wrap gap={ 3 }>
								<FlexBlock>
									<p className="eapi-ij-defaults-label">{ __( 'Post Status', 'tporret-api-data-importer' ) }</p>
									<p className="eapi-ij-defaults-value">{ String( postTypeDefaults.post_status || 'draft' ) }</p>
								</FlexBlock>
								<FlexBlock>
									<p className="eapi-ij-defaults-label">{ __( 'Comment Status', 'tporret-api-data-importer' ) }</p>
									<p className="eapi-ij-defaults-value">{ String( postTypeDefaults.comment_status || 'closed' ) }</p>
								</FlexBlock>
								<FlexBlock>
									<p className="eapi-ij-defaults-label">{ __( 'Ping Status', 'tporret-api-data-importer' ) }</p>
									<p className="eapi-ij-defaults-value">{ String( postTypeDefaults.ping_status || 'closed' ) }</p>
								</FlexBlock>
								<FlexBlock>
									<p className="eapi-ij-defaults-label">{ __( 'Author ID', 'tporret-api-data-importer' ) }</p>
									<p className="eapi-ij-defaults-value">{ String( postTypeDefaults.post_author || 0 ) }</p>
								</FlexBlock>
							</Flex>

							<Notice status="info" isDismissible={ false } className="eapi-ij-defaults-notice">
								{ supportsHierarchy
									? __( 'This post type is hierarchical, so parent-aware destination behavior can be enabled in upcoming mapping phases.', 'tporret-api-data-importer' )
									: __( 'This post type is non-hierarchical, so parent-specific destination fields are not applicable.', 'tporret-api-data-importer' ) }
							</Notice>

							<Button
								variant="secondary"
								onClick={ onApplyRecommendedDefaults }
							>
								{ isEdit
									? __( 'Apply Recommended Defaults To Untouched Fields', 'tporret-api-data-importer' )
									: __( 'Re-Apply Recommended Defaults', 'tporret-api-data-importer' ) }
							</Button>
						</>
					) }
				</PanelBody>
			</Panel>

			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Lock editing of imported posts', 'tporret-api-data-importer' ) }
				checked={ !! job.lock_editing }
				onChange={ ( val ) => updateField( 'lock_editing', val ? 1 : 0 ) }
				help={ __( 'When enabled, posts created by this import cannot be edited or deleted in wp-admin. Disable to allow manual editing.', 'tporret-api-data-importer' ) }
			/>

			<Flex className="eapi-ij-post-settings" gap={ 4 } wrap>
				<FlexBlock>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Default Post Status', 'tporret-api-data-importer' ) }
						value={ job.post_status || 'draft' }
						options={ [
							{ label: __( 'Draft', 'tporret-api-data-importer' ), value: 'draft' },
							{ label: __( 'Published', 'tporret-api-data-importer' ), value: 'publish' },
							{ label: __( 'Pending Review', 'tporret-api-data-importer' ), value: 'pending' },
						] }
						onChange={ ( val ) => updateField( 'post_status', val ) }
						help={ __( 'Status assigned to newly imported posts.', 'tporret-api-data-importer' ) }
					/>
				</FlexBlock>
				<FlexBlock>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Author', 'tporret-api-data-importer' ) }
						value={ String( job.post_author || 0 ) }
						options={ authorOptions }
						onChange={ ( val ) => updateField( 'post_author', parseInt( val, 10 ) ) }
						help={ __( 'Assign imported posts to this author. If unset, the user triggering the import will be used.', 'tporret-api-data-importer' ) }
					/>
				</FlexBlock>
				<FlexBlock>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Comment Status', 'tporret-api-data-importer' ) }
						value={ job.comment_status || 'closed' }
						options={ [
							{ label: __( 'Open', 'tporret-api-data-importer' ), value: 'open' },
							{ label: __( 'Closed', 'tporret-api-data-importer' ), value: 'closed' },
						] }
						disabled={ ! supportsComments }
						onChange={ ( val ) => updateField( 'comment_status', val ) }
						help={ supportsComments
							? __( 'Whether comments are open on imported posts.', 'tporret-api-data-importer' )
							: __( 'This post type does not support comments. Comment status is forced to Closed.', 'tporret-api-data-importer' ) }
					/>
				</FlexBlock>
				<FlexBlock>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Pingback/Trackback Status', 'tporret-api-data-importer' ) }
						value={ job.ping_status || 'closed' }
						options={ [
							{ label: __( 'Open', 'tporret-api-data-importer' ), value: 'open' },
							{ label: __( 'Closed', 'tporret-api-data-importer' ), value: 'closed' },
						] }
						disabled={ ! supportsTrackbacks }
						onChange={ ( val ) => updateField( 'ping_status', val ) }
						help={ supportsTrackbacks
							? __( 'Whether pingbacks and trackbacks are accepted on imported posts.', 'tporret-api-data-importer' )
							: __( 'This post type does not support trackbacks. Pingback/trackback status is forced to Closed.', 'tporret-api-data-importer' ) }
					/>
				</FlexBlock>
			</Flex>

			<Flex className="eapi-ij-split" align="stretch">
				<FlexBlock className="eapi-ij-split-editor">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Post Title Template', 'tporret-api-data-importer' ) }
						value={ job.title_template }
						onChange={ ( val ) => updateField( 'title_template', val ) }
						help={ __( 'Supports Twig syntax (for example: {{ data.first_name }} {{ data.last_name }}). If left blank, defaults to Imported Item {ID}.', 'tporret-api-data-importer' ) }
					/>

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Post Excerpt Template', 'tporret-api-data-importer' ) }
						value={ job.excerpt_template || '' }
						onChange={ ( val ) => updateField( 'excerpt_template', val ) }
						help={ __( 'Twig template for the post excerpt (for example: {{ data.summary }}). If left blank, no excerpt is set.', 'tporret-api-data-importer' ) }
					/>

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Post Slug Template', 'tporret-api-data-importer' ) }
						value={ job.post_name_template || '' }
						onChange={ ( val ) => updateField( 'post_name_template', val ) }
						help={ __( 'Twig template for the URL slug (for example: {{ data.slug }}). Output is sanitized via sanitize_title(). If left blank, WordPress auto-generates the slug from the post title.', 'tporret-api-data-importer' ) }
					/>

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Featured Image Source Path', 'tporret-api-data-importer' ) }
						value={ job.featured_image_source_path || '' }
						onChange={ ( val ) => updateField( 'featured_image_source_path', val ) }
						help={ __( 'Dot-notation path used to set post featured image (for example: image.url). Leave blank to use image.url.', 'tporret-api-data-importer' ) }
					/>

					<BaseControl
						__nextHasNoMarginBottom
						label={ __( 'Mapping Template', 'tporret-api-data-importer' ) }
						help={ __( 'Twig template for the post body. Use data.field_name to reference API fields.', 'tporret-api-data-importer' ) }
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
							? __( 'Running…', 'tporret-api-data-importer' )
							: __( 'Test Template (Dry Run)', 'tporret-api-data-importer' ) }
					</Button>
				</FlexBlock>

				<FlexItem className="eapi-ij-split-dictionary">
					<Panel>
						<PanelBody
							title={ __( 'Data Dictionary', 'tporret-api-data-importer' ) }
							initialOpen={ true }
						>
							{ sampleJson ? (
								<pre className="eapi-ij-json-preview">
									<code>{ sampleJson }</code>
								</pre>
							) : (
								<p className="eapi-ij-empty-state">
									{ __( 'Use "Test Endpoint" on the Source & Auth tab or "Preview First Record" on the Data Rules tab to load sample data here.', 'tporret-api-data-importer' ) }
								</p>
							) }
						</PanelBody>
					</Panel>
				</FlexItem>
			</Flex>

			<Panel className="eapi-ij-custom-meta-panel">
				<PanelBody
					title={ __( 'Custom Fields (Post Meta)', 'tporret-api-data-importer' ) }
					initialOpen={ customMetaMappings.length > 0 }
				>
					{ customMetaMappings.map( ( mapping, index ) => (
						<Flex key={ index } gap={ 3 } align="flex-end" className="eapi-ij-custom-meta-row">
							<FlexBlock>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ index === 0 ? __( 'Meta Key', 'tporret-api-data-importer' ) : undefined }
									placeholder="_price"
									value={ mapping.key }
									onChange={ ( val ) => handleUpdateMapping( index, 'key', val ) }
								/>
							</FlexBlock>
							<FlexBlock>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									label={ index === 0 ? __( 'Meta Value (Twig enabled)', 'tporret-api-data-importer' ) : undefined }
									placeholder="{{ data.price }}"
									value={ mapping.value }
									onChange={ ( val ) => handleUpdateMapping( index, 'value', val ) }
								/>
							</FlexBlock>
							<FlexItem>
								<Button
									isDestructive
									icon="trash"
									label={ __( 'Remove', 'tporret-api-data-importer' ) }
									onClick={ () => handleRemoveMapping( index ) }
								/>
							</FlexItem>
						</Flex>
					) ) }
					<Button
						variant="secondary"
						onClick={ handleAddMapping }
						style={ { marginTop: '8px' } }
					>
						{ __( '+ Add Custom Field', 'tporret-api-data-importer' ) }
					</Button>
				</PanelBody>
			</Panel>

			{ dryRunResult && (
				<div className="eapi-ij-dry-run-result">
					<h3>{ __( 'Dry Run Result', 'tporret-api-data-importer' ) }</h3>

					<Panel>
						<PanelBody
							title={ __( 'Rendered Title', 'tporret-api-data-importer' ) }
							initialOpen={ true }
						>
							<p>{ dryRunResult.renderedTitle }</p>
						</PanelBody>
						<PanelBody
							title={ __( 'Rendered Body', 'tporret-api-data-importer' ) }
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
							title={ __( 'Raw Data', 'tporret-api-data-importer' ) }
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
