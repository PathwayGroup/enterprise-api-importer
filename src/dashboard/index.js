// Polyfill for libraries that expect global object (Recharts, etc.)
if ( typeof global === 'undefined' ) {
	window.global = window;
}

import { createRoot } from '@wordpress/element';
import Dashboard from './Dashboard';
import './style.css';

const rootEl = document.getElementById( 'enterprise-api-importer-dashboard-root' );
if ( rootEl ) {
	const root = createRoot( rootEl );
	root.render( <Dashboard /> );
}
