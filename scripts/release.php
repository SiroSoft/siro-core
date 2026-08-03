<?php

declare(strict_types=1);

/**
 * Automated release helper — Siro.
 *
 * Guides a version bump: verifies the codebase, updates docs placeholders,
 * tags and pushes. Designed to be re-run every release.
 *
 * Usage:
 *   php scripts/release.php <version> [--dry-run] [--skip-ci-check]
 *   Example: php scripts/release.php 1.0.0 --dry-run
 *
 * Steps:
 *   1. Validate version format
 *   2. composer validate + PHPStan + PHPUnit (unless --skip-checks)
 *   3. Prepend CHANGELOG section placeholder (if missing)
 *   4. Print tag/push commands (or run if not --dry-run)
 */

$version = $argv[1] ?? '';
$dryRun = in_array('--dry-run', $argv, true);
$skipChecks = in_array('--skip-checks', $argv, true);

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Usage: php scripts/release.php <version> (e.g. 1.0.0)\n");
    exit(1);
}

$root = dirname(__DIR__);
echo "=== Siro Release Helper: v{$version} ===\n";

// 1. Composer validate
echo "\n[1/5] composer validate...\n";
exec('composer validate --no-check-publish 2>&1', $out, $code);
echo implode("\n", $out) . "\n";
if ($code !== 0) {
    fwrite(STDERR, "  ❌ composer.json invalid. Fix before release.\n");
    exit(1);
}
echo "  ✅ composer valid\n";

// 2. PHPStan
if (!$skipChecks) {
    echo "\n[2/5] PHPStan level=max...\n";
    exec('php -d memory_limit=512M vendor/bin/phpstan analyse --level=max --no-progress 2>&1', $out2, $code2);
    echo implode("\n", array_slice($out2, -3)) . "\n";
    if ($code2 !== 0) {
        fwrite(STDERR, "  ❌ PHPStan errors. Fix before release.\n");
        exit(1);
    }
    echo "  ✅ PHPStan 0 errors\n";
} else {
    echo "\n[2/5] PHPStan — skipped (--skip-checks)\n";
}

// 3. PHPUnit
if (!$skipChecks) {
    echo "\n[3/5] PHPUnit...\n";
    exec('php vendor/bin/phpunit --no-coverage 2>&1', $out3, $code3);
    echo implode("\n", array_slice($out3, -2)) . "\n";
    if ($code3 !== 0) {
        fwrite(STDERR, "  ❌ PHPUnit failures. Fix before release.\n");
        exit(1);
    }
    echo "  ✅ PHPUnit clean\n";
} else {
    echo "\n[3/5] PHPUnit — skipped (--skip-checks)\n";
}

// 4. CHANGELOG placeholder (idempotent — only prepend if missing)
echo "\n[4/5] CHANGELOG...\n";
$changelog = $root . '/CHANGELOG.md';
$c = file_get_contents($changelog);
$needle = "## v{$version}";
if (str_contains($c, $needle)) {
    echo "  ℹ️  CHANGELOG already has v{$version}, skipping\n";
} else {
    $title = "# Changelog — siro-core\n\n";
    $section = "## v{$version} (unreleased)\n\n### Summary\n- (fill in changes)\n\n";
    if (str_starts_with($c, $title)) {
        $c = substr($c, strlen($title));
    }
    file_put_contents($changelog, $title . $section . $c);
    echo "  ✅ CHANGELOG placeholder added\n";
}

// 5. Tag + push
echo "\n[5/5] Tag & push...\n";
$branch = trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>&1'));
echo "  Branch: {$branch}\n";
if ($dryRun) {
    echo "  (dry-run) Would run:\n";
    echo "    git add -A\n";
    echo "    git commit -m \"chore: v{$version} release prep\"\n";
    echo "    git push origin {$branch}\n";
    echo "    git tag -a v{$version} -m \"v{$version} — release\"\n";
    echo "    git push origin v{$version}\n";
} else {
    exec('git add -A 2>&1');
    exec('git commit -m ' . escapeshellarg("chore: v{$version} release prep") . ' 2>&1', $cOut, $cCode);
    echo implode("\n", array_slice($cOut, -2)) . "\n";
    exec('git push origin ' . escapeshellarg($branch) . ' 2>&1', $pOut, $pCode);
    echo "  Push branch: " . ($pCode === 0 ? '✅' : '⚠ (kiểm tra remote)') . "\n";
    exec('git tag -a v' . escapeshellarg($version) . ' -m ' . escapeshellarg("v{$version} — release") . ' 2>&1', $tOut, $tCode);
    exec('git push origin v' . escapeshellarg($version) . ' 2>&1', $tpOut, $tpCode);
    echo "  Push tag: " . ($tpCode === 0 ? '✅' : '⚠ (kiểm tra remote)') . "\n";
}

echo "\n=== Done. Next: CI xanh 3 OS → SiroPHP release branch → merge main ===\n";
exit(0);
