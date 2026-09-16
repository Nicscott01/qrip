(function () {
	'use strict';

	function selectedType() {
		var selected = document.querySelector('input[name="destination_type"]:checked');
		return selected ? selected.value : 'url';
	}

	function updateDestinationPanels() {
		var type = selectedType();
		document.querySelectorAll('.qrip-destination-panel').forEach(function (panel) {
			panel.hidden = panel.dataset.type !== type;
		});
		var url = document.getElementById('qrip-destination');
		var attachment = document.getElementById('qrip-attachment-id');
		if (url) url.required = type === 'url';
		if (attachment) attachment.required = type === 'media';
		var utm = document.getElementById('qrip-utm');
		if (utm) utm.hidden = type !== 'url';
	}

	function setFile(attachment) {
		var id = document.getElementById('qrip-attachment-id');
		var card = document.getElementById('qrip-file-details');
		if (!id || !card) return;
		id.value = attachment.id || '';
		card.classList.toggle('is-empty', !attachment.id);
		card.querySelector('.qrip-file-name').textContent = attachment.filename || qripAdmin.unknownFile;
		card.querySelector('.qrip-file-meta').textContent = [attachment.subtype, attachment.filesizeHumanReadable, attachment.dateFormatted].filter(Boolean).join(' · ');
		var view = card.querySelector('.qrip-view-file');
		view.href = attachment.url || '';
		view.hidden = !attachment.url;
		document.getElementById('qrip-select-file').textContent = attachment.id ? 'Replace File' : 'Select File';
		document.getElementById('qrip-remove-file').hidden = !attachment.id;
	}

	document.addEventListener('change', function (event) {
		if (event.target.name === 'destination_type') updateDestinationPanels();
	});
	document.addEventListener('click', function (event) {
		if (event.target.classList.contains('qrip-copy')) {
			navigator.clipboard && navigator.clipboard.writeText(event.target.dataset.url);
			event.target.textContent = 'Copied';
		}
		if (event.target.id === 'qrip-remove-file') setFile({});
		if (event.target.id === 'qrip-select-file' && window.wp && wp.media) {
			var frame = wp.media({ title: qripAdmin.frameTitle, button: { text: qripAdmin.frameButton }, multiple: false });
			frame.on('select', function () { setFile(frame.state().get('selection').first().toJSON()); });
			frame.open();
		}
	});
	var name = document.getElementById('qrip-name');
	var slug = document.getElementById('qrip-slug');
	if (name && slug) {
		name.addEventListener('input', function () {
			if (!slug.dataset.edited) slug.value = this.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		});
		slug.addEventListener('input', function () { this.dataset.edited = '1'; });
	}
	updateDestinationPanels();
}());
