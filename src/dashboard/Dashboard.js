import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { RefreshCw } from 'lucide-react';
import StatCard from './components/StatCard';
import StatusList from './components/StatusList';
import SecurityDonut from './components/SecurityDonut';
import LatencyChart from './components/LatencyChart';
import AuditMarquee from './components/AuditMarquee';

const STATUS_COLORS = {
	green: 'eapi-text-emerald-600',
	yellow: 'eapi-text-amber-500',
	red: 'eapi-text-rose-500',
};

const STATUS_BG = {
	green: 'eapi-bg-emerald-50 eapi-ring-emerald-200',
	yellow: 'eapi-bg-amber-50 eapi-ring-amber-200',
	red: 'eapi-bg-rose-50 eapi-ring-rose-200',
};

function globalStatus( data ) {
	const allMetrics = Object.values( data ).flatMap( ( cat ) =>
		Object.values( cat ).map( ( r ) => r.metrics?.status )
	);
	if ( allMetrics.includes( 'red' ) ) return 'red';
	if ( allMetrics.includes( 'yellow' ) ) return 'yellow';
	return 'green';
}

const GLOBAL_LABELS = {
	green: 'All Systems Nominal',
	yellow: 'Warnings Detected',
	red: 'Issues Detected',
};

export default function Dashboard() {
	const [ data, setData ] = useState( null );
	const [ history, setHistory ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ refreshing, setRefreshing ] = useState( false );

	const fetchData = async ( refresh = false ) => {
		try {
			const params = refresh ? '?refresh=1' : '';
			const [ dashRes, histRes ] = await Promise.all( [
				apiFetch( { path: '/enterprise-api-importer/v1/dashboard' + params } ),
				apiFetch( { path: '/enterprise-api-importer/v1/dashboard/history' } ),
			] );
			setData( dashRes );
			setHistory( histRes );
		} catch {
			// Silently handle — data stays null, empty state shown.
		} finally {
			setLoading( false );
			setRefreshing( false );
		}
	};

	useEffect( () => {
		fetchData();
	}, [] );

	const handleRefresh = () => {
		setRefreshing( true );
		fetchData( true );
	};

	if ( loading ) {
		return <SkeletonDashboard />;
	}

	if ( ! data ) {
		return (
			<div className="eapi-p-8 eapi-text-center eapi-text-slate-500">
				Unable to load dashboard data. Please try again.
			</div>
		);
	}

	const status = globalStatus( data );
	const health = data.Health || {};
	const security = data.Security || {};
	const performance = data.Performance || {};

	const kpis = [
		{
			id: 'success_rate',
			label: 'Success Rate',
			icon: 'activity',
			value: health.daily_success_rate?.metrics?.value || 'N/A',
			status: health.daily_success_rate?.metrics?.status || 'green',
			sparkData: history?.daily_rates?.map( ( d ) => d.rate ) || [],
		},
		{
			id: 'queue',
			label: 'Queue Depth',
			icon: 'layers',
			value: health.queue_depth?.metrics?.value || '0',
			status: health.queue_depth?.metrics?.status || 'green',
			sparkData: history?.throughput_points?.map( ( d ) => d.rows ) || [],
		},
		{
			id: 'latency',
			label: 'Avg Latency',
			icon: 'timer',
			value: performance.api_latency?.metrics?.value || 'N/A',
			status: performance.api_latency?.metrics?.status || 'green',
			sparkData: history?.latency_points?.map( ( d ) => d.seconds ) || [],
		},
		{
			id: 'connections',
			label: 'Active Endpoints',
			icon: 'network',
			value: performance.active_connections?.metrics?.value || '0',
			status: performance.active_connections?.metrics?.status || 'green',
			sparkData: [],
		},
	];

	return (
		<div className="eapi-p-6 eapi-max-w-7xl eapi-mx-auto">
			{ /* Header */ }
			<div className="eapi-flex eapi-items-center eapi-justify-between eapi-mb-8">
				<div className="eapi-flex eapi-items-center eapi-gap-4">
					<h1 className="eapi-text-2xl eapi-font-bold eapi-text-slate-900 eapi-m-0">
						Operations Command
					</h1>
					<span
						className={ `eapi-inline-flex eapi-items-center eapi-gap-1.5 eapi-px-3 eapi-py-1 eapi-text-sm eapi-font-medium eapi-rounded-full eapi-ring-1 ${ STATUS_BG[ status ] } ${ STATUS_COLORS[ status ] }` }
					>
						<span
							className={ `eapi-w-2 eapi-h-2 eapi-rounded-full ${ status === 'green' ? 'eapi-bg-emerald-500' : status === 'yellow' ? 'eapi-bg-amber-500' : 'eapi-bg-rose-500' }` }
						/>
						{ GLOBAL_LABELS[ status ] }
					</span>
				</div>
				<button
					onClick={ handleRefresh }
					disabled={ refreshing }
					className="eapi-inline-flex eapi-items-center eapi-gap-2 eapi-px-4 eapi-py-2 eapi-text-sm eapi-font-medium eapi-text-slate-700 eapi-bg-white eapi-rounded-lg eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 hover:eapi-bg-slate-50 eapi-transition-all eapi-duration-300 disabled:eapi-opacity-50 eapi-cursor-pointer disabled:eapi-cursor-not-allowed"
				>
					<RefreshCw
						size={ 16 }
						className={ refreshing ? 'eapi-animate-spin' : '' }
					/>
					Refresh Data
				</button>
			</div>

			{ /* KPI Row */ }
			<div className="eapi-grid eapi-grid-cols-1 sm:eapi-grid-cols-2 lg:eapi-grid-cols-4 eapi-gap-4 eapi-mb-6">
				{ kpis.map( ( kpi ) => (
					<StatCard
						key={ kpi.id }
						label={ kpi.label }
						value={ kpi.value }
						icon={ kpi.icon }
						status={ kpi.status }
						sparkData={ kpi.sparkData }
					/>
				) ) }
			</div>

			{ /* Middle Grid */ }
			<div className="eapi-grid eapi-grid-cols-1 lg:eapi-grid-cols-3 eapi-gap-4 eapi-mb-6">
				{ /* Health Column */ }
				<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-p-5">
					<h2 className="eapi-text-sm eapi-font-semibold eapi-text-slate-500 eapi-uppercase eapi-tracking-wider eapi-mb-4 eapi-m-0">
						Environment Health
					</h2>
					<StatusList items={ health } />
				</div>

				{ /* Security Column */ }
				<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-p-5">
					<h2 className="eapi-text-sm eapi-font-semibold eapi-text-slate-500 eapi-uppercase eapi-tracking-wider eapi-mb-4 eapi-m-0">
						Security Posture
					</h2>
					<SecurityDonut items={ security } />
				</div>

				{ /* Performance Column */ }
				<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-p-5">
					<h2 className="eapi-text-sm eapi-font-semibold eapi-text-slate-500 eapi-uppercase eapi-tracking-wider eapi-mb-4 eapi-m-0">
						API Latency
					</h2>
					<LatencyChart dataPoints={ history?.latency_points || [] } />
				</div>
			</div>

			{ /* Footer Marquee */ }
			<AuditMarquee entries={ history?.audit_entries || [] } />
		</div>
	);
}

function SkeletonDashboard() {
	return (
		<div className="eapi-p-6 eapi-max-w-7xl eapi-mx-auto">
			<div className="eapi-flex eapi-items-center eapi-justify-between eapi-mb-8">
				<div className="eapi-skeleton eapi-h-8 eapi-w-64" />
				<div className="eapi-skeleton eapi-h-10 eapi-w-36 eapi-rounded-lg" />
			</div>
			<div className="eapi-grid eapi-grid-cols-1 sm:eapi-grid-cols-2 lg:eapi-grid-cols-4 eapi-gap-4 eapi-mb-6">
				{ [ 1, 2, 3, 4 ].map( ( i ) => (
					<div
						key={ i }
						className="eapi-skeleton eapi-h-32 eapi-rounded-xl"
					/>
				) ) }
			</div>
			<div className="eapi-grid eapi-grid-cols-1 lg:eapi-grid-cols-3 eapi-gap-4 eapi-mb-6">
				{ [ 1, 2, 3 ].map( ( i ) => (
					<div
						key={ i }
						className="eapi-skeleton eapi-h-64 eapi-rounded-xl"
					/>
				) ) }
			</div>
			<div className="eapi-skeleton eapi-h-12 eapi-rounded-xl" />
		</div>
	);
}
