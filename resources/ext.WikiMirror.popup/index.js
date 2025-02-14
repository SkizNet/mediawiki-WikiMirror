import createMirrorPreviewGateway from './gateway';

const selector = '#mw-content-text a[href][title].mirror-link';

module.exports = mw.popups.isEnabled() ? {
	type: 'mirror',
	selector,
	gateway: createMirrorPreviewGateway()
} : null;
