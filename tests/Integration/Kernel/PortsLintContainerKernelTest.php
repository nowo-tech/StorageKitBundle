<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Kernel;

use Closure;
use LogicException;
use Nowo\StorageKitBundle\Controller\FileDownloadController;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\StoredImageResolverInterface;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Tests\Fixtures\Ports\CountingFileDelivery;
use Nowo\StorageKitBundle\Tests\Fixtures\Ports\DualDeliveryDecorator;
use Nowo\StorageKitBundle\Tests\Fixtures\Ports\LegacyDeliveryDecorator;
use Nowo\StorageKitBundle\Tests\Fixtures\Ports\NotADelivery;
use Nowo\StorageKitBundle\Tests\Support\HostBucketProfiles;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.5: `lint:container` (Symfony's CheckAliasValidityPass and the argument type checks included) passes with the new
 * ports as the bundle wires them, with the host's own download port, with a 1.4-style decorator of the deprecated
 * `FileDelivery` id, with a decorator of the port that also keeps `respond()`, and in a DDD host (dedicated entity
 * manager, no Instance Settings). The download controller calls the port the host chose and answers the same file;
 * a port service that implements neither API fails the build.
 */
final class PortsLintContainerKernelTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Closure(ContainerConfigurator): void, class-string|null, class-string|null, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'bundle ports only' => [static function (): void {
        }, null, null, false];
        yield 'host port (alias)' => [static function (ContainerConfigurator $container): void {
            $container->services()->set(CountingFileDelivery::class)->public()->args([service(NowoStorageKitExtension::FILE_DELIVERY_ID)]);
            $container->services()->alias(FileDeliveryInterface::class, CountingFileDelivery::class)->public();
        }, CountingFileDelivery::class, null, false];
        yield '1.4 decorator of the deprecated FileDelivery id' => [static function (ContainerConfigurator $container): void {
            $container->services()->set(LegacyDeliveryDecorator::class)->public()->decorate(FileDelivery::class)->args([service('.inner')]);
        }, null, LegacyDeliveryDecorator::class, false];
        yield 'decorator of the port with both APIs' => [static function (ContainerConfigurator $container): void {
            $container->services()->set(DualDeliveryDecorator::class)->public()->decorate(FileDeliveryInterface::class)->args([service('.inner'), service(NowoStorageKitExtension::FILE_DELIVERY_ID)]);
        }, DualDeliveryDecorator::class, null, false];
        yield 'DDD host' => [static function (ContainerConfigurator $container): void {
            $container->services()->set(HostBucketProfiles::class)->public();
        }, null, null, true];
    }

    /**
     * @param Closure(ContainerConfigurator): void $services
     * @param class-string|null $port the host port the controller calls (null: the bundle's FileDelivery)
     * @param class-string|null $legacy what the deprecated FileDelivery id gives (null: FileDelivery)
     */
    #[DataProvider('hosts')]
    #[IgnoreDeprecations]
    public function testLintContainerPassesAndTheControllerUsesThePortTheHostChose(Closure $services, ?string $port, ?string $legacy, bool $ddd): void
    {
        $kernel = $ddd
            ? $this->bootKernel(['config_profile_provider' => HostBucketProfiles::class, 'instance_profile' => 'primary', 'doctrine' => ['entity_manager' => 'storage_kit']], ['settings'], debug: true, extra: $services, orm: [
                'default_entity_manager' => 'default',
                'entity_managers'        => ['default' => ['connection' => 'default', 'auto_mapping' => true], 'storage_kit' => ['connection' => 'default']],
            ])
            : $this->bootKernel(debug: true, extra: $services);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('linted successfully', $tester->getDisplay());

        foreach ([FileDeliveryInterface::class, FileReferencesInterface::class, StoredImageResolverInterface::class, StoredFilesPersonalDataInterface::class] as $id) {
            self::assertTrue($this->container($kernel)->has($id), $id);
        }
        $controller = $this->service($kernel, FileDownloadController::class);
        $delivery   = (new ReflectionProperty($controller, 'delivery'))->getValue($controller);
        self::assertInstanceOf($port ?? FileDelivery::class, $delivery);
        self::assertInstanceOf($legacy ?? FileDelivery::class, $this->service($kernel, FileDelivery::class));

        if (!$ddd) {
            $this->profile($kernel);
        }
        $view   = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(20, 20), 'a.png');
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/' . $view->uuid . '/original');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame("default-src 'none'; sandbox", $client->getResponse()->headers->get('Content-Security-Policy'));
        if ($port !== null) {
            self::assertSame(1, (new ReflectionProperty($delivery, 'calls'))->getValue($delivery), 'the host port served it');
        }
        if ($legacy !== null) {
            $decorator = $this->service($kernel, FileDelivery::class);
            $file      = $this->service($kernel, StoredFileRepository::class)->findOneByUuid($view->uuid);
            self::assertNotNull($file);
            self::assertSame(200, $decorator->respond($file, 'original')->getStatusCode());
            self::assertSame(1, (new ReflectionProperty($decorator, 'calls'))->getValue($decorator), 'host code calling the deprecated id keeps its decorator');
        }
    }

    public function testAPortServiceThatImplementsNeitherApiFailsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('NowoStorageKitBundle: the download port service "' . NotADelivery::class . '" (' . NotADelivery::class . ') must implement "' . FileDeliveryInterface::class . '".');
        $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(NotADelivery::class)->public();
            $container->services()->alias(FileDeliveryInterface::class, NotADelivery::class)->public();
        });
    }
}
