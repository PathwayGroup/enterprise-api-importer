// Polyfill for libraries that expect global object (Recharts, etc.)
if ( typeof global === 'undefined' ) {
	window.global = window;
}

import { createRoot } from '@wordpress/element';
import Dashboard from './Dashboard';
import ErrorBoundary from './components/ErrorBoundary';
import './style.css';

const rootEl = document.getElementById( 'tporret-api-data-importer-dashboard-root' );
if ( rootEl ) {
	const root = createRoot( rootEl );
	root.render(
		<ErrorBoundary
			fallback={
				<div className="eapi-p-6 eapi-max-w-4xl eapi-mx-auto">
					<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-p-5">
						<h1 className="eapi-text-lg eapi-font-semibold eapi-text-slate-900 eapi-mt-0 eapi-mb-2">
							Dashboard failed to load
						</h1>
						<p className="eapi-text-sm eapi-text-slate-500 eapi-m-0">
							A JavaScript error prevented the dashboard from rendering. Open the browser console to inspect the failing asset.
						</p>
					</div>
				</div>
			}
		>
			<Dashboard />
		</ErrorBoundary>
	);
}
