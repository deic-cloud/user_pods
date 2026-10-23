# Containers (user_pods)

A Nextcloud app for starting and managing containers: a root shell on an
ephemeral Linux system with fast, trusted access to the user's own files, and
one-click Jupyter notebook servers. The app is a thin web layer; the
containers themselves are created by a separate Kubernetes host service
([sciencedata_kubernetes](https://github.com/deic-dk/sciencedata_kubernetes)),
and the available images are described by a manifest library
([pod_manifests](https://github.com/deic-dk/pod_manifests)).

## What users can do

### Containers

The **Containers** app lists the user's running containers and starts new
ones (**+ New**):

- **Image** — chosen from the manifest library. A manifest may restrict an
  image to certain users, e-mail domains or groups (groups need the
  user_group_admin app); others do not see it as launchable.
- **Type** — the size (small, medium, large), where the manifest offers a
  choice.
- **SSH public key** — for images with SSH access; can be remembered in the
  browser.
- **Storage** — for images that mount the user's files: a folder under
  `/storage` (read-write) or `/files` (read-only).
- **Allowed IP addresses** — a new container only accepts connections from
  the address it was created from. The list can be changed afterwards
  (**+ My IP** fills in the current address).

Per container the list shows its status, its web address (with the access
token, for notebook servers), SSH details, extra ports, and its log. A
container lives until the user deletes it (or the host removes it); nothing
outside the mounted storage survives it.

**Image catalog** — `/apps/user_pods/catalog` lists the images by category for
everyone, logged in or not, each with a link that starts it (after login).

### Run this notebook

A public notebook (a catalogue entry, or a public link to an `.ipynb` file)
can be run with one click. The link is
`/index.php/apps/user_pods/run?token=<share token>[&path=<file in a shared folder>]`.

**Logged in:** the notebook is copied to the user's `Notebooks/` folder (an
existing file of the same name is used as it is, not overwritten), a
notebook server is started with an image whose kernel matches the
notebook's (`metadata.kernelspec.name`), and the browser is sent to the
notebook once the server is up — typically within a minute, longer the first
time an image is used on a machine. If a server for that image is already
running, the notebook opens there. Work is saved in the user's files.

A notebook already in the user's files is run with `run?file=<path>`; the
notebook viewer (files_viewers) offers this as **Open in Jupyter**.

**Not logged in (guest notebooks, if enabled):** the visitor gets a
throwaway notebook server:

- no access to anyone's files; the notebook is fetched from its public link
  into a local work directory;
- **nothing is saved** — the page says so and offers to log in instead;
- the server only accepts connections from the visitor's address, and each
  address gets one guest server at a time (a second run returns the first);
- it is **deleted a fixed time after it started** (the guest pod lifetime,
  default 60 minutes), whether in use or not.

Notebooks open "Not trusted" in Jupyter (they were signed on another server):
saved JavaScript output is not run until the user runs the cell or trusts the
notebook. This is Jupyter's own protection and is deliberately left on.

## Guest pod clean-up

Guest servers are tracked in the table `user_pods_guests`. The background job
`OCA\UserPods\BackgroundJob\CullGuestPods` runs every 5 minutes (it needs
Nextcloud's background jobs, preferably in cron mode) and

- deletes every guest server whose lifetime has passed, through the host's
  `delete_pod` (so the host also drops its proxy and address files);
- forgets rows whose server was removed by other means (checked once a
  server is more than 10 minutes old).

A guest server is therefore gone at most about lifetime + 5 minutes after it
started. Check that the job is registered with

```
occ background-job:list --class='OCA\UserPods\BackgroundJob\CullGuestPods'
```

It is registered when the app is installed or upgraded. Setting
`installed_version` by hand instead of running `occ upgrade` skips that step;
disabling and re-enabling the app registers it.

## Administration

Settings are under **Administration settings → Containers** and are stored
per instance (each node of a multi-node installation has its own).

| Setting (app config key) | Default | Meaning |
|---|---|---|
| Management IP (`podManagementIP`) | — | Private address of the Kubernetes host service (its `run_pod.php`, `get_containers.php`, … endpoints). |
| Public hostname (`publicIP`) | — | Public host name under which containers are reached (used to build their addresses). |
| Storage directory (`storageDir`) | — | Directory exposed to containers as `/storage`. |
| Pods per user (`maxPodsPerUser`) | 5 | Containers one user may run at the same time; 0 = no limit. |
| Users without a pod limit (`unlimitedPodUsers`) | — | Comma-separated account names (service accounts). Administrators are always exempt. |
| Guest notebooks (`guestPods`) | off | Allow visitors who are not logged in to run public notebooks in throwaway servers. |
| Guest pod lifetime (`guestPodLifetime`) | 60 | Minutes from start until a guest server is deleted. |
| Guest pods at the same time (`guestMaxPods`) | 10 | Ceiling for the whole service; one per visitor address on top of that. |
| Guest pod type (`guestPodType`) | medium | Size of guest servers. |
| Manifest library — listing API (`manifestsURL`) | GitHub contents API of pod_manifests | Where the list of image manifests is read. |
| Manifest library — raw file base (`rawManifestsURL`) | raw.githubusercontent.com/…/pod_manifests/main/ | Base URL of the manifest `.yaml` files and their `.md` descriptions. |

Further app config keys without a form field: `getContainersPassword` (sent
to the host's `get_containers.php` when set).

Requests to the host service go to a private address; the app opts in to
local addresses per request, so Nextcloud's instance-wide
`allow_local_remote_servers` is not needed.

## Requirements

- Nextcloud 28–35, PHP 8.2+ with the `yaml` extension.
- A running Kubernetes host service (sciencedata_kubernetes) reachable from
  the Nextcloud server, and a manifest library.
- Notebook images that report the notebook's address with its access token
  (the jupyter_sciencedata images do) — the app waits for the token before
  sending anyone to a server.
- Optional: user_group_admin (group-restricted images), files_sharding
  (multi-node installations: "Run this notebook" links point at the master,
  which sends logged-in users on to their own node), files_viewers
  (**Open in Jupyter** in the notebook viewer).

## API (app routes, under `/index.php/apps/user_pods`)

| Route | Method | Purpose |
|---|---|---|
| `/` | GET | The Containers page. |
| `/catalog` | GET | Public image catalog. |
| `/run` | GET | Run a notebook (`token`, `path`, `name`, or `file`); public for guests when enabled. |
| `/guest/status?gt=` | GET | State of a guest server, polled by the guest waiting page (public, rate-limited). |
| `/api/containers` | GET | The user's containers. |
| `/api/catalog` | GET | Images with title, category, kernels, and whether the user may launch them. |
| `/api/manifest?yaml=` | GET | One manifest: description, form fields, pod types. |
| `/api/pod` | POST | Create a container. |
| `/api/run-notebook` | POST | Start a server for a notebook: `file` in the user's files, or `token`/`path`/`name` of a public link. |
| `/api/pod/delete` | POST | Delete a container (`pod`). |
| `/api/pod/allowed-ips` | POST | Change a container's allowed addresses. |
| `/api/pod/ports` | POST | Change a container's extra ports. |
| `/api/pod/logs` | GET | A container's log. |

POST routes need the CSRF token (or, for scripts using basic auth, the
`OCS-APIRequest: true` header).

## Authors

Frederik Orellana (DTU), Ioanna Psylla. Licence: AGPL.
