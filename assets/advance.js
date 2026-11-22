(() => {
	const form = document.getElementById('wp-panel-backup-advance');
	if (!form || !window.fetch) {
		return;
	}

	const panel = document.getElementById('wp-panel-backup-progress');
	const maintenance = document.getElementById('wp-panel-backup-maintenance');
	const field = (name) => panel.querySelector('[data-progress="' + name + '"]');
	const resume = form.querySelector('button');
	const cancel = panel.querySelector('input[value="cancel"]').form;
	let timer;
	let pending = false;
	let queuedAction;
	let leaving = false;

	function update(name, value) {
		const element = field(name);
		if (element.textContent !== value) {
			element.textContent = value;
		}
		element.hidden = !value;
	}

	function pause(message) {
		update('hint', '备份已暂停，可以从已保存的进度继续。');
		update('error', message);
		resume.textContent = '继续备份';
		form.hidden = false;
	}

	async function advance() {
		if (pending || leaving) {
			return;
		}
		pending = true;
		try {
			const body = new FormData(form);
			body.set('action', 'wp_panel_backup_advance');
			const response = await fetch(form.dataset.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				body
			});
			const result = await response.json();
			if (leaving || queuedAction) {
				return;
			}
			if (!response.ok || !result.success) {
				pause(result.data?.message || '无法继续备份。请重新打开备份页面后重试。');
				return;
			}

			const progress = result.data;
			if (maintenance) {
				maintenance.hidden = !progress.maintenance;
			}
			panel.dataset.phase = progress.phase;
			update('label', progress.label);
			update('detail', progress.detail);
			update('packages', progress.packages);
			update('error', progress.error);
			if (progress.running) {
				timer = window.setTimeout(advance, 300);
			} else if (progress.listUrl) {
				window.location.replace(progress.listUrl);
			} else {
				update('hint', '');
				cancel.hidden = true;
				field('return').hidden = false;
			}
		} catch {
			if (!leaving && !queuedAction) {
				pause('未收到备份请求的有效响应。请检查网络或重新登录，然后继续备份。');
			}
		} finally {
			pending = false;
			if (queuedAction && !leaving) {
				queuedAction.requestSubmit();
			}
		}
	}

	function run() {
		form.hidden = true;
		update('error', '');
		update('hint', '备份正在进行。请保持此页打开，关闭后可返回继续。');
		timer = window.setTimeout(advance, 300);
	}

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		run();
	});
	function afterBatch(event) {
		window.clearTimeout(timer);
		if (pending) {
			event.preventDefault();
			if (!queuedAction) {
				queuedAction = event.currentTarget;
				queuedAction.querySelector('[type="submit"]').disabled = true;
				update('hint', '当前批次结束后将执行此操作。');
			}
		}
	}
	cancel.addEventListener('submit', afterBatch);
	if (maintenance) {
		maintenance.querySelector('form').addEventListener('submit', afterBatch);
	}
	window.addEventListener('pagehide', () => {
		leaving = true;
		window.clearTimeout(timer);
	});
	window.addEventListener('pageshow', (event) => {
		if (event.persisted) {
			window.location.reload();
		}
	});

	if (form.dataset.running === '1') {
		const url = new URL(window.location.href);
		url.searchParams.delete('run');
		window.history.replaceState(window.history.state, '', url);
		run();
	}
})();
