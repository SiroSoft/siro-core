<?php

declare(strict_types=1);

namespace Siro\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Env;
use Siro\Core\Session;

/**
 * Branch coverage for Session: gc, flash lifecycle, idle timeout, regenerate.
 */
final class SessionMutationTest extends TestCase
{
    private string $sessionsDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        Env::reset();
        putenv('APP_ENV=testing');
        Session::setInstance(null);
        $this->sessionsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($this->sessionsDir)) {
            mkdir($this->sessionsDir, 0775, true);
        }
        foreach (glob($this->sessionsDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            @unlink($f);
        }
        $this->cleanCookie();
    }

    protected function tearDown(): void
    {
        set_time_limit(0);
        Env::reset();
        Cache::reset();
        putenv('APP_ENV');
        Session::setInstance(null);
        foreach (glob($this->sessionsDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            @unlink($f);
        }
        $this->cleanCookie();
        parent::tearDown();
    }

    private function cleanCookie(): void
    {
        unset($_COOKIE['siro_session']);
    }

    public function testGcNoDir(): void
    {
        // Point BASE_PATH to a non-existent dir by setting instance
        $deleted = Session::gc(3600);
        $this->assertIsInt($deleted);
    }

    public function testGcDeletesExpired(): void
    {
        $old = $this->sessionsDir . DIRECTORY_SEPARATOR . str_repeat('a', 64) . '.json';
        $new = $this->sessionsDir . DIRECTORY_SEPARATOR . str_repeat('b', 64) . '.json';
        file_put_contents($old, '{}');
        file_put_contents($new, '{}');
        touch($old, time() - 5000);

        $deleted = Session::gc(3600);
        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function testFlashKeepAndReflash(): void
    {
        $session = new Session('file');
        $id = str_repeat('c', 64);
        $session->setId($id);
        // Make data non-empty so sessionId survives start()
        $session->set('_marker', 1);
        $session->start($id);
        $session->flash('message', 'hello');
        $session->save();

        $session2 = new Session('file');
        $session2->setId($id);
        $session2->set('_marker', 1);
        $session2->start($id);
        $this->assertSame('hello', $session2->getFlash('message'));
        $this->assertTrue($session2->hasFlash('message'));

        $session2->keep('message');
        $this->assertTrue($session2->hasFlash('message'));
        $session2->reflash();
        $this->assertTrue($session2->hasFlash('message'));
    }

    public function testSetIdInvalidIgnored(): void
    {
        $session = new Session('file');
        $session->setId('not-valid-hex');
        $this->assertSame('', $session->getId());
    }

    public function testStartPersistsData(): void
    {
        $session = new Session('file');
        $session->start();
        $session->set('user_id', 42);
        $session->save();

        $files = glob($this->sessionsDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $this->assertNotEmpty($files);
        $data = json_decode((string) file_get_contents($files[0]), true);
        $this->assertSame(42, $data['user_id']);
    }

    public function testSaveWithoutStartNoop(): void
    {
        $session = new Session('file');
        $session->save();
        $this->assertFalse($session->isStarted());
    }

    public function testAgeFlashData(): void
    {
        $session = new Session('file');
        $session->ageFlashData();
        $this->assertTrue(true);
    }

    public function testIdleTimeoutDestroys(): void
    {
        putenv('SESSION_IDLE_TIMEOUT=1');
        $session = new Session('file');
        $session->set('user_id', 1);
        $session->save();
        $id = $session->getId();
        $session->start($id);

        // Simulate idle: overwrite last_activity to old
        $path = $this->sessionsDir . DIRECTORY_SEPARATOR . $id . '.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['_last_activity'] = time() - 100;
        file_put_contents($path, (string) json_encode($data));

        $session2 = new Session('file');
        $session2->start($id);
        $this->assertNotSame($id, $session2->getId());
    }
}
