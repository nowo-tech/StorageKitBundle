<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Controller;

use Nowo\StorageKitBundle\Controller\FileUploadController;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Audit CJ I6: every uploader has a stable rate-limit key, also a user object that is not a Symfony user.
 */
final class UploadRateKeyTest extends TestCase
{
    public function testKeysAreStableAndHashed(): void
    {
        $request = Request::create('/storage/upload/board/b1', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $alice   = FileUploadController::rateKey(new InMemoryUser('alice', null), $request);
        self::assertSame($alice, FileUploadController::rateKey(new InMemoryUser('alice', null), $request));
        self::assertStringStartsWith('u:', $alice);
        self::assertStringNotContainsString('alice', $alice);

        // A non-Symfony user: the client IP without a session…
        $byIp = FileUploadController::rateKey(new stdClass(), $request);
        self::assertSame($byIp, FileUploadController::rateKey(new stdClass(), $request), 'two objects, one key');
        self::assertStringStartsWith('ip:', $byIp);

        // …the session when one is started.
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);
        $bySession = FileUploadController::rateKey(new stdClass(), $request);
        self::assertSame('s:' . hash('sha256', $session->getId()), $bySession);

        // A session that is not started falls back to the IP.
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame($byIp, FileUploadController::rateKey(new stdClass(), $request));
    }
}
