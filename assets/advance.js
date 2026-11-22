const form = document.getElementById('wp-panel-backup-advance');

if (form && form.dataset.running === '1') {
	window.setTimeout(() => form.requestSubmit(), 300);
}
