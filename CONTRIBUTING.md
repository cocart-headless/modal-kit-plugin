# Contributing to Modal Kit

Thanks for helping. This guide covers setting up the code, the checks to run, and what a change needs before it can go in. For what the plugin is and how to use it, see [README.md](README.md).

## Reporting a problem

Use the [support form](https://modalkitwp.com/support/). Include your WordPress version, your theme and the steps that show the problem.

If you find a security problem, report it through the same form rather than in a public issue or pull request, so it can be fixed before it's known.

## Setup

You need Node 22.22+ or 24.15+, PHP 7.4+, Composer, and two local WordPress sites (6.8 or later):

- a **dev site** to work in;
- a **test site** that the end-to-end tests can change freely. They switch themes and delete modals, so don't use a site you care about.

```
cp .env.example .env     # then set your site paths and addresses
npm install
composer install
npm run env:start        # link the plugin into both sites, activate it, check they respond
npm run start            # build and watch
```

The tooling is set up for [Laragon](https://laragon.org/) on Windows. With another setup, set `LARAGON_PATH` to a folder that has WP-CLI at `bin/wp-cli/wp-cli.phar`, and `PHP_BINARY` to your PHP.

The PHP tests use the WordPress test library. Point `WP_TESTS_DIR` in `.env` at a copy of it, with its own `wp-tests-config.php` and an empty database.

The files in `build/` are compiled from `src/` and aren't in the repo. Run `npm run build` once, or keep `npm run start` running.

## Commands

| Command | What it does |
|---|---|
| `npm run build` | Compile the plugin |
| `npm run start` | Compile and watch |
| `npm run env:wp -- <cmd>` | Run WP-CLI on the dev site (add `--tests` for the test site) |
| `npm run format` | Format the JavaScript |
| `npm run lint` | Lint JavaScript, styles and PHP (WordPress Coding Standards) |
| `npm run lint:phpstan` | PHPStan |
| `npm run test:unit` | Vitest and PHPUnit |
| `npm run test:e2e` | Playwright on the test site, with axe accessibility checks |
| `npm run test:themes` | Compare the fixed styles across free themes (about 25 minutes) |

Run the end-to-end commands one at a time: they share the test site.

## Before you open a pull request

- Run `npm run lint` and `npm run test:unit`, and `npm run test:e2e` if you changed anything a visitor or editor sees.
- If you changed `src/styles/` or `src/blocks/modal/style.scss`, run `npm run test:themes` too. [THEME-TESTING.md](THEME-TESTING.md) explains its report.
- Add or update tests for what you changed.
- Keep a pull request to one change, and describe what it fixes or adds and how you checked it.

## How the plugin is built

A change needs to keep these true:

- **One block.** Modal Kit adds one block, `modal-kit/modal`, and everything inside it is a core block.
- **The editor matches the site.** The canvas and the front end share one `style.scss`. `editor.scss` is only for the editor's own controls.
- **Works on static sites.** Opening, showing and closing a modal never needs a request to WordPress. Time, device and visitor rules run in the browser, and state goes in `localStorage`, never cookies.
- **Built on the platform.** Use the native `<dialog>` element and the Interactivity API. Don't add jQuery or third-party UI libraries.
- **Accessible.** Focus moves to the heading when a modal opens and back to the trigger when it closes. The page behind is inert, tap targets are at least 44px, and reduced motion gets a fade only.
- **Styled by the theme.** Use `theme.json` preset slugs, never hard-coded colours, in styles and patterns.
- **WordPress standards.** Use the official `@wordpress/*` packages for editor and admin screens. In PHP, check capabilities, verify nonces, sanitise input and escape output. Make every string people see translatable, with the text domain `modal-kit`.

PHP code uses the `ModalKit\` namespace, and functions and options are prefixed `modal_kit_`.

## License

By contributing, you agree that your work is released under the plugin's license, [GPL-2.0-or-later](license.txt).
