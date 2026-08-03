<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Commands\LogReplayCommand;

/**
 * Debug workflow tests: replay target hardening (SSRF / URL injection).
 */
final class DebugWorkflowTest extends TestCase
{
    public function testHostValidationAcceptsValidHosts(): void
    {
        $this->assertTrue(LogReplayCommand::isValidHost('localhost:8080'));
        $this->assertTrue(LogReplayCommand::isValidHost('127.0.0.1:8080'));
        $this->assertTrue(LogReplayCommand::isValidHost('api.example.com'));
        $this->assertTrue(LogReplayCommand::isValidHost('[::1]:8080'));
        $this->assertTrue(LogReplayCommand::isValidHost('localhost'));
    }

    public function testHostValidationRejectsMaliciousHosts(): void
    {
        $this->assertFalse(LogReplayCommand::isValidHost(''));
        $this->assertFalse(LogReplayCommand::isValidHost('evil.com\\@127.0.0.1'));
        $this->assertFalse(LogReplayCommand::isValidHost('host with space'));
        $this->assertFalse(LogReplayCommand::isValidHost("evil.com\n127.0.0.1"));
        $this->assertFalse(LogReplayCommand::isValidHost('127.0.0.1:99999'));
        $this->assertFalse(LogReplayCommand::isValidHost('127.0.0.1:abc'));
        $this->assertFalse(LogReplayCommand::isValidHost('evil.com:8080@127.0.0.1'));
    }

    public function testPathValidation(): void
    {
        $this->assertTrue(LogReplayCommand::isValidPath('/api/users'));
        $this->assertTrue(LogReplayCommand::isValidPath('/api/users/123?page=2'));
        $this->assertFalse(LogReplayCommand::isValidPath("/api/users/123\n"));
        $this->assertFalse(LogReplayCommand::isValidPath("/api/x\x00"));
        $this->assertFalse(LogReplayCommand::isValidPath('/api/evil header'));
    }
}
