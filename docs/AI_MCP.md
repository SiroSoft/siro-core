# AI Coding and Siro MCP

Siro Core provides the framework APIs that an AI coding agent needs to inspect,
test, and debug a Siro application. The MCP integration itself is a separate
package: `sirosoft/mcp-server`.

## Install

Install the MCP server in the application project, not in the Core library:

```bash
composer require --dev sirosoft/mcp-server
php siro mcp:serve
```

The package exposes project analysis, documentation, routes, models, traces,
scaffolding, safe CLI commands, and controlled file operations to MCP clients.

## Safety Defaults

The MCP server is designed as a local or staging control plane:

- read-only tools work without approval;
- file writes, patches, scaffolding, and destructive CLI commands require
  `SIRO_MCP_APPROVAL_TOKEN`;
- destructive CLI commands also require `force=true`;
- paths are restricted to the project root;
- CLI commands are allowlisted and shell escape commands are blocked;
- tool results receive a `runId` and are written to a redacted JSONL audit log.

Configure approval in the MCP process environment, never in source control or
an AI prompt:

PowerShell:

```powershell
$env:SIRO_MCP_APPROVAL_TOKEN = "read-from-your-secret-store"
php siro mcp:serve
```

Bash:

```bash
export SIRO_MCP_APPROVAL_TOKEN="read-from-your-secret-store"
php siro mcp:serve
```

## Recommended Agent Workflow

1. Analyze the project with `analyze_project`.
2. Read the relevant Core documentation.
3. Preview edits with `patch_file` in `diff` mode.
4. Review the preview and approve only the intended mutation.
5. Run `php siro test`, PHPStan, or a read-only CLI check.
6. Read `siro://mcp/runs/latest` to verify the result.

## Production Boundary

Do not expose MCP through a public HTTP endpoint or put it in the web
container by default. Run it as a separately authenticated process with a
least-privilege filesystem mount and protected audit storage. The MCP package
is a development dependency in Siro Showcase and is excluded from its
production web image.

See the complete operational guide in the
[`siro-mcp-server`](https://github.com/SiroSoft/siro-mcp-server-/blob/v0.3.0/docs/OPERATIONS.md)
repository.
