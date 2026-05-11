# ⚡ Siro Framework

**The Fastest PHP Micro-Framework** — 4MB RAM, built-in JWT auth, API keys, rate limiting, CRUD generator, and **0 PHPStan errors**.

```bash
composer create-project sirosoft/api my-api
cd my-api
cp .env.example .env
php siro key:generate
php siro migrate
php siro serve
# 🚀 API ready at http://localhost:8080
```

## Why Siro?

| Feature | Siro | Laravel | Slim |
|---------|:----:|:-------:|:----:|
| ⚡ Performance | **328K req/s** | ~50K req/s | ~100K req/s |
| 💾 Memory | **4MB** | 12MB | 2MB |
| 📦 Dependencies | **3** | 50+ | 2 |
| 🔐 JWT Auth | **Built-in** | 3rd party | 3rd party |
| 🔑 API Keys | **Built-in** | ❌ | ❌ |
| 🛡️ Rate Limiting | **Built-in** | ✅ | ❌ |
| 🔄 Idempotency | **Built-in** | ❌ | ❌ |
| 🏗️ CRUD Generator | **✅** | ❌ | ❌ |
| 🧪 Tests | **1,294** | ~5,000 | ~500 |
| 🎯 PHPStan | **0 errors** | varies | varies |

## Repositories

- [**siro-core**](https://github.com/SiroSoft/siro-core) — Framework core (PHP library)
- [**SiroPHP**](https://github.com/SiroSoft/SiroPHP) — Application skeleton with CRUD, auth, CLI

## Quick Links

- [🌐 Website](https://sirophp.com)
- [📦 Packagist: sirosoft/core](https://packagist.org/packages/sirosoft/core)
- [📦 Packagist: sirosoft/api](https://packagist.org/packages/sirosoft/api)
- [📖 Documentation](https://github.com/SiroSoft/siro-core#readme)
- [🐛 Report Issue](https://github.com/SiroSoft/siro-core/issues)

## Stats

![CI](https://github.com/SiroSoft/siro-core/actions/workflows/test.yml/badge.svg)
![PHPStan](https://img.shields.io/badge/phpstan-level%207-0%20errors-brightgreen)
![License](https://img.shields.io/badge/license-MIT-blue)
