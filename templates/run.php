<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('user_pods', 'main');
?>
<div id="app-content">
	<div class="viewcontainer" style="max-width:640px;margin:48px auto;padding:0 16px;">
	<?php if ($_['error'] !== ''): ?>
		<h2><?php p($l->t('The notebook could not be started')); ?></h2>
		<p style="color:var(--color-error-text)"><?php p($_['error']); ?></p>
		<p><a class="button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('user_pods.page.index')); ?>"><?php p($l->t('Open the Containers app')); ?></a></p>
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
			var api = <?php print_unescaped(json_encode($_['containers_url'])); ?>;
			var started = Date.now();
			function poll() {
				fetch(api + '?format=json', { headers: { 'OCS-APIRequest': 'true', 'requesttoken': OC.requestToken }, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						var list = (j && j.ocs && j.ocs.data) || [];
						var c = list.filter(function (x) { return x.pod_name === pod; })[0];
						if (c) { document.getElementById('run-status').textContent = pod + ': ' + (c.status || ''); }
						if (c && /^Running/.test(c.status || '') && c.url) { location.href = c.url; return; }
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
