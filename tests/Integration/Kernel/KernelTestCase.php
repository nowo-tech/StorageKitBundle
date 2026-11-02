<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Tests\Fixtures\AuditLog;
use Nowo\StorageKitBundle\Tests\Fixtures\Boards;
use Nowo\StorageKitBundle\Tests\Fixtures\NoteContext;
use Nowo\StorageKitBundle\Tests\Fixtures\NoteReferenceSource;
use Nowo\StorageKitBundle\Tests\Fixtures\ScopeAccess;
use Nowo\StorageKitBundle\Tests\Fixtures\UploadLimit;
use Nowo\StorageKitBundle\Tests\Support\InMemoryBuckets;
use Nowo\StorageKitBundle\Tests\Support\InMemoryStorageConnectionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function count;
use function in_array;
use function preg_replace;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Boots a fresh {@see StorageKitTestKernel} per call (own cache dir, schema created on the in-memory database), with
 * the in-memory buckets, the `board` context, the `note` reference source, the `acme` scope access and the audit log.
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<StorageKitTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(StorageKitTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config nowo_storage_kit configuration
     * @param list<string> $without bundles / features to leave out
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: the bundle loader)
     * @param Closure(ContainerConfigurator): void|null $extra extra container configuration
     */
    protected function bootKernel(array $config = [], array $without = [], ?Closure $routes = null, bool $debug = false, ?Closure $extra = null, bool $memory = true): StorageKitTestKernel
    {
        $config += [
            'scopes'    => ['enabled' => true, 'access_checker' => ScopeAccess::class],
            'endpoints' => ['allowed_hosts' => ['minio'], 'resolve_dns' => false],
        ];
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new StorageKitTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $extra, $memory, $without): void {
                $container->extension('nowo_storage_kit', $config);
                $services = $container->services();
                $services->set(InMemoryBuckets::class)->public();
                if ($memory) {
                    $services->set(InMemoryStorageConnectionFactory::class)->args([service(InMemoryBuckets::class)]);
                    $services->alias('nowo_storage_kit.connection_factory', InMemoryStorageConnectionFactory::class)->public();
                }
                $services->set(Boards::class)->public();
                $services->set(NoteContext::class)->args([service(Boards::class)])->public()->tag('nowo_storage_kit.file_context');
                $services->set(NoteReferenceSource::class)->args([service('doctrine')])->tag('nowo_storage_kit.reference_source');
                $services->set(ScopeAccess::class);
                $services->set(UploadLimit::class);
                $services->set(AuditLog::class)->public()->tag('kernel.event_listener', ['event' => StorageAuditEvent::class]);
                if (!in_array('messenger', $without, true)) {
                    $services->alias('test.messenger.transport', 'messenger.transport.async')->public();
                }
                if ($extra instanceof Closure) {
                    $extra($container);
                }
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_storage_kit');
            },
            $without,
            $debug,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        if ($entityManager instanceof EntityManagerInterface) {
            $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
            $tool     = new SchemaTool($entityManager);
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        return $kernel;
    }

    protected function container(StorageKitTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * Browser that keeps the session (CSRF tokens) and the in-memory database (no kernel reboot) between requests.
     */
    protected function client(StorageKitTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters($user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']);

        return $client;
    }

    /**
     * A CSRF token of the browser's session (for actions the page shows disabled).
     */
    protected function token(StorageKitTestKernel $kernel, KernelBrowser $client, string $id): string
    {
        $stack = $this->service($kernel, 'request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $request = $client->getRequest();
        $session = $request->getSession();
        $session->start();
        $stack->push($request);
        try {
            $manager = $this->service($kernel, 'security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);

            return $manager->getToken($id)->getValue();
        } finally {
            $stack->pop();
            $session->save();
            $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        }
    }

    protected function entityManager(StorageKitTestKernel $kernel): EntityManagerInterface
    {
        $doctrine = $this->service($kernel, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    protected function buckets(StorageKitTestKernel $kernel): InMemoryBuckets
    {
        return $this->service($kernel, InMemoryBuckets::class);
    }

    protected function audit(StorageKitTestKernel $kernel): AuditLog
    {
        return $this->service($kernel, AuditLog::class);
    }

    /**
     * A saved, enabled database profile (instance when `$scope` is null) on the in-memory buckets.
     */
    protected function profile(StorageKitTestKernel $kernel, ?string $scope = null, string $bucket = 'files', string $state = StorageProfile::STATE_ACTIVE, ?string $endpoint = 'http://minio:9000'): StorageProfile
    {
        $profile = new StorageProfile($scope, $state);
        $profile->configure(true, $endpoint, null, 'us-east-1', $bucket, 'app', 'test-key', 'test-secret-not-real', true);
        $manager = $this->entityManager($kernel);
        $manager->persist($profile);
        $manager->flush();

        return $profile;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    protected function service(StorageKitTestKernel $kernel, string $id): object
    {
        $service = $this->container($kernel)->get($id);
        self::assertIsObject($service);

        return $service;
    }
}
