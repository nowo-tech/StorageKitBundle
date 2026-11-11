<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Ddd;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Command\AdoptInstanceBucketCommand;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\InstanceSettingsPass;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Http\StorageCspSources;
use Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Nowo\StorageKitBundle\Tests\Fixtures\Boards;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\HostBucketProfiles;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_filter;
use function array_keys;
use function array_values;
use function gc_collect_cycles;
use function memory_get_usage;

/**
 * 1.4 (architecture bar rule 8): a DDD host without Instance Settings — NowoInstanceSettingsBundle not registered —
 * whose named buckets come from its own {@see ConfigProfileProviderInterface}; the instance bucket is pinned to one of
 * them. The first upload creates the profile row without flushing the host's unit of work, and a kept-alive kernel
 * (FrankenPHP worker mode, kernel reset off: `http_kernel` handle / terminate, no services resetter) never answers a
 * request with an access decision memoised for an earlier one.
 */
final class HostProvidersKernelTest extends KernelTestCase
{
    public function testBootsWithoutInstanceSettingsOnTheHostBuckets(): void
    {
        $kernel    = $this->bootWithout();
        $container = $this->container($kernel);

        self::assertFalse($container->has(InstanceSettingsInterface::class));
        self::assertFalse($container->has(StorageSettingsSection::class));
        self::assertFalse($container->has(InstanceStorageSettings::class));
        self::assertFalse($container->getParameter(InstanceSettingsPass::PARAMETER));
        $provider = $this->service($kernel, ConfigProfileProviderInterface::class);
        self::assertInstanceOf(HostBucketProfiles::class, $provider);
        self::assertFalse($container->has(AdoptInstanceBucketCommand::class));

        // The first upload creates the instance row of `primary` (DBAL insert): the host's pending entity stays pending.
        $manager = $this->entityManager($kernel);
        $note    = new Note('b1', 'pending');
        $manager->persist($note);
        $view = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');

        self::assertTrue($manager->getUnitOfWork()->isScheduledForInsert($note), 'still pending');
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $manager->getClassMetadata(Note::class)->getTableName()));
        $row = $manager->getRepository(StorageProfile::class)->findOneBy(['configName' => 'primary']);
        self::assertInstanceOf(StorageProfile::class, $row);
        self::assertTrue($manager->contains($row));
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_profile'));
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file WHERE uuid = ?', [$view->uuid]));
        self::assertNotEmpty($this->buckets($kernel)->objects('host-files', 'http://minio:9000'));
        self::assertGreaterThan(0, $provider->lookups);

        // A second upload reuses the row.
        $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(12, 12), 'b.png');
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_profile'));

        // Redirect-delivered host buckets open the CSP; streamed ones do not.
        $csp = $this->service($kernel, StorageCspSources::class);
        self::assertSame(['https://cdn.example.test'], $csp->imgSrc());
    }

    public function testKeptAliveKernelNeverReusesAnAccessDecisionOfAnEarlierRequest(): void
    {
        $kernel = $this->bootWithout();
        $view   = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $boards   = $this->service($kernel, Boards::class);
        $provider = $this->service($kernel, HostBucketProfiles::class);
        $before   = 0;

        for ($i = 0; $i < 600; ++$i) {
            $user = $i % 2 === 0 ? 'alice' : 'guest';
            if ($i === 300) {
                // The host revokes alice's access between two requests of the same worker.
                $boards->boards['b1']['readers'] = array_values(array_filter($boards->boards['b1']['readers'], static fn (string $reader): bool => $reader !== 'alice'));
            }
            $lookups  = $provider->lookups;
            $request  = Request::create('/storage/files/' . $view->uuid . '/original', server: ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']);
            $response = $http->handle($request);
            $expected = $user === 'alice' && $i < 300 ? 200 : 404;
            self::assertSame($expected, $response->getStatusCode(), $user . ' #' . $i);
            $http->terminate($request, $response);
            if ($expected === 200) {
                self::assertGreaterThan($lookups, $provider->lookups, 'the host provider is asked on every request');
            }

            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 500 requests.');
        self::assertSame(['b1', 'b2', 'acme', 'pub'], array_keys($boards->boards));
    }

    private function bootWithout(): StorageKitTestKernel
    {
        return $this->bootKernel(
            ['config_profile_provider' => HostBucketProfiles::class, 'instance_profile' => 'primary'],
            ['settings'],
            extra: static function (ContainerConfigurator $container): void {
                $container->services()->set(HostBucketProfiles::class)->public();
            },
        );
    }
}
