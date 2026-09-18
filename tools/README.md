# Reusable Tools

This directory contains local and release tooling that is intentionally kept
outside the Core runtime and PHPUnit test suite.

## Directories

- `diagnostics/`: test-run diagnostics and coverage helpers.
- `release/`: PHAR and release preparation helpers.
- `soak/`: load, queue, Redis, and long-running environment helpers.

The stable Composer and CI tools remain under `scripts/` for compatibility
with existing release commands. New one-off or reusable operational tools
should be added here rather than inside `src/` or `tests/`.

## Release Commands

```bash
php tools/release/release-hygiene.php
php tools/release/build-phar.php
composer release:check
```

The hygiene gate rejects tracked environment files, logs, databases, PHARs,
coverage output, rate-limit state, and tool-generated result directories.
