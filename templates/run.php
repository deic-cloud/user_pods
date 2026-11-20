<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('user_pods', 'main');
?>
<?php $guest = !empty($_['guest']); ?>
<div id="app-content">
	<div class="viewcontainer" style="max-width:640px;margin:48px auto;padding:0 16px;">
	<div id="run-error" <?php if ($_['error'] === ''): ?>hidden<?php endif; ?>>
		<h2><?php p($l->t('The notebook could not be started')); ?></h2>
		<p id="run-error-text" style="color:var(--color-error-text)"><?php p($_['error']); ?></p>
		<?php if ($guest): ?>
		<p><a class="button" href="<?php p($_['login_url']); ?>"><?php p($l->t('Log in and run it on your own account')); ?></a></p>
		<?php else: ?>
		<p><a class="button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('user_pods.page.index')); ?>"><?php p($l->t('Open the Containers app')); ?></a></p>
		<?php endif; ?>
	</div>
	<?php if ($_['error'] === ''): ?>
	<div id="run-wait">
		<?php if ($guest): ?>
		<h2><?php p($l->t('Starting a notebook server for you…')); ?></h2>
		<p><?php p($l->t('This is a temporary server: it is deleted after %s minutes and nothing you do in it is saved. Starting usually takes about a minute.', [(string)$_['lifetime']])); ?></p>
		<p><?php print_unescaped($l->t('To keep your work, %1$slog in%2$s and the notebook is copied to your own files instead.', ['<a href="' . \OCP\Util::sanitizeHTML($_['login_url']) . '">', '</a>'])); ?></p>
		<?php else: ?>
		<h2><?php p($l->t('Starting your notebook server…')); ?></h2>
		<p id="run-file" hidden></p>
		<?php endif; ?>
		<div class="icon-loading-dark" style="height:40px;"></div>
		<p id="run-status" style="color:var(--color-text-maxcontrast)"><?php p($l->t('Preparing the notebook…')); ?></p>
		<p id="run-manual" hidden><?php p($l->t('Taking longer than expected.')); ?>
			<?php if ($guest): ?><?php p($l->t('Please try again in a few minutes.')); ?>
			<?php else: ?><a href="<?php p($_['pods_url']); ?>"><?php p($l->t('See your containers')); ?></a><?php endif; ?></p>
	</div>
	<script nonce="<?php p(\OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce()); ?>">
	(function () {
		// Not OC.requestToken: the global OC is not defined on this page in NC34.
		var guest = <?php print_unescaped(json_encode($guest)); ?>;
		var startUrl = <?php print_unescaped(json_encode($_['start_url'])); ?>;
		var params = <?php print_unescaped(json_encode($_['params'], JSON_FORCE_OBJECT)); ?>;
		var statusUrl = <?php print_unescaped(json_encode($_['status_url'] ?? '')); ?>;
		var containersUrl = <?php print_unescaped(json_encode($_['containers_url'] ?? '')); ?>;
		var T = {
			starting: <?php print_unescaped(json_encode($l->t('starting…'))); ?>,
			upNoAddress: <?php print_unescaped(json_encode($l->t('server is up, waiting for its address…'))); ?>,
			gone: <?php print_unescaped(json_encode($l->t('The server is no longer available.'))); ?>,
			waitingFor: <?php print_unescaped(json_encode($l->t('Waiting for %s…', ['%s']))); ?>,
			copy: <?php print_unescaped(json_encode($l->t('Your copy of the notebook is in your files as %s. A server for it is starting; this usually takes about a minute, longer the first time an image is used on a machine.', ['%s']))); ?>,
			failed: <?php print_unescaped(json_encode($l->t('The request failed. Please reload the page to try again.'))); ?>
		};
		var token = document.head.dataset.requesttoken || '';
		var $ = function (id) { return document.getElementById(id); };
		var started = Date.now();

		function fail(msg) {
			$('run-wait').hidden = true;
			$('run-error-text').textContent = msg || T.failed;
			$('run-error').hidden = false;
		}
		function status(text) { $('run-status').textContent = text; }
		function late() {
			if (Date.now() - started > 20 * 60 * 1000) { $('run-manual').hidden = false; return true; }
			return false;
		}

		function pollGuest(gt) {
			fetch(statusUrl + '?gt=' + encodeURIComponent(gt), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j.status === 'ready' && j.url) { location.href = j.url; return; }
					if (j.status === 'gone') { status(T.gone); return; }
					status(j.pod_status && !j.url && /^Running/.test(j.pod_status) ? T.upNoAddress : (j.pod_status || T.starting));
					if (!late()) setTimeout(function () { pollGuest(gt); }, 5000);
				})
				.catch(function () { setTimeout(function () { pollGuest(gt); }, 8000); });
		}

		function pollPod(pod) {
			fetch(containersUrl, { headers: { 'OCS-APIRequest': 'true', 'requesttoken': token }, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					var list = Array.isArray(j) ? j : ((j && j.ocs && j.ocs.data) || []);
					var c = list.filter(function (x) { return x.pod_name === pod; })[0];
					if (c) {
						var running = /^Running/.test(c.status || '');
						// The address appears with the proxy port, before Jupyter listens; it is
						// ready once the host has added the notebook token (as for guests).
						var ready = running && c.url && /[?&]token=/.test(c.url);
						if (ready) { location.href = c.url; return; }
						status(pod + ': ' + (running ? T.upNoAddress : (c.status || T.starting)));
					}
					if (!late()) setTimeout(function () { pollPod(pod); }, 5000);
				})
				.catch(function () { setTimeout(function () { pollPod(pod); }, 8000); });
		}

		// The work itself, asked for once per page
		var body = new URLSearchParams(params);
		fetch(startUrl, {
			method: 'POST', body: body, credentials: 'same-origin',
			headers: { 'OCS-APIRequest': 'true', 'requesttoken': token },
		})
			.then(function (r) { return r.json().catch(function () { return {}; }); })
			.then(function (j) {
				var err = j.error || (j.status === 'error' && j.data && j.data.message) || '';
				if (err) { fail(err); return; }
				if (j.status === 'ready' && j.url) { location.href = j.url; return; }
				if (guest && j.guest) { status(T.starting); setTimeout(function () { pollGuest(j.guest); }, 3000); return; }
				if (!guest && j.pod) {
					if (j.file) { $('run-file').textContent = T.copy.replace('%s', j.file); $('run-file').hidden = false; }
					status(T.waitingFor.replace('%s', j.pod));
					setTimeout(function () { pollPod(j.pod); }, 3000);
					return;
				}
				fail('');
			})
			.catch(function () { fail(''); });
	})();
	</script>
	<?php endif; ?>
	</div>
</div>
