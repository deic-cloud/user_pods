<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addScript('user_pods', 'main');
\OCP\Util::addStyle('user_pods', 'main');
?>
<div id="app-content">
	<div id="app-content-kubernetes" class="viewcontainer" data-client-ip="<?php p($_SERVER['REMOTE_ADDR'] ?? ''); ?>">
		<div class="pods-header">
			<h2 class="pods-title"><?php p($l->t('Containers')); ?></h2>
			<button id="pods-reload" type="button" class="pods-icon-btn"
				title="<?php p($l->t('Reload')); ?>" aria-label="<?php p($l->t('Reload')); ?>">
				<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
					<path fill="currentColor" d="M17.65 6.35C16.2 4.9 14.21 4 12 4C7.58 4 4.01 7.58 4.01 12S7.58 20 12 20C15.73 20 18.84 17.45 19.73 14H17.65C16.83 16.33 14.61 18 12 18C8.69 18 6 15.31 6 12S8.69 6 12 6C13.66 6 15.14 6.69 16.22 7.78L13 11H20V4L17.65 6.35Z" />
				</svg>
			</button>
			<button id="pod-create" type="button" class="button primary pods-new-btn">
				<span class="pods-new-plus" aria-hidden="true">+</span> <?php p($l->t('New')); ?>
			</button>
		</div>
		<div id="loading">
			<div id="loading-text"><?php p($l->t('Working…')); ?></div>
			<div class="icon-loading-dark"></div>
		</div>
		<div id="pods-modal" class="pods-modal" hidden>
			<div class="pods-modal-backdrop" data-modal-close></div>
			<div class="pods-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pods-modal-title">
				<div class="pods-modal-header">
					<h2 id="pods-modal-title"><?php p($l->t('New container')); ?></h2>
					<button type="button" class="pods-icon-btn pods-modal-close" data-modal-close
						title="<?php p($l->t('Close')); ?>" aria-label="<?php p($l->t('Close')); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
							<path fill="currentColor" d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z" />
						</svg>
					</button>
				</div>
				<div class="pods-modal-body">
					<div id="newpod">
				<span class="spanpanel">
					<select id="yaml_file" title="<?php p($l->t('YAML file')); ?>">
						<option value=""></option>
					</select>
				</span>
				<span id="links"></span>
				<span class="newpod-span">
					<div id="ok" class="btn-pod">
						<a class="button" href="#"><?php p($l->t('Launch')); ?></a>
					</div>
					<div id="cancel" class="btn-pod">
						<a class="button" href="#"><?php p($l->t('Cancel')); ?></a>
					</div>
				</span>
				<div id="description"></div>
				<div id="ssh">
					<textarea id="public_key" placeholder="<?php p($l->t('Public SSH key')); ?>"
						title="<?php p($l->t('Paste your public SSH key here')); ?>"></textarea>
					<div class="key_buttons">
						<a id="save_ssh_public_key" class="button btn-sg" href="#" title="<?php p($l->t('Save SSH key to browser storage')); ?>"><?php p($l->t('Save')); ?></a>
						<br />
						<a id="load_ssh_public_key" class="button btn-sg" href="#" title="<?php p($l->t('Load SSH key from browser storage')); ?>"><?php p($l->t('Load')); ?></a>
						<br />
						<a id="clear_ssh_public_key" class="button btn-sg" href="#" title="<?php p($l->t('Clear stored SSH key')); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
				<div id="pod_type"><label><?php p($l->t('Instance type:')); ?></label></div>
				<div id="storage"></div>
				<div id="cvmfs"></div>
				<div id="setup"></div>
				<div id="file"><span id="file_text"><?php p($l->t('File')); ?>:</span>
					<input id="file_input" type="text" placeholder="<?php p($l->t('Optional file to open in your container')); ?>"
						title="<?php p($l->t('Path of file in your ScienceData Home')); ?>">
				</div>
				<div id="peers"><span id="peers_text"><?php p($l->t('Peers')); ?>:</span>
					<input id="peers_input" type="text" placeholder="<?php p($l->t('Optional peers to pass to your container')); ?>"
						title="<?php p($l->t('List of the form hostname1:ip1,hostname2:ip2,…')); ?>">
				</div>
					</div>
				</div>
			</div>
		</div>
		<div id="running_pods">
			<table id="podstable" class="panel">
				<thead class="panel-heading">
					<tr class="podstable-head">
						<th id="headerPodName" class="pods-col"><span><?php p($l->t('Name')); ?></span></th>
						<th id="headerPodStatus" class="pods-col"><span><?php p($l->t('Status')); ?></span></th>
						<th id="headerPodView" class="pods-col"><span><?php p($l->t('View')); ?></span></th>
						<th id="headerPodMore" class="pods-col th-button"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
						<th id="headerPodDelete" class="pods-col th-button"><span class="hidden-visually"><?php p($l->t('Delete')); ?></span></th>
					</tr>
				</thead>
				<tbody id="fileList"></tbody>
				<tfoot>
					<tr class="summary text-sm">
						<td colspan="5"><span class="pods-count" data-containers="0"></span></td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</div>
