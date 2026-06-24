/**
 * user_pods — pod management UI (NC34 port of the OC7 js/script.js).
 *
 * Self-contained vanilla JS — no jQuery, no marked, no tipsy (none are reliably
 * global in NC34). Talks to the AppFramework REST API in lib/Controller/ApiController:
 *   GET  api/containers            -> [ {pod_name, status, url, ssh_url, ...}, ... ]
 *   GET  api/manifests             -> [ "foo.yaml", ... ]
 *   GET  api/manifest?yaml=foo     -> { manifest_url, pod_accepts_*, container_infos, ... } | []
 *   POST api/pod                   -> { status, data:{ name, message } }   (raw host shape)
 *   POST api/pod/delete            -> { status, data:{ name, message } }
 *   POST api/pod/allowed-ips       -> { status, data:{ ... } }
 *   POST api/pod/ports             -> { status, data:{ ... } }
 * Unlike OC7's ajax/actions.php, PodService returns the host's raw response, so
 * "success" is detected via the host's own status==='success'.
 */
(function() {
	'use strict'

	const APP = 'user_pods'
	// Keep the query string out of OC.generateUrl (it would encode the '?').
	const url = (path) => {
		const i = path.indexOf('?')
		const base = i === -1 ? path : path.slice(0, i)
		const query = i === -1 ? '' : path.slice(i)
		return OC.generateUrl('/apps/user_pods/' + base) + query
	}

	let root = null // #app-content-kubernetes
	let clientIp = ''
	const xhrPool = new Set()
	let runPodTimeouts = []
	let setAllowedIPsBusy = false

	// ---- small helpers ---------------------------------------------------

	function $(sel, ctx) { return (ctx || document).querySelector(sel) }
	function $all(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)) }

	function esc(s) {
		const d = document.createElement('div')
		d.textContent = s == null ? '' : String(s)
		return d.innerHTML
	}

	function show(el, on) {
		if (!el) return
		el.style.display = on === false ? 'none' : ''
	}

	function alertError(msg) {
		if (OC.dialogs && OC.dialogs.alert) {
			OC.dialogs.alert(msg, t(APP, 'Error'))
		} else {
			window.alert(msg)
		}
	}

	// Minimal, safe markdown -> HTML for the manifest .md description.
	function renderMarkdown(md) {
		const lines = esc(md || '').split('\n')
		let html = ''
		let inList = false
		const inline = (s) => s
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
			.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer noopener">$1</a>')
		for (let line of lines) {
			const h = line.match(/^(#{1,6})\s+(.*)$/)
			const li = line.match(/^\s*[-*]\s+(.*)$/)
			if (h) {
				if (inList) { html += '</ul>'; inList = false }
				const level = h[1].length
				html += '<h' + level + '>' + inline(h[2]) + '</h' + level + '>'
			} else if (li) {
				if (!inList) { html += '<ul>'; inList = true }
				html += '<li>' + inline(li[1]) + '</li>'
			} else if (line.trim() === '') {
				if (inList) { html += '</ul>'; inList = false }
			} else {
				if (inList) { html += '</ul>'; inList = false }
				html += '<p>' + inline(line) + '</p>'
			}
		}
		if (inList) html += '</ul>'
		return html
	}

	function getParam(name) {
		const m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search)
		return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : null
	}

	// ---- ajax wrappers ---------------------------------------------------

	function loadingStart(text) {
		const token = {}
		xhrPool.add(token)
		const lt = $('#loading-text')
		if (lt) lt.textContent = t(APP, text)
		show($('#loading'), true)
		return token
	}

	function loadingDone(token) {
		xhrPool.delete(token)
		if (xhrPool.size === 0) show($('#loading'), false)
	}

	function apiGet(path, loadingText) {
		const token = loadingStart(loadingText)
		return fetch(url(path), {
			headers: { 'OCS-APIRequest': 'true', requesttoken: OC.requestToken },
		})
			.then((r) => r.json())
			.finally(() => loadingDone(token))
	}

	function apiPost(path, params, loadingText) {
		const token = loadingStart(loadingText)
		const body = new URLSearchParams()
		Object.keys(params).forEach((k) => body.append(k, params[k] == null ? '' : params[k]))
		return fetch(url(path), {
			method: 'POST',
			headers: {
				requesttoken: OC.requestToken,
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: body.toString(),
		})
			.then((r) => r.json())
			.finally(() => loadingDone(token))
	}

	const hostOk = (json) => json && json.status === 'success'
	const hostMessage = (json) => (json && json.data && (json.data.message || json.data.error)) || ''

	// ---- containers table ------------------------------------------------

	function portFromUrl(u, re) {
		if (!u) return ''
		const m = u.match(re)
		return m ? m[1] : ''
	}

	function renderViewCell(c) {
		if ((c.status || '').includes('Running') && c.url) {
			return '<td><div data-column="view"><span><a href="' + esc(c.url) + '">' + esc(c.url) + '</a></span></div></td>'
		}
		const text = (c.status || '').includes('Running') ? 'none' : 'wait'
		return '<td><div data-column="view"><span>' + text + '</span></div></td>'
	}

	function formatStatus(status) {
		if ((status || '').includes('Running')) {
			try {
				const date = status.substr(status.indexOf(':') + 1)
				const time = new Date(date).toString().slice(0, 25)
				return 'Running: '.concat(time)
			} catch (e) { /* fall through */ }
		}
		return status || ''
	}

	function expandedTable(c) {
		const httpsPort = portFromUrl(c.url, /^https:\/\/.+:(\d+).*$/)
		const sshPort = portFromUrl(c.ssh_url, /^ssh:\/\/.+:([0-9]+)$/)
		const extraPorts = c.extra_ports || ''
		let rows = ''
		rows += row('container name:', esc(c.container_name))
		rows += row('image name:', esc(c.image_name))
		rows += row('pod IP:', esc(c.pod_ip))
		rows += row('node IP:', esc(c.node_ip))
		rows += row('owner:', esc(c.owner))
		rows += row('age:', esc(c.age))
		if (c.ssh_url) {
			rows += '<tr><td class="expanded-column-name">ssh access:</td>'
				+ '<td class="expanded-column-value"><span class="expanded-row-ssh-url"><a href="' + esc(c.ssh_url) + '">' + esc(c.ssh_url) + '</a></span>'
				+ '<span class="expanded-row-ssh-ip"><input class="allowed_ip" type="text" title="' + esc(t(APP, 'Allowed client IP addresses')) + '" value="' + esc(c.allowed_ips || '') + '" />'
				+ '<label class="button add_current_ip" title="' + esc(t(APP, 'Add your current IP')) + '">+My IP</label></span></td></tr>'
		}
		if (extraPorts) {
			rows += '<tr><td class="expanded-column-name">extra ports:</td><td class="expanded-column-value"><span>' + esc(extraPorts) + '</span></td></tr>'
		}
		rows += '<tr><td class="expanded-column-name">persistent ports:</td><td class="expanded-column-value"><span>'
			+ '<input type="checkbox" class="keep_ports"' + (c.keep_ports === '1' ? ' checked="checked"' : '') + ' /></span></td></tr>'

		function row(name, value) {
			return '<tr><td class="expanded-column-name">' + name + '</td><td class="expanded-column-value"><span>' + value + '</span></td></tr>'
		}

		return '<tr hidden class="expanded-row" data-pod-name="' + esc(c.pod_name) + '" data-https-port="' + esc(httpsPort)
			+ '" data-ssh-port="' + esc(sshPort) + '" data-extra-ports="' + esc(extraPorts) + '"><td colspan="5">'
			+ '<table class="panel expanded-table">' + rows + '</table></td></tr>'
	}

	function simpleRow(c) {
		const httpsPort = portFromUrl(c.url, /^https:\/\/.+:(\d+).*$/)
		const sshPort = portFromUrl(c.ssh_url, /^ssh:\/\/.+:([0-9]+)$/)
		let str = '<tr class="simple-row" data-pod-name="' + esc(c.pod_name) + '" data-pod-ip="' + esc(c.pod_ip || '')
			+ '" data-image-name="' + esc(c.image_name || '') + '" data-https-port="' + esc(httpsPort) + '" data-ssh-port="' + esc(sshPort)
			+ '" data-extra-ports="' + esc(c.extra_ports || '') + '">'
		str += '<td><div data-column="pod_name"><span>' + esc(c.pod_name) + '</span></div></td>'
		str += '<td><div data-column="status"><span>' + esc(formatStatus(c.status)) + '</span></div></td>'
		str += renderViewCell(c)
		str += '<td class="td-button"><a href="#" title="' + esc(t(APP, 'Expand')) + '" class="expand-view permanent action icon icon-right-open"></a></td>'
		str += '<td class="td-button"><a href="#" title="' + esc(t(APP, 'Delete pod')) + '" class="delete-pod permanent action icon icon-trash-empty"></a></td>'
		str += '</tr>'
		str += expandedTable(c)
		return str
	}

	function updateContainerCount() {
		const count = $all('#podstable tbody#fileList tr.simple-row').length
		const cell = $('#podstable tfoot .summary td')
		if (!cell) return
		cell.innerHTML = '<span class="info" data-containers="' + count + '">' + count + ' '
			+ (count === 1 ? t(APP, 'container') : t(APP, 'containers')) + '</span>'
	}

	function wireRow(tr, c) {
		const expanded = tr.nextElementSibling
		if (expanded) {
			const ip = $('.expanded-row-ssh-ip input', expanded)
			if (ip) {
				ip.addEventListener('keypress', function(e) {
					if (e.which === 13 && !setAllowedIPsBusy) {
						setAllowedIPs(c.pod_name, this.value)
					}
				})
			}
			const addIp = $('.add_current_ip', expanded)
			if (addIp) {
				addIp.addEventListener('click', function() {
					const input = this.parentNode.querySelector('input')
					let cur = (input.value || '').replace(' ', '')
					const re = new RegExp('(^|,)' + clientIp + '/*[0-9]*(,|$)', 'g')
					if (!cur.match(re)) {
						cur = cur + (cur !== '' ? ',' : '') + clientIp
						input.value = cur
					}
				})
			}
			const keep = $('.keep_ports', expanded)
			if (keep) {
				keep.addEventListener('change', function() {
					const httpsPort = !this.checked ? '' : expanded.getAttribute('data-https-port')
					const sshPort = !this.checked ? '' : expanded.getAttribute('data-ssh-port')
					let extra = !this.checked ? '' : (expanded.getAttribute('data-extra-ports') || '')
					extra = extra.replace(/^\d+:/, '').replace(/,\d+:/, ',')
					setPortNumbers(c.pod_name, httpsPort, sshPort, extra)
				})
			}
		}
	}

	function getContainers(callback) {
		apiGet('api/containers', 'Retrieving table data…')
			.then((data) => {
				if (!Array.isArray(data)) {
					alertError(t(APP, 'get_containers: Something went wrong…'))
					return
				}
				const expandedNames = $all('#podstable #fileList tr.simple-row .expand-view.icon-down-open')
					.map((a) => a.closest('tr').getAttribute('data-pod-name'))
				const body = $('#fileList')
				body.innerHTML = ''
				data.forEach((c) => {
					body.insertAdjacentHTML('beforeend', simpleRow(c))
					const tr = body.querySelector('tr.simple-row[data-pod-name="' + cssEscape(c.pod_name) + '"]')
					if (tr) wireRow(tr, c)
				})
				updateContainerCount()
				$all('#podstable #fileList tr.simple-row').forEach((tr) => {
					if (expandedNames.indexOf(tr.getAttribute('data-pod-name')) !== -1) {
						toggleExpanded($('.expand-view', tr))
					}
				})
				if (callback) callback()
			})
			.catch((e) => alertError(t(APP, 'get_containers: Something went wrong. ') + e))
	}

	function cssEscape(s) {
		return String(s).replace(/["\\]/g, '\\$&')
	}

	// ---- pod actions -----------------------------------------------------

	function runPod(p) {
		apiPost('api/pod', {
			yaml_url: p.yaml_url,
			public_key: p.ssh_key,
			mount_root: p.mount_root,
			mount_path: p.mount_path,
			cvmfs_repos: p.cvmfs_repos,
			file: p.file,
			setup_script: p.setup_script,
			peers: p.peers,
			pod_type: p.type,
			allowed_ip: clientIp,
		}, 'Creating pod…')
			.then((json) => {
				if (hostOk(json) && json.data && json.data.name) {
					getContainers()
					runPodTimeouts.forEach(clearTimeout)
					runPodTimeouts = [10000, 30000, 60000].map((ms) => setTimeout(getContainers, ms))
				} else {
					alertError(t(APP, 'run_pod: ') + (hostMessage(json) || t(APP, 'Something went wrong…')))
				}
			})
			.catch((e) => alertError(t(APP, 'run_pod: Something went wrong. ') + e))
	}

	function setAllowedIPs(podName, ips) {
		setAllowedIPsBusy = true
		apiPost('api/pod/allowed-ips', { pod: podName, ips: ips }, 'Setting firewall rules…')
			.then((json) => {
				if (!hostOk(json)) {
					alertError(t(APP, 'set_allowed_ips: Something went wrong…'))
					setStatusText(podName, 'Setting allowed IPs failed')
				}
			})
			.catch((e) => alertError(t(APP, 'set_allowed_ips: Something went wrong. ') + e))
			.finally(() => { setAllowedIPsBusy = false })
	}

	function setPortNumbers(podName, httpsPort, sshPort, extraPorts) {
		apiPost('api/pod/ports', {
			pod: podName, https_port: httpsPort, ssh_port: sshPort, extra_ports: extraPorts,
		}, 'Setting port numbers…')
			.then((json) => {
				if (!hostOk(json)) {
					alertError(t(APP, 'set_port_numbers: Something went wrong… ') + hostMessage(json))
					setStatusText(podName, 'Setting port numbers failed')
				}
			})
			.catch((e) => alertError(t(APP, 'set_port_numbers: Something went wrong. ') + e))
	}

	function deletePod(podName) {
		const delLink = $('#podstable tr[data-pod-name="' + cssEscape(podName) + '"] a.delete-pod')
		if (delLink) show(delLink, false)
		setStatusText(podName, 'Deleting')
		apiPost('api/pod/delete', { pod: podName }, 'Deleting your pod…')
			.then((json) => {
				if (hostOk(json)) {
					$all('tr[data-pod-name="' + cssEscape(podName) + '"]').forEach((tr) => tr.remove())
					updateContainerCount()
				} else {
					alertError(t(APP, 'delete_pod: Something went wrong…'))
					if (delLink) show(delLink, true)
					setStatusText(podName, 'Delete failed')
				}
			})
			.catch((e) => alertError(t(APP, 'delete_pod: Something went wrong. ') + e))
	}

	function setStatusText(podName, text) {
		const span = $('#podstable tr[data-pod-name="' + cssEscape(podName) + '"] div[data-column="status"] span')
		if (span) span.textContent = text
	}

	// ---- new-pod form ----------------------------------------------------

	function toggleExpanded(expander) {
		if (!expander) return
		const tr = expander.closest('tr')
		const next = tr.nextElementSibling
		if (expander.className.indexOf('icon-down-open') === -1) {
			if (next) next.hidden = false
			expander.classList.remove('icon-right-open')
			expander.classList.add('icon-down-open')
		} else {
			if (next) next.hidden = true
			expander.classList.remove('icon-down-open')
			expander.classList.add('icon-right-open')
		}
	}

	function toggleNewpod() {
		const np = $('#newpod')
		np.style.display = (np.style.display === 'none' || np.style.display === '') ? 'block' : 'none'
		$('#pod-create').classList.toggle('primary')
	}

	let currentManifestUrl = ''

	function loadYaml(yamlFile) {
		$('#public_key').value = ''
		const select = $('#yaml_file')
		const value = yamlFile || select.value
		if (!value) {
			show($('#storage'), false)
			show($('#cvmfs'), false)
			$all('#pod_type select').forEach((s) => s.remove())
			show($('#pod_type'), false)
			$all('#setup input').forEach((i) => { i.value = '' })
			show($('#setup'), false)
			show($('#ssh'), false)
			return Promise.resolve()
		}
		return apiGet('api/manifest?yaml=' + encodeURIComponent(value), 'Retrieving YAML…')
			.then((d) => {
				// checkManifest returns [] (empty array) when not allowed.
				if (!d || Array.isArray(d) || !d.manifest_url) {
					$('#description').innerHTML = '<p>' + esc(t(APP, 'This is a private image.')) + '</p>'
					show($('#description'), true)
					show($('#ok'), false)
					show($('#cancel'), false)
					show($('#ssh'), false)
					show($('#peers'), false)
					show($('#cvmfs'), false)
					show($('#setup'), false)
					return
				}
				currentManifestUrl = d.manifest_url
				show($('#ok'), true)
				show($('#cancel'), true)
				const ghUrl = d.manifest_url.replace(/^https:\/\/raw\.githubusercontent\.com\/deic-dk\/pod_manifests\/main\//,
					'https://github.com/deic-dk/pod_manifests/blob/main/')
				$('#links').innerHTML = '<span><a href="' + esc(ghUrl) + '" target="_blank" rel="noreferrer noopener">YAML source</a></span>'
				$('#description').innerHTML = renderMarkdown(d.manifest_info)

				show($('#ssh'), d.pod_accepts_public_key === true)

				if (d.pod_accepts_file === true) {
					show($('#file'), true)
					if (d.pod_file) $('#file_input').value = d.pod_file
				} else {
					show($('#file'), false)
				}

				if ('pod_peers' in d || 'pod_peers_image' in d) {
					show($('#peers'), true)
					if (d.pod_peers) {
						$('#peers_input').value = d.pod_peers
					} else if (d.pod_peers_image) {
						let peers = ''
						$all('#podstable .simple-row').forEach((tr) => {
							if (tr.getAttribute('data-image-name') === d.pod_peers_image) {
								peers += (peers ? ',' : '') + tr.getAttribute('data-pod-name') + ':' + tr.getAttribute('data-pod-ip')
							}
						})
						$('#peers_input').value = peers || 'batch:'
					}
				} else {
					show($('#peers'), false)
				}

				buildMountInputs(d)
			})
			.catch((e) => alertError(t(APP, 'check_manifest: Something went wrong. ') + e))
	}

	function buildMountInputs(d) {
		show($('#storage'), false)
		show($('#cvmfs'), false)
		show($('#pod_type'), false)
		$all('#setup input').forEach((i) => { i.value = '' })
		show($('#setup'), false)

		if (d.pod_types && d.pod_types.length) {
			let sel = '<select title="' + esc(t(APP, 'Pod type')) + '"><option value=""></option>'
			d.pod_types.forEach((pt) => { sel += '<option value="' + esc(pt) + '">' + esc(pt) + '</option>' })
			sel += '</select>'
			$all('#pod_type select').forEach((s) => s.remove())
			$('#pod_type').insertAdjacentHTML('beforeend', sel)
			show($('#pod_type'), true)
		}

		const hasSciencedataMount = d.pod_mount_path && d.pod_mount_path.sciencedata
		if (!(hasSciencedataMount || d.pod_mount_src || d.cvmfs_repos || d.setup_script)) {
			return
		}
		const infos = d.container_infos || []
		infos.forEach((container) => {
			const mountPaths = container.mount_paths || {}
			Object.keys(mountPaths).forEach((name) => {
				if (name !== 'sciencedata') return
				const mountPath = mountPaths[name]
				const mountName = String(mountPath).substring(String(mountPath).lastIndexOf('/') + 1)
				if (!mountPath || !mountName) return
				const filesOpt = (d.nfs_rw && d.nfs_rw === 'yes') ? '' : '<option value="files">/files/</option>'
				let mountInput = '<span>' + esc(t(APP, 'Mount source:')) + ' </span>'
					+ '<select id="mount_root"><option value="storage">/storage/</option>' + filesOpt + '</select>'
				mountInput += '<input id="mount_input" data-image-name="' + esc(container.image_name) + '" type="text" placeholder="'
					+ esc(t(APP, 'Path')) + '" data-mount-root="storage" data-mount-path="' + esc(mountPath) + '" title="'
					+ esc(t(APP, 'Directory to mount on') + ' ' + mountPath + ' ' + t(APP, 'inside the container')) + '">'
				const storage = $('#storage')
				storage.innerHTML = mountInput
				show(storage, true)
				const mr = $('#mount_root')
				if (mr) {
					mr.addEventListener('change', function() {
						const mi = $('#mount_input')
						if (mi) mi.setAttribute('data-mount-root', this.value)
					})
				}
			})

			if (d.cvmfs_repos) {
				const el = $('#cvmfs')
				el.innerHTML = '<span>' + esc(t(APP, 'CVMFS Repositories')) + ':</span> <input type="text" placeholder="'
					+ esc(d.cvmfs_repos) + '" title="' + esc(t(APP, 'Comma-separated list of CVMFS repositories. Leave blank to use pod default.')) + '" />'
				show(el, true)
			}
			if (d.setup_script) {
				const el = $('#setup')
				el.innerHTML = '<span>' + esc(t(APP, 'Setup script')) + ':</span> <input type="text" value="'
					+ esc(d.setup_script) + '" title="' + esc(t(APP, 'Optional path to setup script to run in your pod.')) + '" />'
				show(el, true)
			}
		})
	}

	function onLaunch() {
		const yamlUrl = currentManifestUrl
		const sshKey = $('#public_key').value
		const file = $('#file_input').value
		const setupInput = $('#setup input')
		const setupScript = setupInput ? setupInput.value : ''
		const mountInput = $('#mount_input')
		const mountRoot = mountInput ? mountInput.getAttribute('data-mount-root') : ''
		const cvmfsInput = $('#cvmfs input')
		const cvmfsRepos = cvmfsInput ? cvmfsInput.value : ''
		const peers = $('#peers_input').value || ''
		const typeSel = $('#pod_type select')
		const type = typeSel ? typeSel.value : ''

		const sshVisible = $('#ssh').style.display !== 'none' && $('#ssh').offsetParent !== null
		if (sshVisible && (!sshKey || sshKey === '')) {
			alertError(t(APP, 'Please fill in a public SSH key'))
			return
		}
		let mountPath = ''
		if (mountInput && mountInput.offsetParent !== null) {
			mountPath = mountInput.value
			if (!mountPath) {
				alertError(t(APP, 'Please fill in the directory to mount from your home server'))
				return
			}
		}
		runPod({
			yaml_url: yamlUrl, ssh_key: sshKey, mount_root: mountRoot, mount_path: mountPath,
			cvmfs_repos: cvmfsRepos, file: file, setup_script: setupScript, peers: peers, type: type,
		})
	}

	// ---- bootstrap -------------------------------------------------------

	function loadManifests() {
		return apiGet('api/manifests', 'Loading manifests…').then((list) => {
			if (!Array.isArray(list)) return
			const select = $('#yaml_file')
			list.forEach((name) => {
				const opt = document.createElement('option')
				opt.value = name
				opt.textContent = name.replace(/\.yaml$/, '')
				select.appendChild(opt)
			})
		})
	}

	function ready() {
		root = $('#app-content-kubernetes')
		if (!root) return
		clientIp = root.getAttribute('data-client-ip') || ''

		$('#pod-create').addEventListener('click', (e) => { e.preventDefault(); toggleNewpod() })
		$('#cancel').addEventListener('click', (e) => { e.preventDefault(); toggleNewpod() })
		$('#yaml_file').selectedIndex = -1
		$('#yaml_file').addEventListener('change', () => loadYaml())
		$('#ok').addEventListener('click', (e) => { e.preventDefault(); onLaunch() })
		$('#pods_refresh').addEventListener('click', () => getContainers())
		$('#save_ssh_public_key').addEventListener('click', (e) => { e.preventDefault(); localStorage.public_ssh_key = $('#public_key').value })
		$('#load_ssh_public_key').addEventListener('click', (e) => { e.preventDefault(); $('#public_key').value = localStorage.public_ssh_key || '' })
		$('#clear_ssh_public_key').addEventListener('click', (e) => { e.preventDefault(); localStorage.public_ssh_key = ''; $('#public_key').value = '' })

		// Delegated handlers for the dynamically built table.
		$('#podstable').addEventListener('click', (e) => {
			const expand = e.target.closest('.expand-view')
			if (expand) { e.preventDefault(); toggleExpanded(expand); return }
			const del = e.target.closest('.delete-pod')
			if (del) {
				e.preventDefault()
				const podName = del.closest('tr').querySelector('div[data-column="pod_name"] span').textContent.trim()
				const confirm = (OC.dialogs && OC.dialogs.confirm) ? OC.dialogs.confirm : null
				if (confirm) {
					confirm(t(APP, 'Are you sure you want to delete the pod') + ' ' + podName + '?',
						t(APP, 'Delete confirmation'), (ok) => { if (ok) deletePod(podName) })
				} else if (window.confirm(t(APP, 'Delete pod') + ' ' + podName + '?')) {
					deletePod(podName)
				}
			}
		})

		loadManifests()
		getContainers(() => {
			const yamlFile = getParam('yaml_file')
			if (yamlFile) {
				const file = getParam('file')
				show($('#newpod'), true)
				$('#pod-create').classList.remove('primary')
				loadYaml(yamlFile).then(() => {
					$('#yaml_file').value = yamlFile
					if (file) $('#file_input').value = file
				})
			}
		})
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready)
	} else {
		ready()
	}
})()
