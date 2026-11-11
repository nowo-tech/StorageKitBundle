<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\EventListener;

use Nowo\StorageKitBundle\EventListener\RequestBoundaryListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class RequestBoundaryListenerTest extends TestCase
{
    public function testResetsOnMainRequestsAndBoundariesOnly(): void
    {
        $memo = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $listener = new RequestBoundaryListener([$memo]);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $memo->resets, 'a sub-request keeps the memos of its main request');
        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $memo->resets);
        $listener->onBoundary();
        self::assertSame(2, $memo->resets);
    }
}
