<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('user_pods', 'main');
?>
<div class="pods-guide-page"><!-- the public layout does not scroll the page: this box does (as the catalog) -->
<div class="pods-guide">
	<?php if ($_['html'] === ''): ?>
		<h2><?php p($l->t('No guide for this image')); ?></h2>
		<p><a href="<?php p($_['catalogUrl']); ?>"><?php p($l->t('See the image catalog')); ?></a></p>
	<?php else: ?>
		<?php print_unescaped($_['html']); /* MarkdownLite: escaped, then marked up */ ?>
		<p class="pods-guide-back"><a href="<?php p($_['catalogUrl']); ?>"><?php p($l->t('All images')); ?></a></p>
	<?php endif; ?>
</div>
</div>
