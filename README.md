<p align="center">
	<a href="license.txt" target="_blank">
		<img src="https://img.shields.io/badge/license-GPL--2.0%2B-red.svg" alt="Licence">
	</a>
	<img src="https://img.shields.io/badge/WordPress-6.8+-blue.svg" alt="WordPress 6.8+">
	<img src="https://img.shields.io/badge/PHP-7.4+-777BB4.svg" alt="PHP 7.4+">
	<img src="https://img.shields.io/badge/version-1.0.0-brightgreen.svg" alt="Version 1.0.0">
	<a href="https://github.com/seb86/modal-kit-plugin">
		<img src="https://img.shields.io/github/stars/seb86/modal-kit-plugin?style=social" alt="GitHub Stars">
	</a>
</p>

# Modal Kit

**Modals made of blocks.** Popups, slide-ins, bars, drawers, full-screen takeovers, bottom sheets, inline panels and a video lightbox, made in the WordPress block editor with the blocks you already know.

Modal Kit adds one block, Modal. Everything inside it is a core block, such as Group, Heading, Image, Cover and Buttons, so there's no new builder to learn. Modals pick up your theme's colors, fonts and spacing, and the editor shows each one over your own homepage, exactly as visitors will see it.

Every feature is free to use, with nothing locked or time-limited.

Requires WordPress 6.8+ and PHP 7.4+.

## What you get

- **Nine layouts.** Centered dialog, slide-in corner, top or bottom bar, full-screen takeover, side drawer, split image and content, bottom sheet, inline panel and video lightbox. Each one adapts to phones by itself.
- **Six styles.** Native, Soft and Editorial follow your theme. Fieldnote, Harbor and Night bring their own look, which stays the same in any theme.
- **Opens when you want.** From any link or button, after a delay, at a scroll depth, when a visitor is about to leave, after time on the site, after inactivity, or when a block scrolls into view.
- **Polite by default.** Choose the pages, how often a modal shows, which devices and dates, and how many automatic modals a page can show.
- **Private.** No cookies and nothing personal. What a visitor has already seen stays in their own browser.
- **Accessible.** Built on the native `<dialog>` element: focus moves into the modal and back out, Esc closes it, tap targets are at least 44px, and reduced motion gets a fade.
- **Works on static sites.** Opening and closing a modal never needs WordPress, so sites exported with Simply Static keep working.

The full feature list is in [readme.txt](readme.txt).

## Installing

> [!NOTE]
> Modal Kit isn't on WordPress.org: you download it from ModalKitWP.com, and it updates from there.

1. Download the zip from [modalkitwp.com](https://modalkitwp.com/) (or [Releases](https://github.com/seb86/modal-kit-plugin/releases)).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, then activate it.

## Updates

Updates come from modalkitwp.com's package server (`packages.cocartheadless.com`) and need no license key. They show in **Dashboard → Updates** like any other plugin's. Under **Modal Kit → Settings → Updates** you can install them automatically, choose a release channel (stable, release candidate, beta or nightly), pause them for up to 12 weeks, or roll back to an earlier version.

Every update is signed, and Modal Kit refuses to install one whose signature doesn't check out. If your host blocks outgoing requests, allow `packages.cocartheadless.com`. On a network, updates are managed from the main site.

## Your first modal

1. When you activate Modal Kit, a short setup (four steps, about two minutes) picks a starting look and sensible defaults, then opens your first modal in the editor. You can skip it and run it later from **Modal Kit → Settings → Tools**.
2. To add another modal, go to **Modal Kit → Add New Modal** and choose a starter.
3. Publish it, then link to it from any button. Its address, such as `#modal-autumn-sale`, is in the **Triggers** panel.

To check how often a modal shows, use **Reset "already seen"** in **Settings → Tools**, or add `?modal=preview` to a page to see every modal there (editors only).

## Your theme

Modal Kit works with block themes and classic themes. If you build themes and want to check that the fixed styles look right in yours, see [Testing Modal Kit with your theme](THEME-TESTING.md).

## Feedback and support

If something doesn't work as you expect, or you have an idea, please use the [support form](https://modalkitwp.com/support/). It helps to include your WordPress version, your theme and the steps that show the problem.

## Contributing

To work on the code, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

Modal Kit is [GPL-2.0-or-later](license.txt). The bundled fonts are under the SIL Open Font License 1.1, and their licences are in [`assets/fonts`](assets/fonts).
