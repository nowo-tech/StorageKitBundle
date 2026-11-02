<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getenv;
use function in_array;
use function is_string;
use function md5;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: Framework (optional Messenger on an in-memory transport, in-memory lock), Security
 * (http_basic, in-memory users), Twig + Twig Extra, Doctrine on in-memory SQLite (or STORAGE_KIT_TEST_DATABASE_URL),
 * DoctrineEncrypt (Halite key in the cache dir), FormKit, UiKit and the bundle. Configuration and routes per test.
 */
final class StorageKitTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param Closure(ContainerConfigurator): void $services extension configs and services
     * @param Closure(RoutingConfigurator): void $routes routes
     * @param list<string> $without bundles / features to leave out (security, messenger, csrf, encrypt)
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        private readonly array $without = [],
        bool $debug = false,
    ) {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if (!in_array('security', $this->without, true)) {
            yield new SecurityBundle();
        }
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        if (!in_array('encrypt', $this->without, true)) {
            yield new NowoDoctrineEncryptBundle();
        }
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoStorageKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * One container class per test kernel (see MailKit: lazy-ghost classes of removed cache dirs).
     */
    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . '_' . md5($this->id);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function databaseUrl(): string
    {
        $url = getenv('STORAGE_KIT_TEST_DATABASE_URL');

        return is_string($url) && $url !== '' ? $url : 'sqlite:///:memory:';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-storage-kit-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $csrf      = !in_array('csrf', $this->without, true);
        $framework = [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'csrf_protection'       => $csrf,
            'form'                  => ['csrf_protection' => $csrf],
            'validation'            => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'translator'            => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations', 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
            'http_client'           => true,
            'lock'                  => 'in-memory',
            'mailer'                => ['enabled' => false],
        ];
        $framework['messenger'] = in_array('messenger', $this->without, true)
            ? ['enabled' => false]
            : ['transports' => ['async' => 'in-memory://'], 'routing' => [RunStorageSyncMessage::class => 'async']];
        $container->extension('framework', $framework);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', [
            'dbal' => ['url' => self::databaseUrl()],
            'orm'  => ['mappings' => ['Tests' => [
                'type'      => 'attribute',
                'dir'       => '%kernel.project_dir%/tests/Fixtures/Entity',
                'prefix'    => 'Nowo\StorageKitBundle\Tests\Fixtures\Entity',
                'is_bundle' => false,
            ]]],
        ]);
        if (!in_array('encrypt', $this->without, true)) {
            $container->extension('nowo_doctrine_encrypt', [
                'default_profile'      => 'default',
                'secret_permissions'   => ['enabled' => false],
                'generate_missing_key' => true,
                'on_decrypt_failure'   => 'null',
                'profiles'             => ['default' => ['encryptor_class' => 'Halite', 'secret_directory_path' => self::baseDir() . '/' . $this->id . '/keys']],
            ]);
        }
        if (!in_array('security', $this->without, true)) {
            $user = static fn (string ...$roles): array => ['password' => 'pw', 'roles' => $roles];
            $container->extension('security', [
                'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'      => $user('ROLE_ADMIN', 'ROLE_USER'),
                    'alice'      => $user('ROLE_USER'),
                    'bob'        => $user('ROLE_USER'),
                    'scopeadmin' => $user('ROLE_USER'),
                    'guest'      => $user('ROLE_GUEST'),
                ]]]],
                'firewalls' => ['main' => ['lazy' => true, 'http_basic' => true]],
            ]);
        }
        $container->services()->set('logger', NullLogger::class);
        ($this->services)($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        ($this->routes)($routes);
    }
}
