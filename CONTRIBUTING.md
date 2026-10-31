# Contributing to Insightistic

Insightistic is a WordPress plugin maintained by WordPressistic. Contributions should preserve WordPress compatibility, security, accessibility, translation support, and the GPL-2.0-or-later license.

## Local checks

Install the development dependencies and run the same local gates used before publication:

```bash
composer install --prefer-dist --no-interaction
npm install --no-audit --no-fund
composer release-check
```

Do not commit generated dependency directories, secrets, source maps, local build output, or production ZIPs to the source tree. Use the release process in [`docs/RELEASING.md`](docs/RELEASING.md) for public assets.

## WordPress standards

- Sanitize input and escape output.
- Protect admin and AJAX mutations with capability checks and nonces.
- Use the `insightistic` text domain for translatable strings.
- Keep external service calls opt-in and documented in `readme.txt`.
- Preserve the plugin's minimum requirements: WordPress 5.6+ and PHP 8.0+.

