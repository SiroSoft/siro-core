# Migration Guide

## Upgrading to v0.16.1

### Requirements

- PHP 8.2+
- PDO extension
- JSON extension
- Mbstring extension

### What's New

- **536 tests** with 100% pass rate
- Improved Mail fake system (stores full structure)
- HTTP tests skipped by default (require external network)

### Breaking Changes

None. v0.16.1 is fully backward compatible.

### From v0.15.x to v0.16.1

1. Update composer:
   ```bash
   composer update sirosoft/core
   ```

2. Clear any cached config:
   ```bash
   php siro optimize
   ```

3. Run tests to verify:
   ```bash
   php siro test
   ```

### v1.0 Roadmap

The framework is approaching v1.0. To prepare:

1. **Review Security.md** - Ensure your usage aligns with security best practices
2. **Update tests** - Add tests for your custom controllers/services
3. **Document your API** - Use `php siro make:openapi` to generate OpenAPI spec

### Deprecation Notices

None at this time.

### Known Issues

- HTTP tests require external network/SSL connectivity and are skipped by default
- PHPStan runs with 512MB memory limit due to better-reflection complexity

### Getting Help

- Documentation: [README.md](README.md)
- Security: [SECURITY.md](SECURITY.md)
- Issues: https://github.com/SiroSoft/siro-core/issues