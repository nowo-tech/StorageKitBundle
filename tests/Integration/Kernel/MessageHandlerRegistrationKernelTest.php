<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Kernel;

use ArrayObject;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function count;
use function is_array;
use function str_ends_with;

/**
 * REQ-MSG-001: `RunStorageSyncHandler` carries `#[AsMessageHandler]` and keeps its explicit `messenger.message_handler`
 * tag on a definition that is not autoconfigured, so the bus gets it exactly once — also in a host that autoconfigures
 * everything it registers itself.
 */
final class MessageHandlerRegistrationKernelTest extends KernelTestCase
{
    public function testTheSyncHandlerIsRegisteredExactlyOnce(): void
    {
        // What the container held before the removing passes.
        $seen = new ArrayObject();
        $this->bootKernel(build: static function (ContainerBuilder $container) use ($seen): void {
            $container->addCompilerPass(new class($seen) implements CompilerPassInterface {
                /**
                 * @param ArrayObject<string, bool|int> $seen
                 */
                public function __construct(
                    private readonly ArrayObject $seen,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $definition = $container->getDefinition(RunStorageSyncHandler::class);
                    $handlers   = 0;
                    foreach ($container->getDefinitions() as $id => $locator) {
                        if (!str_ends_with((string) $id, '.messenger.handlers_locator')) {
                            continue;
                        }
                        $mapping = $locator->getArgument(0);
                        $entry   = is_array($mapping) ? ($mapping[RunStorageSyncMessage::class] ?? null) : null;
                        $handlers += $entry instanceof IteratorArgument ? count($entry->getValues()) : (is_array($entry) ? count($entry) : 0);
                    }
                    $this->seen->exchangeArray([
                        'tags'           => count($definition->getTag('messenger.message_handler')),
                        'autoconfigured' => $definition->isAutoconfigured(),
                        'handlers'       => $handlers,
                    ]);
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        });

        self::assertSame(['tags' => 1, 'autoconfigured' => false, 'handlers' => 1], $seen->getArrayCopy());
        self::assertNotSame([], (new ReflectionClass(RunStorageSyncHandler::class))->getAttributes(AsMessageHandler::class));
    }
}
