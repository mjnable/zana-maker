# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.0.0-beta.3 — 2026-10-25

### Added

- Generated code is wired into the module class (`// zana:maker services` and `// zana:maker boot`
  markers); `Generator::manualSteps()` lists what could not be wired, and the commands print it.

## 2.0.0-alpha.3 — 2026-10-24

First release of the 2.0 line. Code generators that follow the Zana module layout. Conventions produce plain, explicit code you own, not runtime magic. Requires PHP 8.5 or later.

Documentation: [the maker guide](https://github.com/mjnable/zana-docs/blob/main/guides/maker.md).
