# WPCV

A WordPress plugin scaffold with ZIP packaging and GitHub Actions deployment to an existing **CapRover WordPress app**, over SSH and Docker. Requires WordPress 6.5+ and PHP 8.2+.

## Plugin development

- `plugin/wpcv/wpcv.php`: plugin metadata, constants, and lifecycle registration.
- `plugin/wpcv/includes/class-plugin.php`: application hooks and an administrator-only **Tools > WPCV** status page.
- `scripts/build.py`: packages `plugin/wpcv/` into `dist/wpcv.zip`; repository files and deployment credentials stay outside the package.
- `.github/workflows/deploy.yml`: validates pull requests; validates and deploys pushes to `main` and manual runs on `main`.

Add functionality inside `plugin/wpcv/`. Keep version numbers in the PHP header, `WPCV_VERSION`, and `readme.txt` in sync. Activation hooks are for initial setup; future database upgrades should use versioned migrations that also run during updates.

Build locally with Python 3 (no dependencies):

```sh
python scripts/build.py
```

Upload `dist/wpcv.zip` in **Plugins > Add New Plugin > Upload Plugin**, or copy `plugin/wpcv/` into your local WordPress `wp-content/plugins/` directory and activate it. The plugin currently stores no data and needs no uninstall cleanup.

## CapRover setup

The deployment targets a single-replica WordPress service on a Linux CapRover Docker Swarm manager. The SSH host must also be the node running WordPress. The host needs Bash, Docker, and SSH/SFTP; the WordPress container needs PHP CLI, `id`, `mktemp`, and ZIP extraction support. WP-CLI is downloaded at a pinned version with a verified SHA-256 checksum and used temporarily inside the existing container. No permanent image changes or host PHP installation are needed.

1. Create a dedicated SSH key for GitHub Actions, for example `ssh-keygen -t ed25519 -C github-actions-wpcv -f ./wpcv-deploy -N ""`. Store this key outside the repository. This workflow expects an unencrypted private key.
2. Add the public key to the SSH account's `~/.ssh/authorized_keys` on the server. Allow SSH connections from the GitHub-hosted runner, or use a self-hosted runner with network access to the box.
3. Allow the SSH account to use Docker, either directly or through passwordless `sudo -n docker`. Docker access is administrative access to the host; use a dedicated, trusted deployment credential.
4. In CapRover, confirm the WordPress app has a persistent directory covering `/var/www/html`, `/var/www/html/wp-content`, or `/var/www/html/wp-content/plugins`. Confirm your actual container paths. The script refuses to install unless the resolved plugin directory is covered by an explicitly configured writable volume or bind mount in the Docker service. An image's implicit anonymous volume is insufficient. See [CapRover persistent apps](https://caprover.com/docs/persistent-apps).
5. Find the **physical Docker service name** and container filesystem user. Current CapRover apps may use the app name; older installations may retain a `srv-captain--` prefix. Use the exact name shown by `docker service ls`, rather than guessing it. See [CapRover troubleshooting](https://caprover.com/docs/troubleshooting.html).

Inspect the service on the server (prefix commands with `sudo -n` if required):

```sh
docker service ls
docker service inspect YOUR_WORDPRESS_SERVICE --format '{{json .Spec.TaskTemplate.ContainerSpec.Mounts}}'
docker ps --filter label=com.docker.swarm.service.name=YOUR_WORDPRESS_SERVICE
```

WP-CLI executes as `www-data` by default **inside the WordPress container**, retaining its PHP extensions, database environment, and network. Set `WP_RUN_USER` if the image uses a different non-root user. That user needs write access to the plugin directory and WordPress upgrade workspace. If WordPress forces FTP or disables file modifications, configure direct filesystem access and allow plugin updates before deploying. Avoid concurrent CapRover app redeployments while a plugin installation is running.

## GitHub configuration

Create a GitHub repository, push this checkout with `main` as the default branch, and create an environment named **production** under **Settings > Environments**. Add the following environment secrets and variables there. Repository-level Actions secrets and variables also work.

### Secrets

| Name | Value |
| --- | --- |
| `VM_HOST` | CapRover host IPv4 address or DNS hostname, without `ssh://` or a port. |
| `VM_USER` | SSH login username. |
| `VM_SSH_KEY` | Complete private key, including header/footer and line breaks. |
| `VM_KNOWN_HOSTS` | Verified OpenSSH `known_hosts` entry for this server. |

Collect the host key from a trusted machine, for example:

```sh
ssh-keyscan -H -p 22 YOUR_SERVER > known_hosts
ssh-keygen -lf known_hosts
```

Verify the fingerprint against the server's host key through the DigitalOcean console or an existing trusted connection before saving the file contents as `VM_KNOWN_HOSTS`. On the server, `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` displays its Ed25519 fingerprint. For a nonstandard SSH port, scan that port instead. The workflow requires a matching host key and never disables host verification.

### Variables

| Name | Value |
| --- | --- |
| `CAPROVER_SERVICE` (**required**) | Exact WordPress Docker service name from `docker service ls`, e.g. `my-wordpress` or `srv-captain--my-wordpress`. Select WordPress, not its database service. |
| `WP_PATH` | WordPress root **inside the container**; defaults to `/var/www/html`. |
| `WP_RUN_USER` | Non-root user **inside the container**; defaults to `www-data`. |
| `VM_PORT` | SSH port; defaults to `22`. |

No DigitalOcean API token, CapRover password/app token, or WordPress password is needed. This updates the existing app's persistent plugin files rather than deploying a new WordPress image through CapRover. The standard [WordPress REST plugin endpoint](https://developer.wordpress.org/rest-api/reference/plugins/) installs WordPress.org slugs rather than accepting arbitrary private ZIP uploads, so this workflow uses SSH and [WP-CLI ZIP installation](https://developer.wordpress.org/cli/commands/plugin/install/).

## Deployment and recovery

Push to `main`, or select **Actions > Validate and deploy WordPress plugin > Run workflow** on `main`. Pull requests only validate and package; they receive no deployment credentials. Deployments are serialized and a running deployment is not cancelled by a newer push.

The workflow checks PHP syntax on PHP 8.2, checks shell scripts, builds a ZIP, and uploads it alongside a checksum-verified WP-CLI PHAR to a unique host temporary directory. It discovers the container by its Swarm service label, verifies a single replica and plugin persistence, then uses `docker exec` to install/replace and activate `wpcv`. It checks activation and prints the installed version. Host/container staging files and runner key files are cleaned up on normal completion or failure. A hard runner termination can leave non-secret staging files in `/tmp/wpcv-deploy.*` on the host or inside the container.

Confirm **Tools > WPCV** in WordPress after the first deployment. This scaffold targets a single-site WordPress installation; multisite deployment needs explicit site/network activation settings.

Deployment replaces plugin code in place. It does not provide atomic swaps, automatic rollback, database backups, or an HTTP health check. To restore an earlier version, revert the relevant commit on `main` and push it; the workflow reinstalls that code. Database changes introduced by future plugin features need their own recovery plan. Keep persistent data in the WordPress database or uploads directory because plugin files are replaced during updates.

The repository is initialized locally without a remote or an initial commit. After configuring GitHub and the server:

```sh
git add .
git commit -m "Initialize WPCV plugin and deployment workflow"
git remote add origin YOUR_GITHUB_REPOSITORY_URL
git push -u origin main
```
