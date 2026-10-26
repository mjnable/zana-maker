# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.2.0 — 2026-10-26

### Added

- `make:crud`: back-office screens for a content type, in one or every locale (`--translatable`):
  list, create, edit, publish, archive, delete after confirming, and a signed preview link
  (FE-32).
- `make:page`: a localised page with its routes, metadata and template (FE-16).
- `--web` on `make:module` and `make:controller`: HTML controllers and templates (FE-16).

## 2.0.0 — 2026-10-26

The final 2.0 release. No changes since 2.0.0-rc.1.

## 2.0.0-beta.3 — 2026-10-25

### Added

- Generated code is wired into the module class (`// zana:maker services` and `// zana:maker boot`
  markers); `Generator::manualSteps()` lists what could not be wired, and the commands print it.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Code generators that follow the Zana module layout. Conventions produce plain, explicit code you own, not runtime magic. Requires PHP 8.5 or later.

Documentation: [the maker guide](https://github.com/mjnable/zana-docs/blob/main/guides/maker.md).
