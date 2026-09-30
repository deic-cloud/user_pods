<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('user_pods', 'gallery');
$featured = array_values(array_filter($_['catalog'], static fn ($e) => $e['featured'] !== null));
usort($featured, static fn ($a, $b) => $a['featured'] <=> $b['featured']);
$byCategory = [];
foreach ($_['catalog'] as $e) {
	$byCategory[$e['category']][] = $e;
}
$card = static function (array $e) use ($l, $_): void { ?>
	<div class="pods-card">
		<h4 class="pods-card-title"><?php p($e['title']); ?></h4>
		<p class="pods-card-summary"><?php p($e['summary']); ?></p>
		<div class="pods-card-foot">
			<?php if ($e['restricted']) { ?>
				<span class="pods-card-badge"><?php p($l->t('Restricted')); ?></span>
			<?php } ?>
			<a class="button pods-card-launch" href="<?php p($_['launchUrl'] . '?yaml_file=' . rawurlencode($e['file'])); ?>"><?php p($l->t('Launch')); ?></a>
		</div>
	</div>
<?php };
?>
<div id="pods-catalog" class="pods-catalog-page">
	<p class="pods-catalog-intro"><?php p($l->t('Each image starts a container of your own on the compute cluster, next to your files: a notebook server, a Linux shell with root access, a web server or a database. Launch asks you to log in; images marked Restricted are for members of a particular group or institution.')); ?></p>
	<?php if ($_['error'] !== '') { ?>
		<p class="pods-catalog-error"><?php p($l->t('The image library could not be read: %s', [$_['error']])); ?></p>
	<?php } ?>
	<?php if ($featured !== []) { ?>
		<h3 class="pods-catalog-heading"><?php p($l->t('Start here')); ?></h3>
		<div class="pods-gallery-grid">
			<?php foreach ($featured as $e) { $card($e); } ?>
		</div>
	<?php } ?>
	<?php foreach ($byCategory as $category => $entries) { ?>
		<h3 class="pods-catalog-heading"><?php p($category); ?></h3>
		<div class="pods-gallery-grid">
			<?php foreach ($entries as $e) { $card($e); } ?>
		</div>
	<?php } ?>
</div>
