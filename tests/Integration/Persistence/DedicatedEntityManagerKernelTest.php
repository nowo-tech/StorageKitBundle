<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Persistence;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Fixtures\HostDefaultManagerExtension;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\BoundaryDriver;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Ui\ProfileTester;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function dirname;
use function is_array;
use function str_starts_with;

/**
 * 1.5 `doctrine.entity_manager` (REQ-ARCH-001 rule 6, REQ-RUNTIME-002 rule 4): with a dedicated manager the bundle
 * entities are mapped on it only, every bundle write — the admin and CLI ones that flush (profile test, bucket moves,
 * purge) included — goes through it, so the host's pending work on its own manager is never flushed; the bundle
 * manager is recovered when closed and cleared at every request, message and command boundary, and the host's never.
 */
final class DedicatedEntityManagerKernelTest extends KernelTestCase
{
    public function testTheEntitiesAreMappedOnTheDedicatedManagerOnly(): void
    {
        $kernel   = $this->bootDedicated();
        $registry = $this->registry($kernel);
        $bundle   = $registry->getManager('storage_kit');
        $default  = $registry->getManager('default');

        self::assertSame($bundle, $this->service($kernel, StorageEntityManager::class)->manager());
        self::assertSame($bundle, $registry->getManagerForClass(StoredFile::class));
        self::assertSame($default, $registry->getManagerForClass(Note::class));
        $classes = static fn (EntityManagerInterface $manager): array => array_values(array_filter(
            array_map(static fn (ClassMetadata $metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata()),
            static fn (string $class): bool => str_starts_with($class, 'Nowo\\StorageKitBundle\\Entity\\'),
        ));
        self::assertInstanceOf(EntityManagerInterface::class, $default);
        self::assertSame([], $classes($default), 'auto_mapping and the default prepend are kept off the host manager');
        self::assertCount(4, $classes($bundle));
        self::assertTrue($this->container($kernel)->has(OwnEntityManagerSubscriber::class));
    }

    /**
     * DoctrineBundle 3.4 regression: `auto_discover_entities` (on by default for a manager without `mappings` and
     * `auto_mapping`) gives the host manager's chain a default attribute driver, which claims every `#[ORM\Entity]`
     * class: the host manager mapped the bundle entities too, and once it came first in the registry (another bundle
     * configuring it, the host's own order) `getManagerForClass()` answered it, so host-facing code (`doctrine`
     * repositories, EntityType, UniqueEntity, MapEntity) queried its database. The explicit catch-all default driver
     * reproduces it on every DoctrineBundle version. The bundle manager is on its own database here.
     */
    #[DataProvider('hostManagersWithADefaultDriver')]
    #[IgnoreDeprecations]
    public function testTheDedicatedManagerWinsOverADefaultDriverOfTheHostManager(bool $explicitDefaultDriver, bool $hostManagerFirst): void
    {
        $kernel = $this->bootKernel(
            [
                'doctrine'         => ['entity_manager' => 'storage_kit'],
                'profiles'         => ['main' => ['dsn' => 's3://test-key:test-secret-not-real@minio:9000/main?tls=0&path_style=1']],
                'instance_profile' => 'main',
            ],
            // Instance Settings maps its entities on the host manager, which turns DoctrineBundle 3.4 auto-discovery off.
            without: ['settings'],
            orm: ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default'], 'storage_kit' => ['connection' => 'storage_kit']]],
            dbal: ['default_connection' => 'default', 'connections' => ['default' => ['url' => 'sqlite:///:memory:'], 'storage_kit' => ['url' => 'sqlite:///:memory:']]],
            build: static function (ContainerBuilder $container) use ($explicitDefaultDriver, $hostManagerFirst): void {
                if ($hostManagerFirst) {
                    $container->registerExtension(new HostDefaultManagerExtension());
                }
                if ($explicitDefaultDriver) {
                    $container->addCompilerPass(new class implements CompilerPassInterface {
                        public function process(ContainerBuilder $container): void
                        {
                            // An attribute driver answers "not transient" for any class carrying #[ORM\Entity],
                            // whatever its paths.
                            $container->getDefinition('doctrine.orm.default_metadata_driver')
                                ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[dirname(__DIR__, 2) . '/Fixtures/Entity'], true])]);
                        }
                    });
                }
            },
            schema: false,
        );
        $registry = $this->registry($kernel);
        self::assertSame($hostManagerFirst ? ['default', 'storage_kit'] : ['storage_kit', 'default'], array_keys($registry->getManagerNames()));
        $bundle = $registry->getManager('storage_kit');

        // Neither manager is initialized yet: DoctrineBundle 3.4 asks the metadata drivers.
        foreach ([StoredFile::class, StorageProfile::class, StorageSync::class] as $class) {
            self::assertSame($bundle, $registry->getManagerForClass($class), $class);
        }

        $this->createSchema($kernel);
        $host = $this->hostManager($kernel);
        self::assertTrue($host->getMetadataFactory()->isTransient(StoredFile::class), 'not mapped on the host manager');
        self::assertSame($bundle, $registry->getManagerForClass(StoredFile::class));

        // A bundle write lands in the bundle database, and the host-facing registry reads it back from there.
        $profile = $this->profile($kernel);
        self::assertSame($bundle, $this->service($kernel, StorageEntityManager::class)->manager());
        $bundle->clear();
        self::assertInstanceOf(StorageProfile::class, $registry->getRepository(StorageProfile::class)->find($profile->getId()), 'the registry repository reads the bundle database');
        self::assertFalse($host->getConnection()->createSchemaManager()->tablesExist([$bundle->getClassMetadata(StorageProfile::class)->getTableName()]), 'nothing on the host database');
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function hostManagersWithADefaultDriver(): iterable
    {
        yield 'host manager without mappings (DoctrineBundle 3.4 auto-discovery)' => [false, false];
        yield 'host manager without mappings, first in the registry' => [false, true];
        yield 'host manager with a catch-all default driver' => [true, false];
        yield 'host manager with a catch-all default driver, first in the registry' => [true, true];
    }

    public function testNoBundleWriteFlushesTheHostManager(): void
    {
        $kernel = $this->bootDedicated();
        $host   = $this->hostManager($kernel);
        $bundle = $this->service($kernel, StorageEntityManager::class)->manager();
        $note   = new Note('b1', 'pending host change');
        $host->persist($note);
        $from = $this->profile($kernel);
        $to   = $this->profile($kernel, null, 'target', StorageProfile::STATE_TARGET);

        // Host-called writes: upload, references, GDPR erasure.
        $view = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(30, 20), 'a.png');
        $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(30, 20), 'b.png');
        $this->service($kernel, FileReferencesInterface::class)->sync('note', 'n1', '![a](storage-file:' . $view->uuid . ')', ['board', 'b1']);
        // Admin and CLI writes that flush the bundle manager: profile test, bucket move, purge.
        $this->service($kernel, ProfileTester::class)->test($from, null);
        $sync = $this->service($kernel, StorageMove::class)->start($from, $to, null);
        self::assertTrue($this->service($kernel, StorageSyncer::class)->run($sync));
        self::assertSame(StorageSync::COMPLETED, $sync->getStatus());
        self::assertSame(2, $this->service($kernel, StoredFilesPersonalDataInterface::class)->erase('alice'));
        // The note is not saved yet, so its reference is dropped too: both files are purged.
        self::assertSame(2, $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable('+1 day'), 0)['purged']);

        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'the host change is still pending');
        $notes = $host->getClassMetadata(Note::class)->getTableName();
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $notes));
        self::assertNotNull($bundle->getRepository(StorageProfile::class)->find($from->getId())?->getTestedAt());
        $host->flush();
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $notes));
    }

    public function testTheBundleManagerIsRecoveredAndClearedAtEveryBoundaryAndTheHostOneNever(): void
    {
        $kernel     = $this->bootDedicated();
        $registry   = $this->registry($kernel);
        $host       = $this->hostManager($kernel);
        $dispatcher = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $note = new Note('b1', 'pending host change');
        $host->persist($note);
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $subscriber = $this->service($kernel, OwnEntityManagerSubscriber::class);

        foreach (['kernel.terminate', ConsoleEvents::TERMINATE, 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'] as $event) {
            $profile = $this->profile($kernel);
            $bundle  = $this->service($kernel, StorageEntityManager::class)->manager();
            self::assertTrue($bundle->contains($profile));
            // Plus the PHP_INT_MAX bookkeeping (1.5.1, audit NM).
            self::assertCount(2, array_filter($dispatcher->getListeners($event), static fn (mixed $listener): bool => is_array($listener) && $listener[0] === $subscriber), $event);
            $subscriber->clear();
            self::assertFalse($bundle->contains($profile), $event);
            self::assertTrue($host->contains($note), $event . ': the host manager is never cleared');
        }

        // A failed write closed the bundle manager: the next main request starts with a usable one.
        $closed = $registry->getManager('storage_kit');
        self::assertInstanceOf(EntityManagerInterface::class, $closed);
        $closed->close();
        $request  = Request::create('/storage/files/00000000-0000-4000-8000-000000000000/original', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $response = $http->handle($request);
        $http->terminate($request, $response);
        self::assertSame(404, $response->getStatusCode());
        $manager = $registry->getManager('storage_kit');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->isOpen());
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note));

        // A closed manager is skipped by clear() (nothing to detach) and recovered for a Messenger message.
        $manager->close();
        $subscriber->clear();
        $subscriber->recover();
        $manager = $registry->getManager('storage_kit');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->isOpen());
    }

    public function testEveryUnitStartsWithAnEmptyBundleManagerEvenWhenTheLastOneNeverEnded(): void
    {
        $kernel     = $this->bootDedicated();
        $subscriber = $this->service($kernel, OwnEntityManagerSubscriber::class);
        $http       = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $dispatcher = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $bundle = fn (): EntityManagerInterface => $this->service($kernel, StorageEntityManager::class)->manager();

        // A request whose kernel.terminate never ran (fatal error, worker killed mid-response): the next main request
        // starts empty anyway (audit NH-3).
        $profile = $this->profile($kernel);
        $request = Request::create('/storage/files/00000000-0000-4000-8000-000000000000/original', server: ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(404, $http->handle($request)->getStatusCode());
        self::assertFalse($bundle()->contains($profile), 'cleared at the start of the main request');

        // A Messenger message starts empty too.
        $profile = $this->profile($kernel);
        $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
        self::assertFalse($bundle()->contains($profile), 'cleared when a message is received');

        // Nested console commands (audit NH-4): only the outermost start and end act; a request handled in-process by
        // a command does not clear the command's unit.
        $units = new BoundaryDriver($dispatcher, $http);
        $units->handled();
        $units->commandStarts();
        $profile = $this->profile($kernel);
        $units->commandStarts();
        $http->handle($request);
        $units->terminate();
        $units->commandEnds();
        self::assertTrue($bundle()->contains($profile), 'the inner command and the in-process request keep the outer unit');
        $units->commandEnds();
        self::assertFalse($bundle()->contains($profile), 'the outermost command end clears');
        $profile = $this->profile($kernel);
        $units->commandEnds();
        self::assertTrue($bundle()->contains($profile), 'a run that never started ends nothing (audit NM)');
        $subscriber->onTerminate();
        self::assertFalse($bundle()->contains($profile), 'outside a command, kernel.terminate clears');
    }

    public function testAnUnknownManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_storage_kit.doctrine.entity_manager is "missing", but no such Doctrine entity manager is configured (known: "default").');
        $this->bootKernel(['doctrine' => ['entity_manager' => 'missing']]);
    }

    public function testTheHostDefaultManagerByNameKeepsTheStandardBehaviour(): void
    {
        foreach ([null, $this->orm()] as $orm) {
            $kernel = $this->bootKernel(['doctrine' => ['entity_manager' => 'default']], orm: $orm === null ? null : ['default_entity_manager' => 'default', 'entity_managers' => ['default' => $orm['entity_managers']['default']]]);
            self::assertSame($this->registry($kernel)->getManager('default'), $this->service($kernel, StorageEntityManager::class)->manager());
            self::assertFalse($this->container($kernel)->has(OwnEntityManagerSubscriber::class), 'the host manager is never cleared by the bundle');
            $this->profile($kernel);
        }
    }

    /**
     * @return array{default_entity_manager: string, entity_managers: array<string, array<string, mixed>>}
     */
    private function orm(): array
    {
        return [
            'default_entity_manager' => 'default',
            'entity_managers'        => [
                'default'     => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => ['Tests' => StorageKitTestKernel::testsMapping()]],
                'storage_kit' => ['connection' => 'default'],
            ],
        ];
    }

    private function bootDedicated(): StorageKitTestKernel
    {
        return $this->bootKernel(['doctrine' => ['entity_manager' => 'storage_kit']], orm: $this->orm());
    }

    private function registry(StorageKitTestKernel $kernel): ManagerRegistry
    {
        $registry = $this->service($kernel, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function hostManager(StorageKitTestKernel $kernel): EntityManagerInterface
    {
        $manager = $this->registry($kernel)->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
