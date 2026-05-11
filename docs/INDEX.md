# Siro Core Documentation Index

Welcome to the Siro Core documentation! This index helps you find the right guide for your needs.

---

## 📚 Available Documentation

### Core Guides
- **[Architecture Decisions](ARCHITECTURE.md)** — Design choices and ADRs
- **[Security Guide](SECURITY.md)** — JWT, encryption, best practices
- **[Performance Optimization](PERFORMANCE.md)** — Benchmarking and tuning
- **[Documentation Summary](DOCUMENTATION_SUMMARY.md)** — Overview of all docs

### API References
- **[Router API](api/Router.md)** — HTTP routing and middleware

### Quick Links
- **[README](../README.md)** — Overview, install, quick start
- **[SiroPHP Application Skeleton](https://github.com/SiroSoft/SiroPHP)** — Full project with auth, CRUD, CLI
- **[Website](https://sirophp.com)** — Official website with tutorials and examples

---

## 🛠️ CLI Commands

Full command list: `php siro list`

### Code Generation
```bash
php siro make:crud products      # Full CRUD scaffold
php siro make:model User         # Generate model
php siro make:controller User    # Generate controller
php siro make:migration create_users_table
php siro make:auth               # Full auth system
php siro make:middleware Log     # Generate middleware
```

### Database
```bash
php siro migrate                 # Run migrations
php siro migrate:status          # Check migration status
php siro db:show users           # Inspect table
```

### Testing & Debugging
```bash
php siro test                    # Run all tests
php siro why                     # Debug last request
php siro api:test GET /api/users # Test endpoint
php siro log:trace <id>          # View trace
php siro log:tail                # Tail request logs
```

### Performance & Config
```bash
php siro benchmark               # Run benchmarks
php siro optimize                # Cache for production
php siro env:check               # Validate environment
php siro doctor                  # System health check
```

### Deployment
```bash
php siro deploy                  # Deploy application
php siro down                    # Maintenance mode
php siro up                      # Disable maintenance mode
```

---

## 🔗 External Resources

- **GitHub:** https://github.com/SiroSoft/siro-core
- **Packagist:** https://packagist.org/packages/sirosoft/core
- **Website:** https://sirophp.com
- **SiroPHP Skeleton:** https://github.com/SiroSoft/SiroPHP

---

*Last updated: May 11, 2026*  
*Documentation version: 1.0*
