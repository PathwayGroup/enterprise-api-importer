import { createRoot } from '@wordpress/element';
import ImportJobWorkspace from './ImportJobWorkspace';
import './style.css';

const rootEl = document.getElementById( 'enterprise-api-importer-import-job-root' );
if ( rootEl ) {
	const root = createRoot( rootEl );
	root.render( <ImportJobWorkspace /> );
}
