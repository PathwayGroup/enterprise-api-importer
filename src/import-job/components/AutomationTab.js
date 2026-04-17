import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const RECURRENCE_OPTIONS = [
	{ label: __( 'Off', 'tporret-api-data-importer' ), value: 'off' },
	{ label: __( 'Hourly', 'tporret-api-data-importer' ), value: 'hourly' },
	{ label: __( 'Twice Daily', 'tporret-api-data-importer' ), value: 'twicedaily' },
	{ label: __( 'Daily', 'tporret-api-data-importer' ), value: 'daily' },
	{ label: __( 'Custom', 'tporret-api-data-importer' ), value: 'custom' },
];

export default function AutomationTab( { job, updateField } ) {
	return (
		<div className="eapi-ij-tab-content">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Recurrence', 'tporret-api-data-importer' ) }
				value={ job.recurrence }
				options={ RECURRENCE_OPTIONS }
				onChange={ ( val ) => updateField( 'recurrence', val ) }
				help={ __( 'When set to Custom, the import runs every N minutes. Use Off to disable recurring automation.', 'tporret-api-data-importer' ) }
			/>

			{ job.recurrence === 'custom' && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Custom Interval (minutes)', 'tporret-api-data-importer' ) }
					type="number"
					min={ 1 }
					step={ 1 }
					value={ String( job.custom_interval_minutes ) }
					onChange={ ( val ) =>
						updateField(
							'custom_interval_minutes',
							Math.max( 1, parseInt( val, 10 ) || 30 )
						)
					}
				/>
			) }
		</div>
	);
}
