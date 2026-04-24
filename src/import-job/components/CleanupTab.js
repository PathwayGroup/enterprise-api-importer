import { useState, useCallback } from '@wordpress/element';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function CleanupTab( { onCleanup } ) {
	const [ confirmation, setConfirmation ] = useState( '' );
	const [ pendingMode, setPendingMode ] = useState( '' );

	const handleCleanup = useCallback( async ( mode ) => {
		setPendingMode( mode );

		try {
			await onCleanup( mode, confirmation );
			setConfirmation( '' );
		} finally {
			setPendingMode( '' );
		}
	}, [ confirmation, onCleanup ] );

	const isBusy = '' !== pendingMode;
	const canSubmit = 'DELETE' === confirmation && ! isBusy;

	return (
		<div className="eapi-ij-tab-content">
			<Panel>
				<PanelBody
					title={ __( 'Fresh Start Cleanup', 'tporret-api-data-importer' ) }
					initialOpen={ true }
				>
					<Notice status="warning" isDismissible={ false }>
						{ __( 'This will remove only content created by this import job, plus its staging rows and logs. Type DELETE to enable the cleanup actions.', 'tporret-api-data-importer' ) }
					</Notice>

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Confirmation', 'tporret-api-data-importer' ) }
						value={ confirmation }
						onChange={ setConfirmation }
						help={ __( 'Type DELETE exactly to confirm. "Move all to Trash" will trash imported posts and their owned featured images. "Permanently Delete all" removes them permanently.', 'tporret-api-data-importer' ) }
					/>

					<div className="eapi-ij-cleanup-actions">
						<Button
							variant="secondary"
							disabled={ ! canSubmit }
							isBusy={ 'trash' === pendingMode }
							onClick={ () => handleCleanup( 'trash' ) }
						>
							{ __( 'Move All To Trash', 'tporret-api-data-importer' ) }
						</Button>

						<Button
							variant="primary"
							disabled={ ! canSubmit }
							isBusy={ 'delete' === pendingMode }
							onClick={ () => handleCleanup( 'delete' ) }
						>
							{ __( 'Permanently Delete All', 'tporret-api-data-importer' ) }
						</Button>
					</div>
				</PanelBody>
			</Panel>
		</div>
	);
}
