# GitHub Actions Workflows

## `build-zip.yml` — Build Plugin Zip

This workflow builds a correctly structured, installable WordPress plugin zip for **WG Vault**.

### What it does

1. **Copies plugin files** into a `wg-vault/` subdirectory so the zip extracts to the right folder name when installed via WordPress.
2. **Creates a zip archive** — either `wg-vault-{tag}.zip` for tagged releases or `wg-vault.zip` for branch pushes.
3. **Uploads the zip as a workflow artifact** (retained for 30 days) so it can be downloaded from the Actions tab at any time.
4. **On version tags only:** automatically creates a GitHub Release and attaches the zip as a release asset.

The following paths are excluded from the zip:

- `.git/`
- `.github/`
- `.gitignore`
- `*.zip`
- `node_modules/`

---

### Triggers

| Event | Behavior |
|---|---|
| Push to `main` | Builds `wg-vault.zip` and uploads it as an artifact |
| Push of a `v*.*.*` tag | Builds `wg-vault-{tag}.zip`, uploads artifact, and creates a GitHub Release |
| Manual (`workflow_dispatch`) | Builds `wg-vault.zip` and uploads it as an artifact |

---

### How to trigger a versioned release

1. Commit and push all changes to `main`.
2. Tag the commit with a version number following [semver](https://semver.org/):

```bash
git tag v1.0.1
git push origin v1.0.1
```

The workflow will automatically:
- Build `wg-vault-v1.0.1.zip`
- Create a GitHub Release titled `v1.0.1`
- Attach the zip as a downloadable release asset

---

### Where to find the built zip

**From the Actions tab (any build):**

1. Go to the repository on GitHub.
2. Click the **Actions** tab.
3. Select the **Build Plugin Zip** workflow run.
4. Scroll to the **Artifacts** section at the bottom of the run summary.
5. Download **wg-vault-zip**.

**From a GitHub Release (tagged builds only):**

1. Go to the repository on GitHub.
2. Click **Releases** in the right sidebar.
3. Find the release for your tag (e.g. `v1.0.1`).
4. Download the zip from the **Assets** section.

---

### Installing the zip in WordPress

1. Download the zip (from Artifacts or Releases).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Select the zip and click **Install Now**.
4. The plugin will install into `wp-content/plugins/wg-vault/` with the correct folder name.
