<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('user_pods', 'main');
?>
<?php $guest = !empty($_['guest']); ?>
<div id="app-content">
	<div class="viewcontainer" style="max-width:640px;margin:48px auto;padding:0 16px;">
	<?php if ($_['error'] !== ''): ?>
		<h2><?php p($l->t('The notebook could not be started')); ?></h2>
		<p style="color:var(--color-error-text)"><?php p($_['error']); ?></p>
		<?php if ($guest): ?>
		<p><a class="button" href="<?php p($_['login_url']); ?>"><?php p($l->t('Log in and run it on your own account')); ?></a></p>
		<?php else: ?>
		<p><a class="button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('user_pods.page.index')); ?>"><?php p($l->t('Open the Containers app')); ?></a></p>
		<?php endif; ?>
	<?php elseif ($guest): ?>
		<h2 id="run-title"><?php p($l->t('Starting a notebook server for you…')); ?></h2>
		<p><?php p($l->t('This is a temporary server: it is deleted after %s minutes and nothing you do in it is saved. Starting usually takes about a minute.', [(string)$_['lifetime']])); ?></p>
		<p><?php print_unescaped($l->t('To keep your work, %1$slog in%2$s and the notebook is copied to your own files instead.', ['<a href="' . \OCP\Util::sanitizeHTML($_['login_url']) . '">', '</a>'])); ?></p>
		<div class="icon-loading-dark" style="height:40px;"></div>
		<p id="run-status" style="color:var(--color-text-maxcontrast)"><?php p($l->t('Waiting for the server…')); ?></p>
		<p id="run-manual" hidden><?php p($l->t('Taking longer than expected. Please try again in a few minutes.')); ?></p>
		<script nonce="<?php p(\OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce()); ?>">
		(function () {
			var api = <?php print_unescaped(json_encode($_['status_url'])); ?>;
			var started = Date.now();
			function poll() {
				fetch(api, { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j.status === 'ready' && j.url) { location.href = j.url; return; }
						if (j.status === 'gone') { document.getElementById('run-status').textContent = <?php print_unescaped(json_encode($l->t('The server is no longer available.'))); ?>; return; }
						document.getElementById('run-status').textContent = j.pod_status && !j.url && /^Running/.test(j.pod_status)
							? <?php print_unescaped(json_encode($l->t('server is up, waiting for its address…'))); ?>
							: (j.pod_status || <?php print_unescaped(json_encode($l->t('starting…'))); ?>);
						if (Date.now() - started > 20 * 60 * 1000) { document.getElementById('run-manual').hidden = false; return; }
						setTimeout(poll, 5000);
					})
					.catch(function () { setTimeout(poll, 8000); });
			}
			setTimeout(poll, 3000);
		})();
		</script>
	<?php else: ?>
		<h2 id="run-title"><?php p($l->t('Starting your notebook server…')); ?></h2>
		<p><?php p($l->t('Your copy of the notebook is in your files as %s. A server for it is starting; this usually takes about a minute, longer the first time an image is used on a machine.', [$_['file']])); ?></p>
		<div class="icon-loading-dark" style="height:40px;"></div>
		<p id="run-status" style="color:var(--color-text-maxcontrast)"><?php p($l->t('Waiting for %s…', [$_['pod']])); ?></p>
		<p id="run-manual" hidden><?php p($l->t('Taking longer than expected.')); ?>
			<a href="<?php p($_['pods_url']); ?>"><?php p($l->t('See your containers')); ?></a></p>
		<script nonce="<?php p(\OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce()); ?>">
		(function () {
			var pod = <?php print_unescaped(json_encode($_['pod'])); ?>;
			var file = <?php print_unescaped(json_encode($_['file'])); ?>;
			var lab = <?php print_unescaped(json_encode(str_contains((string)$_['image'], 'jupyterlab'))); ?>;
			var api = <?php print_unescaped(json_encode($_['containers_url'])); ?>;
			// The server's address opens its file tree; open the notebook instead, as
			// PodService::runningServerUrl does when the server is already up.
			function notebookUrl(url) {
				var m = /^(https?:\/\/[^\/]+)\/([^?#]*)\?(?:[^#]*&)?token=([^&#]+)/.exec(url);
				if (!m || !file) { return url; }
				var path = file.split('/').map(encodeURIComponent).join('/');
				return m[1] + (lab || /(^|\/)lab/.test(m[2]) ? '/lab/tree/' : '/notebooks/') + path + '?token=' + m[3];
			}
			var started = Date.now();
			function poll() {
				fetch(api, { headers: { 'OCS-APIRequest': 'true', 'requesttoken': OC.requestToken }, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						var list = Array.isArray(j) ? j : ((j && j.ocs && j.ocs.data) || []);
						var c = list.filter(function (x) { return x.pod_name === pod; })[0];
						if (c) {
							var running = /^Running/.test(c.status || '');
							// The address appears with the proxy port, before Jupyter listens; it is
							// ready once the host has added the notebook token (as for guests).
							var ready = running && c.url && /[?&]token=/.test(c.url);
							document.getElementById('run-status').textContent = pod + ': ' + (running && !ready
								? <?php print_unescaped(json_encode($l->t('server is up, waiting for its address…'))); ?>
								: (c.status || <?php print_unescaped(json_encode($l->t('starting…'))); ?>));
							if (ready) { location.href = notebookUrl(c.url); return; }
						}
						if (Date.now() - started > 20 * 60 * 1000) { document.getElementById('run-manual').hidden = false; return; }
						setTimeout(poll, 5000);
					})
					.catch(function () { setTimeout(poll, 8000); });
			}
			setTimeout(poll, 3000);
		})();
		</script>
	<?php endif; ?>
	</div>
</div>
