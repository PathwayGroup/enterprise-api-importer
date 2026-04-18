THIRD-PARTY-LICENSES

Date: 2026-04-18

Purpose

This file lists third-party packages bundled with the packaged plugin release (the `vendor/` directory). For each package include name, installed version, declared license, source URL, and a short note about GPL compatibility.

Instructions

1. When preparing the distributed ZIP, populate the table below with exact versions and license text/links (you can extract values from `composer.lock` or run `composer show --installed` inside the packaged directory).
2. Confirm each package's license is GPL‑compatible. If any bundled package is not GPL‑compatible, remove it or replace with a GPL‑compatible alternative and document the change.

Suggested verification commands (run inside the packaged plugin dir):

- composer show --installed
- jq '.packages[] | {name: .name, version: .version, license: .license, homepage: .homepage}' composer.lock

Bundled packages (fill in):

| Package | Version | License | Source / Link | GPL-compatible? | Notes |
|---------|---------|---------|---------------|-----------------|-------|
| twig/twig | 3.24 (example) | (fill) | https://github.com/twigphp/Twig | (yes/no) | Twig is typically BSD/MIT-style; verify compatibility |
| symfony/polyfill-mbstring | (fill) | (fill) | https://github.com/symfony/polyfill-mbstring | (yes/no) | Runtime polyfill used by Twig/symfony components |
| symfony/deprecation-contracts | (fill) | (fill) | https://github.com/symfony/deprecation-contracts | (yes/no) | Dev/runtime — include only if bundled in vendor/ for runtime |

If there are additional bundled packages in `vendor/`, append rows for each with the same columns.

Attribution / License copies

- Include a short note here confirming that full license texts for bundled libraries are included or linked in the packaged ZIP (recommended: include each bundled library's license file under `licenses/` in the package).

Example entry to include in packaged zip:

licenses/twig-Twig-3.24-LICENSE.txt  — copy of the upstream license

Contact

If you're unsure about a package's compatibility with the WordPress.org guidelines, open an issue or ask for a license review before publishing.