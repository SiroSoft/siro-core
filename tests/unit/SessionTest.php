<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Session;

final class SessionTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        Session::setInstance(null);
        $this->session = new Session('file');
        Session::setInstance($this->session);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $sessionDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (is_dir($sessionDir)) {
            array_map('unlink', glob($sessionDir . '/*') ?: []);
            rmdir($sessionDir);
        }
        Session::setInstance(null);
    }

    public function testInstance(): void
    {
        $this->assertInstanceOf(Session::class, Session::instance());
        $this->assertSame($this->session, Session::instance());
    }

    public function testStartAndSet(): void
    {
        $this->session->start();
        $this->session->set('user_id', 42);
        $this->assertSame(42, $this->session->get('user_id'));
    }

    public function testHas(): void
    {
        $this->session->start();
        $this->assertFalse($this->session->has('missing'));
        $this->session->set('exists', true);
        $this->assertTrue($this->session->has('exists'));
    }

    public function testRemove(): void
    {
        $this->session->start();
        $this->session->set('temp', 'value');
        $this->assertTrue($this->session->has('temp'));
        $this->session->remove('temp');
        $this->assertFalse($this->session->has('temp'));
    }

    public function testGetReturnsDefault(): void
    {
        $this->session->start();
        $this->assertSame('default', $this->session->get('nothing', 'default'));
    }

    public function testFlash(): void
    {
        $this->session->start();
        $this->session->flash('success', 'Saved!');
        $this->session->save();

        $session2 = new Session('file');
        $sessionId = $this->session->getId();
        $session2->setId($sessionId);
        $session2->start();
        $this->assertSame('Saved!', $session2->getFlash('success'));
    }

    public function testAll(): void
    {
        $this->session->start();
        $this->session->set('a', 1);
        $this->session->set('b', 2);
        $data = $this->session->all();
        $this->assertArrayHasKey('a', $data);
        $this->assertArrayHasKey('b', $data);
    }

    public function testDestroy(): void
    {
        $this->session->start();
        $this->session->set('key', 'val');
        $this->session->destroy();
        $this->assertNull($this->session->get('key'));
    }

    public function testRegenerate(): void
    {
        $this->session->start('test_session_id');
        $this->session->regenerate();
        $this->assertNotSame('test_session_id', $this->session->getId());
    }

    public function testDriverDefaults(): void
    {
        $this->assertTrue(true);
    }
}
