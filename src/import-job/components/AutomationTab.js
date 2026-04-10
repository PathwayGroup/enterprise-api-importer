import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const RECURRENCE_OPTIONS = [
	{ label: __( 'Off', 'enterprise-api-importer' ), value: 'off' },
	{ label: __( 'Hourly', 'enterprise-api-importer' ), value: 'hourly' },
	{ label: __( 'Twice Daily', 'enterprise-api-importer' ), value: 'twicedaily' },
	{ label: __( 'Daily', 'enterprise-api-importer' ), value: 'daily' },
	{ label: __( 'Custom', 'enterprise-api-importer' ), value: 'custom' },
];

export default function AutomationTab( { job, updateField } ) {
	return (
		<div className="eapi-ij-tab-content">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Recurrence', 'enterprise-api-importer' ) }
				value={ job.recurrence }
				options={ RECURRENCE_OPTIONS }
				onChange={ ( val ) => updateField( 'recurrence', val ) }
				help={ __( 'When set to Custom, the import runs every N minutes. Use Off to disable recurring automation.', 'enterprise-api-importer' ) }
			/>

			{ job.recurrence === 'custom' && (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Custom Interval (minutes)', 'enterprise-api-importer' ) }
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
