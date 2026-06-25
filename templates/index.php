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
			<button id="pod-create" type="button" class="button primary pods-new-btn">
				<span class="pods-new-plus" aria-hidden="true">+</span> <?php p($l->t('New')); ?>
			</button>
			<button id="pods-reload" type="button" class="pods-icon-btn"
				title="<?php p($l->t('Reload')); ?>" aria-label="<?php p($l->t('Reload')); ?>">
				<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
					<path fill="currentColor" d="M17.65 6.35C16.2 4.9 14.21 4 12 4C7.58 4 4.01 7.58 4.01 12S7.58 20 12 20C15.73 20 18.84 17.45 19.73 14H17.65C16.83 16.33 14.61 18 12 18C8.69 18 6 15.31 6 12S8.69 6 12 6C13.66 6 15.14 6.69 16.22 7.78L13 11H20V4L17.65 6.35Z" />
				</svg>
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
					<div id="ok" class="btn-pod pods-hidden">
						<a class="button primary" href="#"><?php p($l->t('Launch')); ?></a>
					</div>
					<div id="cancel" class="btn-pod pods-hidden">
						<a class="button" href="#"><?php p($l->t('Cancel')); ?></a>
					</div>
				</span>
				<div id="newpod-placeholder">
					<div class="newpod-placeholder-icon" aria-hidden="true">
						<svg width="64" height="64" viewBox="0 0 24 24"><path fill="currentColor" d="M21,16.5C21,16.88 20.79,17.21 20.47,17.38L12.57,21.82C12.41,21.94 12.21,22 12,22C11.79,22 11.59,21.94 11.43,21.82L3.53,17.38C3.21,17.21 3,16.88 3,16.5V7.5C3,7.12 3.21,6.79 3.53,6.62L11.43,2.18C11.59,2.06 11.79,2 12,2C12.21,2 12.41,2.06 12.57,2.18L20.47,6.62C20.79,6.79 21,7.12 21,7.5V16.5M12,4.15L6.04,7.5L12,10.85L17.96,7.5L12,4.15M5,15.91L11,19.29V12.58L5,9.21V15.91M19,15.91V9.21L13,12.58V19.29L19,15.91Z" /></svg>
					</div>
					<p class="newpod-placeholder-text"><?php p($l->t('Choose an image from the drop-down')); ?></p>
				</div>
				<div id="newpod-spinner" class="icon-loading" hidden></div>
				<div id="description" class="pods-hidden"></div>
				<div id="ssh" class="pods-hidden">
					<textarea id="public_key" placeholder="<?php p($l->t('Public SSH key')); ?>"
						title="<?php p($l->t('Paste your public SSH key here')); ?>"></textarea>
					<div class="key_buttons">
						<a id="save_ssh_public_key" class="button btn-sg" href="#" title="<?php p($l->t('Save SSH key to browser storage')); ?>"><?php p($l->t('Save')); ?></a>
						<a id="load_ssh_public_key" class="button btn-sg" href="#" title="<?php p($l->t('Load SSH key from browser storage')); ?>"><?php p($l->t('Load')); ?></a>
						<a id="clear_ssh_public_key" class="button btn-sg" href="#" title="<?php p($l->t('Clear stored SSH key')); ?>"><?php p($l->t('Clear')); ?></a>
					</div>
				</div>
				<div id="pod_type" class="pods-hidden"><label><?php p($l->t('Instance type:')); ?></label></div>
				<div id="storage" class="pods-hidden"></div>
				<div id="cvmfs" class="pods-hidden"></div>
				<div id="setup" class="pods-hidden"></div>
				<div id="file" class="pods-hidden"><span id="file_text"><?php p($l->t('File')); ?>:</span>
					<input id="file_input" type="text" placeholder="<?php p($l->t('Optional file to open in your container')); ?>"
						title="<?php p($l->t('Path of file in your ScienceData Home')); ?>">
				</div>
				<div id="peers" class="pods-hidden"><span id="peers_text"><?php p($l->t('Peers')); ?>:</span>
					<input id="peers_input" type="text" placeholder="<?php p($l->t('Optional peers to pass to your container')); ?>"
						title="<?php p($l->t('List of the form hostname1:ip1,hostname2:ip2,…')); ?>">
				</div>
					</div>
				</div>
			</div>
		</div>
		<div id="pods-logs-modal" class="pods-modal" hidden>
			<div class="pods-modal-backdrop" data-modal-close></div>
			<div class="pods-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pods-logs-title">
				<div class="pods-modal-header">
					<h2 id="pods-logs-title"><?php p($l->t('Logs')); ?></h2>
					<span class="pods-logs-header-actions">
						<a id="pods-logs-download" class="button" href="#"><?php p($l->t('Download')); ?></a>
						<button type="button" class="pods-icon-btn pods-modal-close" data-modal-close
							title="<?php p($l->t('Close')); ?>" aria-label="<?php p($l->t('Close')); ?>">
							<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
								<path fill="currentColor" d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z" />
							</svg>
						</button>
					</span>
				</div>
				<div class="pods-modal-body">
					<div id="pods-logs-spinner" class="icon-loading" hidden></div>
					<pre id="pods-logs-content"></pre>
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
						<th id="headerPodLogs" class="pods-col th-button"><span class="hidden-visually"><?php p($l->t('Logs')); ?></span></th>
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
