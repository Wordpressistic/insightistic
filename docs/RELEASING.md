# Insightistic release process

This repository uses a manual release process. GitHub Actions are intentionally not required or triggered for releases.

## Release checklist

1. Create a release branch from `main` using the `wpistic/` prefix.
2. Update the version in all contract files:
   - `insightistic.php` plugin header and `INSIGHTISTIC_VERSION`
   - `readme.txt` `Stable tag` and changelog
   - `README.md` badge and release table
   - `package.json` and `package-lock.json`
   - `languages/insightistic.pot`
3. Run the local release gate:

   ```bash
   composer install --prefer-dist --no-interaction
   npm install --no-audit --no-fund
   composer release-check
   node scripts/check-js.mjs
   ```

4. Run the official WordPress.org readme validator and the fresh WordPress acceptance suite.
5. Commit the verified release and create a dotted tag that exactly matches the plugin version:

   ```bash
   git tag -a insightistic.4.6.6 -m "Insightistic 4.6.6"
   git push origin main --follow-tags
   ```

6. Build from the tagged commit and confirm the generated files:

   ```bash
   php scripts/build-release.php
   php scripts/validate-zip.php dist/insightistic.4.6.6.zip
   Get-FileHash dist/insightistic.4.6.6.zip -Algorithm SHA256
   ```

7. Create a GitHub Release for the exact tag and attach:
   - `dist/insightistic.4.6.6.zip`
   - `dist/insightistic.4.6.6.zip.sha256`

8. Verify the downloaded GitHub asset against the checksum and confirm the ZIP contains one root directory named `insightistic/`.

## Release asset rules

- Never attach a GitHub source archive as the WordPress plugin ZIP.
- Never attach a ZIP built from an untagged or dirty working tree.
- The production ZIP must contain `insightistic.php`, `readme.txt`, `LICENSE`, runtime directories, assets, and translations only.
- Development tools, tests, Composer/npm dependencies, source maps, and repository metadata must stay out of the production ZIP.
- The release tag, plugin header, runtime constant, readme stable tag, package version, and translation project version must agree.

## Versioning

Use semantic-style versions (`MAJOR.MINOR.PATCH`) and increment the plugin version for every code release. The repository uses the existing dotted tag format `insightistic.X.Y.Z`; do not mix it with the older hyphenated format.

