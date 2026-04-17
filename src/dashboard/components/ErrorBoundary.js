import { Component } from '@wordpress/element';

export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	componentDidCatch( error ) {
		if ( window?.console?.error ) {
			window.console.error( error );
		}
	}

	render() {
		if ( this.state.hasError ) {
			return this.props.fallback || null;
		}

		return this.props.children;
	}
}