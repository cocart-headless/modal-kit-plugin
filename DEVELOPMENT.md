# Developing Modal Kit

For what the plugin is and how to use it, see [README.md](README.md). To check the fixed styles in a theme, see [THEME-TESTING.md](THEME-TESTING.md).

## Setup

You need Node 22.22+ or 24.15+, Composer, and two local WordPress sites: a dev site, and a test site that the end-to-end suites can change freely. The tooling expects [Laragon](https://laragon.org/) on Windows, with the dev site at `http://localhost:8081` and the test site at `http://localhost:8082`.

```
cp .env.example .env     # then set your paths and site addresses
npm install
composer install
npm run env:start        # link the plugin into the local sites, activate it, check they respond
npm run start            # build and watch
```

The compiled files in `build/` come from `src/`. Build them once with `npm run build`, which writes the style tokens first.

## Commands

| Command | What it does |
|---|---|
| `npm run env:start` | Link the plugin into the local sites and activate it |
| `npm run env:wp -- <cmd>` | Run WP-CLI on the dev site (add `--tests` for the test site) |
| `npm run build` | Compile the plugin |
| `npm run start` | Compile and watch |
| `npm run demos` | Make a demo page for every modal on the dev site |
| `npm run format` | Format the JavaScript |
| `npm run lint` | JavaScript, styles and PHP |
| `npm run lint:phpstan` | PHPStan |
| `npm run i18n:pot` | Update `languages/modal-kit.pot` |

## Testing

| Command | What it runs |
|---|---|
| `npm run test:js` | Vitest (`src/`, `tests/js/`) |
| `npm run test:php` | PHPUnit, using the WordPress test library in `WP_TESTS_DIR` |
| `npm run test:unit` | Both of the above |
| `npm run test:multisite` | PHPUnit with WordPress running as a network |
| `npm run test:e2e` | Playwright on the test site, including axe checks and screenshot comparisons |
| `npm run test:browsers` | The front-end specs on Firefox, WebKit and two emulated phones (install them once with `npx playwright install firefox webkit`) |
| `npm run test:static` | Export the test site with Simply Static, then run `tests/static` against the output |
| `npm run test:themes` | Compare the fixed styles across free themes, and write a report to `artifacts/themes/` |

Run `npm run lint` and `npm run test:unit` before finishing a change. Screenshot comparisons run locally only, because the baselines are taken on Windows and GitHub runs Linux, so also run `npm run test:e2e` before tagging, and `npm run test:browsers` when the runtime or the modal's styles change. Emulated phones aren't real ones: check a release on an actual iPhone, and with a screen reader.

`npm run test:themes` takes about 25 minutes, so run it when `src/styles/` or `src/blocks/modal/style.scss` changes, and before tagging. It compares the themes in `tests/themes/themes.json` with Twenty Twenty-Five, and [THEME-TESTING.md](THEME-TESTING.md) explains the report.

The end-to-end suites (`test:e2e`, `test:browsers`, `test:static` and `test:themes`, here or in Modal Kit Pro) share the test site. Run them one at a time: each switches themes and deletes modals, which makes another running suite fail.

## Releasing

`package.json` holds the version and requirements (`requires`, `requires_php`, `tested_up_to`). These commands copy them everywhere else:

| Command | What it does |
|---|---|
| `npm version 1.2.3` | Set the version in `package.json`, then update the plugin header and constant, `block.json`, the `.pot`, the requirements in `README.md` and the lockfile. It creates no commit or tag. |
| `npm run version` | Do the same copy after you edit `package.json` by hand |
| `npm run zip` | `version`, build, regenerate the `.pot` and write the zip |
| `node bin/sign-package.mjs --keygen` | Make the key pair for signing update packages |
| `node bin/sign-package.mjs <zip>` | Sign a package with `MODAL_KIT_SIGNING_KEY`: prints the signature and writes `<zip>.sig`. Refuses a key that doesn't match `Updater::PUBLIC_KEY` |

Zips are written to `releases/` as `modal-kit-<version>.zip`, replacing a zip of the same version, and Git ignores the folder. A zip holds only the paths under `files` in `package.json`, including `src/`, the source of the compiled code. Development files, such as `package.json` and `webpack.config.js`, stay out.

Modal Kit isn't on WordPress.org. `.wordpress-org/` still holds the banners, icons, screenshots and Live Preview blueprint made for its listing, which never go in the zip; `npm run wporg:screenshots` and `npm run wporg:blueprint` rebuild them.

To release:

1. Run `npm version 1.2.3` and add the entry to the changelog in `readme.txt`.
2. Run the checks above, then `npm run zip`.
3. Commit, then push the tag: `git tag v1.2.3 && git push origin v1.2.3`.

The release signs the zip and publishes it, with its `.sig`, as a GitHub release. The package server reads the releases from there, so sites see the update within about 12 hours (sooner with **Check again** on Dashboard → Updates). A tag with a hyphen, such as `v1.2.3-beta.1`, publishes a GitHub pre-release, which only sites on the matching release channel get.

## Updates

Modal Kit updates from the package server at `packages.cocartheadless.com`, which serves every CoCart Headless plugin. Its code is the `cocart-headless/package-provider-php` repo, not this one, and its entry for Modal Kit is free (`premium: false`), so no license key is needed. The plugin header's `Update URI: https://modalkitwp.com/` keeps WordPress from asking WordPress.org about the slug.

`includes/Updates/Updater.php` does the work, and plugins that build on Modal Kit register with it (`Updater::register()`): Modal Kit Pro does, with its license as the condition for packages. For each plugin it answers WordPress's update check (`update_plugins_modalkitwp.com`) and "View details", fetches a fresh download link at install time, checks the package's signature, and handles release channels, pausing, rollback, automatic updates, language packs and the notice after an update. Its state is in the plugin's own options (`modal_kit_update_settings`, `modal_kit_auto_update`, and the transients `modal_kit_update_info` and `modal_kit_just_updated`), removed by `uninstall.php`. `includes/Updates/Routes.php` registers Modal Kit and serves the **Updates** settings tab (`GET|POST /modal-kit/v1/updates`, `GET|POST /modal-kit/v1/updates/rollback`), for users who can update plugins on the site that manages updates (on a network, the main site).

The server address (`Updater::SERVER`), the package hosts and the public key are constants, with no filters. A filter would let a must-use plugin send an unmodified copy of the plugin to someone else's update server. To try a staging server, edit the constants in a local build; don't ship them changed. `modal_kit_pre_rollback` short-circuits a rollback; the e2e mu-plugin uses it because the test site's plugins are linked to this repo.

Every package must carry an Ed25519 signature made with the key in `Updater::PUBLIC_KEY`, the same key Modal Kit Pro's releases are signed with. The updater downloads the file, checks it and refuses to install it if the signature is missing or wrong, or if it can't get the update info to read the signature from. The private key lives only in the repository secret `MODAL_KIT_SIGNING_KEY` (in both repositories) and an offline backup, never on the package server. Changing the key works as in Modal Kit Pro's DEVELOPMENT.md ("Package signing"), with the new public key going in `Updater::PUBLIC_KEY` here.

## GitHub workflows

| Workflow | Runs on | What it does |
|---|---|---|
| `development.yml` | Part of a release, or by hand | Lint, PHPStan, Vitest and build |
| `testing.yml` | Part of a release, or by hand | PHPUnit (PHP 7.4 to 8.5, two WordPress versions), Playwright, static export and Plugin Check |
| `release.yml` | `v*` tags | Checks the tag matches the version, runs the two above, then builds the zip and publishes a GitHub release |

Only a `v*` tag runs these automatically, to save Actions minutes. Pushes and pull requests run nothing, so run the checks locally as you work. To try the suites on GitHub before tagging, start **Development** or **Testing** by hand from the Actions tab; that publishes nothing.

A tag runs the stages in order, each waiting for the one before: version check, Development, Testing, publish. If any stage fails, nothing is published. Fix the problem, delete the tag and push it again. Screenshots aren't part of this: they're compared only when `test:e2e` runs locally.

The release needs the repository secret `MODAL_KIT_SIGNING_KEY` (see Updates). For the one release that changes the signing key, set the variable `MODAL_KIT_PREVIOUS_PUBLIC_KEY` to the old public key.
