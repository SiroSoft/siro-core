<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use Siro\Core\Tests\TestCase;
use Siro\Core\Session;

/**
 * Session flash lifecycle + persistence tests (file driver).
 */
final class SessionFlashTest extends TestCase
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
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        Session::setInstance(null);
        parent::tearDown();
    }

    public function testFlashLifecycle(): void
    {
        $this->session->start();
        $this->session->flash('success', 'Saved!');
        $this->assertFalse($this->session->hasFlash('success'));
        $this->session->save();
        // save() persists _flash_next to _flash in storage; a fresh session
        // (next request) loads it as current flash
        $session2 = new Session('file');
        $session2->setId($this->session->getId());
        $session2->start();
        $this->assertTrue($session2->hasFlash('success'));
        $this->assertSame('Saved!', $session2->getFlash('success'));
    }

    public function testGetFlashDefault(): void
    {
        $this->session->start();
        $this->assertNull($this->session->getFlash('missing'));
        $this->assertSame('fallback', $this->session->getFlash('missing', 'fallback'));
    }

    public function testKeepPreservesFlash(): void
    {
        $this->session->start();
        $this->session->flash('keep_me', 'v');
        $this->session->save();
        // reload as next request
        $session2 = new Session('file');
        $session2->setId($this->session->getId());
        $session2->start();
        $this->assertTrue($session2->hasFlash('keep_me'));

        $session2->keep('keep_me');
        $session2->save();
        $session3 = new Session('file');
        $session3->setId($this->session->getId());
        $session3->start();
        $this->assertTrue($session3->hasFlash('keep_me'));
    }

    public function testReflashAll(): void
    {
        $this->session->start();
        $this->session->flash('a', 1);
        $this->session->flash('b', 2);
        $this->session->save();
        $session2 = new Session('file');
        $session2->setId($this->session->getId());
        $session2->start();
        $this->assertTrue($session2->hasFlash('a'));
        $this->assertTrue($session2->hasFlash('b'));
        $session2->reflash();
        $session2->save();
        $session3 = new Session('file');
        $session3->setId($this->session->getId());
        $session3->start();
        $this->assertTrue($session3->hasFlash('a'));
        $this->assertTrue($session3->hasFlash('b'));
    }

    public function testSaveAndLoadPersistence(): void
    {
        $this->session->start();
        $this->session->set('user_id', 42);
        $id = $this->session->getId();
        $this->session->save();

        // New session with same id loads persisted data
        $session2 = new Session('file');
        $session2->setId($id);
        $session2->start();
        $this->assertSame(42, $session2->get('user_id'));
    }

    public function testDestroyClears(): void
    {
        $this->session->start();
        $this->session->set('x', 1);
        $this->session->destroy();
        $this->assertFalse($this->session->has('x'));
        $this->assertFalse($this->session->isStarted());
    }

    public function testRegenerateChangesId(): void
    {
        $this->session->start();
        $oldId = $this->session->getId();
        $this->session->regenerate();
        $this->assertNotSame($oldId, $this->session->getId());
    }

    public function testIsStarted(): void
    {
        $this->assertFalse($this->session->isStarted());
        $this->session->start();
        $this->assertTrue($this->session->isStarted());
    }

    public function testAll(): void
    {
        $this->session->start();
        $this->session->set('name', 'Siro');
        $this->session->set('count', 3);
        $data = $this->session->all();
        $this->assertSame('Siro', $data['name']);
        $this->assertSame(3, $data['count']);
    }

    public function testGc(): void
    {
        $this->session->start();
        $this->session->set('key', 'value');
        $this->session->save();
        $cleaned = Session::gc(1); // expire everything older than 1s
        $this->assertIsInt($cleaned);
    }
}
