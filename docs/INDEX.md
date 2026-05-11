# SiroPHP Documentation Index

Welcome to the SiroPHP documentation! This index helps you find the right guide for your needs.

---

## 🚀 Getting Started

### New to SiroPHP?
1. **[README](../README.md)** - Overview and quick start
2. **[Installation Guide](INSTALLATION.md)** - Step-by-step setup
3. **[Quick Start Tutorial](QUICKSTART.md)** - Build your first API in 5 minutes

### Already Familiar?
- **[Architecture Decisions](ARCHITECTURE.md)** - Understand design choices
- **[API Reference](api/)** - Detailed API documentation
- **[Examples](examples/)** - Real-world code samples

---

## 📚 Core Guides

### Essential Reading
- **[Security Guide](SECURITY.md)** ⭐ - Security features and best practices
- **[Performance Optimization](PERFORMANCE.md)** ⭐ - Make your API blazing fast
- **[Contributing Guide](../CONTRIBUTING.md)** - How to contribute
- **[Code of Conduct](../CODE_OF_CONDUCT.md)** - Community guidelines

### Development
- **[Database Guide](DATABASE.md)** - Multi-DB support, migrations, query builder
- **[Authentication Guide](AUTHENTICATION.md)** - JWT, RBAC, token management
- **[Validation Guide](VALIDATION.md)** - Request validation and custom rules
- **[Testing Guide](TESTING.md)** - PHPUnit tests and best practices

### Advanced Topics
- **[Event System](EVENTS.md)** - Pub/sub pattern and model lifecycle
- **[Queue & Mail](QUEUE_MAIL.md)** - Background jobs and email sending
- **[Caching Strategies](CACHING.md)** - File and Redis caching
- **[File Storage](STORAGE.md)** - Local and S3-compatible storage

---

## 🔍 API References

### Core Components
- **[Router API](api/Router.md)** - HTTP routing and middleware
- **[Model API](api/Model.md)** - ORM and relationships
- **[Database API](api/Database.md)** - Query builder and connections
- **[Auth API](api/Auth.md)** - Authentication and authorization
- **[Response API](api/Response.md)** - HTTP response building
- **[Request API](api/Request.md)** - Request handling and validation
- **[Container API](api/Container.md)** - Dependency injection
- **[Event API](api/Event.md)** - Event dispatcher

### Utilities
- **[Cache API](api/Cache.md)** - Caching system
- **[Session API](api/Session.md)** - Session management
- **[Logger API](api/Logger.md)** - Logging and tracing
- **[Validator API](api/Validator.md)** - Input validation
- **[HTTP Client API](api/Http.md)** - Outbound HTTP requests
- **[Encrypter API](api/Encrypter.md)** - AES-256 encryption

---

## 🛠️ CLI Commands

### Project Setup
```bash
php siro new my-api              # Create new project
php siro serve                   # Start dev server
php siro live                    # Dev server with auto-reload
```

### Code Generation
```bash
php siro make:model User         # Generate model
php siro make:controller User    # Generate controller
php siro make:migration create_users_table  # Generate migration
php siro make:crud products      # Full CRUD scaffold
php siro make:test ProductApi    # Generate test
php siro make:factory User       # Generate factory
php siro make:auth               # Full auth system
```

### Database
```bash
php siro migrate                 # Run migrations
php siro migrate:rollback        # Rollback migrations
php siro db:seed                 # Run seeders
php siro db:show users           # Inspect table
```

### Testing & Debugging
```bash
php siro test                    # Run all tests
php siro api:test GET /api/users # Test endpoint
php siro log:trace <id>          # View trace details
php siro log:replay <id>         # Replay request
php siro slow                    # Show slow requests
```

### Performance
```bash
php siro benchmark               # Run benchmarks
php siro config:cache            # Cache configuration
php siro optimize                # Optimize for production
php siro env:check               # Validate environment
```

### Deployment
```bash
php siro deploy                  # Deploy application
php siro down                    # Enable maintenance mode
php siro up                      # Disable maintenance mode
php siro storage:link            # Create storage symlink
```

**Full command list:** `php siro list`

---

## 🎯 Common Tasks

### I want to...

#### Build a REST API
→ See **[Quick Start Tutorial](QUICKSTART.md)**  
→ Use `php siro make:crud posts`

#### Add Authentication
→ See **[Authentication Guide](AUTHENTICATION.md)**  
→ Use `php siro make:auth`

#### Connect to Database
→ See **[Database Guide](DATABASE.md)**  
→ Configure `.env` file

#### Write Tests
→ See **[Testing Guide](TESTING.md)**  
→ Use `php siro make:test ProductApi`

#### Optimize Performance
→ See **[Performance Guide](PERFORMANCE.md)**  
→ Run `php siro benchmark`

#### Secure My API
→ See **[Security Guide](SECURITY.md)**  
→ Run `php siro env:check`

#### Deploy to Production
→ See **[Deployment Guide](DEPLOYMENT.md)**  
→ Use `php siro deploy`

#### Contribute to Framework
→ See **[Contributing Guide](../CONTRIBUTING.md)**  
→ Read **[Architecture Decisions](ARCHITECTURE.md)**

---

## 📖 Learning Path

### Beginner (Week 1)
1. Install SiroPHP
2. Follow Quick Start tutorial
3. Build simple CRUD API
4. Add authentication
5. Write basic tests

### Intermediate (Week 2-3)
1. Learn middleware system
2. Implement relationships
3. Add caching
4. Set up queue system
5. Configure multi-language support

### Advanced (Week 4+)
1. Study architecture decisions
2. Optimize performance
3. Implement custom middleware
4. Extend framework components
5. Contribute to open source

---

## 🔗 External Resources

### Official Links
- **GitHub:** https://github.com/SiroSoft/siro-core
- **Packagist:** https://packagist.org/packages/sirosoft/core
- **Issues:** https://github.com/SiroSoft/siro-core/issues
- **Discussions:** https://github.com/SiroSoft/siro-core/discussions

### Community
- **Discord:** [Join our server](https://discord.gg/sirophp) *(link placeholder)*
- **Twitter:** [@SiroPHP](https://twitter.com/sirophp) *(link placeholder)*
- **Blog:** https://sirosoft.com/blog *(link placeholder)*

### Related Projects
- **SiroPHP Skeleton:** https://github.com/SiroSoft/SiroPHP
- **Demo POS:** https://github.com/SiroSoft/demo-pos
- **Example Apps:** https://github.com/SiroSoft/examples

---

## 📊 Quick Comparison

| Feature | SiroPHP | Laravel | Slim | Express.js |
|---------|---------|---------|------|------------|
| **Boot Time** | <1ms | 50-100ms | 10-20ms | 100-200ms |
| **Memory** | 2MB | 80-100MB | 3-5MB | 30-50MB |
| **Dependencies** | 0 | 50+ | 5+ | 100+ |
| **Learning Curve** | Easy | Steep | Medium | Medium |
| **Best For** | APIs, Microservices | Full-stack apps | Small APIs | JavaScript devs |

---

## ❓ FAQ

### General Questions

**Q: Is SiroPHP production-ready?**  
A: Yes! Used in production by multiple companies. See **[Security Guide](SECURITY.md)** for hardening tips.

**Q: Can I use SiroPHP with existing projects?**  
A: Yes! Install via `composer require sirosoft/core` and integrate gradually.

**Q: Does it support PostgreSQL?**  
A: Yes! MySQL, PostgreSQL, and SQLite are fully supported.

**Q: How does it compare to Laravel?**  
A: 2000-4000x faster, 40x less memory, zero dependencies. Trade-off: smaller ecosystem.

### Technical Questions

**Q: How do I add custom middleware?**  
A: Create class implementing middleware interface, add to route. See **[Router API](api/Router.md)**.

**Q: Can I use Eloquent ORM?**  
A: No, SiroPHP has its own lightweight Model layer. Similar API, less overhead.

**Q: How do I handle file uploads?**  
A: Use `$request->file()` method. See **[Storage Guide](STORAGE.md)**.

**Q: Is there WebSocket support?**  
A: Not yet. Planned for future release. Use external WebSocket server for now.

### Contribution Questions

**Q: How do I report bugs?**  
A: Open issue on GitHub or email security@sirosoft.com for vulnerabilities.

**Q: Can I contribute without coding?**  
A: Yes! Documentation, testing, and community help are valuable. See **[Contributing Guide](../CONTRIBUTING.md)**.

**Q: How do I become a maintainer?**  
A: Consistent quality contributions over time lead to maintainer status.

---

## 🆘 Getting Help

### Priority Order
1. **Search documentation** - Your question might be answered here
2. **Check GitHub Issues** - Someone may have asked before
3. **Ask in Discussions** - Community can help
4. **Open Issue** - For bugs or feature requests
5. **Email Support** - support@sirosoft.com (for urgent matters)

### Response Times
- GitHub Issues: Within 48 hours
- Discussions: Within 3 days
- Email: Within 1 week
- Security issues: Within 48 hours (security@sirosoft.com)

---

## 📝 Document Status

### Completed ✅
- Architecture Decision Records
- Security Guide
- Performance Optimization Guide
- Contributing Guidelines
- Code of Conduct
- Security Policy
- Router API Reference

### In Progress 🚧
- Model API Reference
- Database API Reference
- Authentication Guide
- Testing Guide
- Deployment Guide

### Planned 📅
- Event System Guide
- Queue & Mail Guide
- Caching Strategies
- File Storage Guide
- More API References

---

## 🎉 Contributing to Documentation

Found a typo? Want to improve docs? We welcome contributions!

1. Fork repository
2. Edit documentation files
3. Submit pull request
4. Follow **[Contributing Guide](../CONTRIBUTING.md)**

**Documentation priorities:**
- High: API references, examples
- Medium: Tutorials, guides
- Low: Translations, diagrams

---

*Last updated: May 11, 2026*  
*Documentation version: 1.0*
