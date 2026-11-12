<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection;

use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Command\AdoptInstanceBucketCommand;
use Nowo\StorageKitBundle\Command\BackfillUploaderKeysCommand;
use Nowo\StorageKitBundle\Command\ForgetRetiredProfilesCommand;
use Nowo\StorageKitBundle\Command\PurgeFilesCommand;
use Nowo\StorageKitBundle\Command\StorageSyncCommand;
use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Controller\FileDownloadController;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\InstanceSettingsPass;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Doctrine\TablePrefixListener;
use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Download\StoredImageResolver;
use Nowo\StorageKitBundle\Download\StoredImageResolverInterface;
use Nowo\StorageKitBundle\EventListener\InstanceSettingsListener;
use Nowo\StorageKitBundle\EventListener\RequestBoundaryListener;
use Nowo\StorageKitBundle\EventListener\StorageKitAccessListener;
use Nowo\StorageKitBundle\Form\Extension\StorageSettingsSectionTypeExtension;
use Nowo\StorageKitBundle\Form\StorageProfileType;
use Nowo\StorageKitBundle\Http\StorageCspSources;
use Nowo\StorageKitBundle\Integration\HttpHardening\StorageCspSourceProvider;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalData;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\PersonalData\UploaderKeyResolverInterface;
use Nowo\StorageKitBundle\PersonalData\UserIdUploaderKeyResolver;
use Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferenceListener;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Routing\StorageKitRouteLoader;
use Nowo\StorageKitBundle\Scope\DenyAllScopeAccessChecker;
use Nowo\StorageKitBundle\Scope\ScopeAccessCheckerInterface;
use Nowo\StorageKitBundle\Scope\ScopeResolverInterface;
use Nowo\StorageKitBundle\Scope\TargetScopeResolver;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Security\AllowAllStorageAccessChecker;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Security\RoleAccessChecker;
use Nowo\StorageKitBundle\Security\StorageAccessCheckerInterface;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\S3StorageConnectionFactory;
use Nowo\StorageKitBundle\Storage\StorageConnectionFactoryInterface;
use Nowo\StorageKitBundle\Storage\StorageProbe;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Twig\StorageKitTwigExtension;
use Nowo\StorageKitBundle\Ui\AdminPages;
use Nowo\StorageKitBundle\Ui\ProfileTester;
use Nowo\StorageKitBundle\Ui\StorageKitPages;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function array_key_exists;
use function array_key_first;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function trim;

use const PHP_INT_MAX;

/**
 * Loads `nowo_storage_kit`: storage, SSRF guard, profiles, contexts and references, uploads and downloads, purge,
 * moves, the PHP API, and (when enabled) the Storage / Move pages and the file endpoints. Controllers are not
 * autoconfigured: their routes come only from the `nowo_storage_kit` loader (REQ-SF-004 rule 7).
 */
final class NowoStorageKitExtension extends Extension implements PrependExtensionInterface
{
    /**
     * @param bool|null $instanceSettingsInstalled whether nowo-tech/instance-settings-bundle is installed (null = detect; tests)
     */
    public function __construct(
        private readonly ?bool $instanceSettingsInstalled = null,
    ) {
    }

    /** Service id of the bucket connection factory (tests replace it with an in-memory one). */
    public const CONNECTION_FACTORY_ID = 'nowo_storage_kit.connection_factory';

    /** Service id of the upload rate limiter factory. */
    public const LIMITER_ID = 'nowo_storage_kit.upload.limiter';

    /** Messenger's `WorkerMessageHandledEvent` (by name: symfony/messenger is optional). */
    public const WORKER_MESSAGE_HANDLED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent';

    /** Messenger's `WorkerMessageFailedEvent` (by name; audit LR-S-L2: a failed message is a boundary too). */
    public const WORKER_MESSAGE_FAILED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent';

    /** Messenger's `WorkerMessageReceivedEvent` (by name; 1.5.1 audit NM: a message is a unit of work in flight). */
    public const WORKER_MESSAGE_RECEIVED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent';

    /** Messenger's `WorkerMessageSkipEvent` (by name; dispatched by `messenger:failed:retry` only, 1.5.1 audit NM). */
    public const WORKER_MESSAGE_SKIPPED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent';

    /** Messenger's `WorkerStoppedEvent` (by name; 1.5.1 audit NM). */
    public const WORKER_STOPPED = 'Symfony\\Component\\Messenger\\Event\\WorkerStoppedEvent';

    /** Service id of the bundle's own download delivery (1.5; `FileDelivery` and `FileDeliveryInterface` are aliases). */
    public const FILE_DELIVERY_ID = 'nowo_storage_kit.file_delivery';

    /** Container parameter: `doctrine.entity_manager` (null = the default manager). */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_storage_kit.doctrine.entity_manager';

    /** Service id of the bounded DNS lookup of the SSRF guard. */
    public const DNS_LOOKUP_ID = 'nowo_storage_kit.dns_lookup';

    /**
     * `nowo_storage_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the bundle entities and the FormKit profile.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $mappings = [
                'mappings' => [
                    'NowoStorageKitBundle' => [
                        'type'      => 'attribute',
                        'dir'       => __DIR__ . '/../Entity',
                        'prefix'    => 'Nowo\StorageKitBundle\Entity',
                        'alias'     => 'NowoStorageKit',
                        'is_bundle' => false,
                    ],
                ],
            ];
            // 1.5: on doctrine.entity_manager when the host declares it (pinning the host's own default so the
            // prepended entry never becomes the default manager); StorageEntityManagerPass keeps it there only and
            // fails on an undeclared manager. Null (default) or the single-manager shorthand: as before.
            $entityManager = $this->rawEntityManager($container);
            if ($entityManager === null || ($entityManager === 'default' && !$this->hostDeclaresEntityManagers($container))) {
                $container->prependExtensionConfig('doctrine', ['orm' => $mappings]);
            } elseif (($hostDefault = $this->hostDefaultEntityManager($container, $entityManager)) !== null) {
                $container->prependExtensionConfig('doctrine', ['orm' => [
                    'default_entity_manager' => $hostDefault,
                    'entity_managers'        => [$entityManager => $mappings],
                ]]);
            }
        }
        $this->prependFormKitProfile($container);
    }

    /**
     * Registers the services from the processed configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /* @var array{profiles: array<string, array{dsn: string|null, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string|null, prefix: string, access_key: string|null, secret: string|null, path_style: bool, delivery: string}>, config_profile_provider: string|null, instance_profile: string|null, uri_scheme: string, endpoints: array{guard_instance_profile: bool, allowed_hosts: list<string>, allow_private: bool, resolve_dns: bool, dns_timeout: float, request_timeout: float}, upload: array{enabled: bool, path_prefix: string, access_roles: list<string>, max_bytes: int, max_pixels: int, variants: array<string, int>, rate_limit: int, rate_interval: string}, download: array{url_ttl: int, disposition: string}, purge: array{retention_days: int, batch_size: int, max_batches: int}, sync: array{batch_size: int, slice_seconds: int, stale_seconds: int}, scopes: array{enabled: bool, resolver: string|null, access_checker: string|null, path_prefix: string}, limits: array{upload_limit: string|null}, personal_data: array{uploader_key_resolver: string|null, user_provider: string}, doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $config */
        if (!$this->hasBundle($container, 'NowoDoctrineEncryptBundle') && !$container->hasExtension('nowo_doctrine_encrypt')) {
            throw new LogicException('NowoStorageKitBundle requires nowo-tech/doctrine-encrypt-bundle (bucket secrets are encrypted at rest): register Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle in config/bundles.php.');
        }
        if ($config['web_ui']['enabled'] && !$config['security']['allow_unauthenticated'] && !$this->hasBundle($container, 'SecurityBundle') && !$container->hasExtension('security')) {
            throw new LogicException('NowoStorageKitBundle: the storage pages require symfony/security-bundle (or security.allow_unauthenticated: true in dev / demo only).');
        }

        $container->setParameter('nowo_storage_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ENTITY_MANAGER_PARAMETER, $config['doctrine']['entity_manager']);
        $container->setParameter('nowo_storage_kit.web_ui.enabled', $config['web_ui']['enabled']);
        $container->setParameter('nowo_storage_kit.upload.enabled', $config['upload']['enabled']);
        $container->setParameter('nowo_storage_kit.scopes.enabled', $config['scopes']['enabled']);
        $container->setParameter('nowo_storage_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_storage_kit.endpoints.allow_private', $config['endpoints']['allow_private']);

        $container->registerForAutoconfiguration(FileContextInterface::class)->addTag(FileContextInterface::TAG);
        $container->registerForAutoconfiguration(FileReferenceSourceInterface::class)->addTag(FileReferenceSourceInterface::TAG);

        $this->registerStorage($container, $config);
        $this->registerFiles($container, $config);
        $this->registerLifecycle($container, $config);
        $this->registerAccess($container, $config);
        $this->registerHttp($container, $config);

        // 1.4 (FrankenPHP worker, kernel kept alive): the memos are cleared at the request boundary too, not only by
        // the services resetter (kernel.reset).
        $container->setDefinition(RequestBoundaryListener::class, (new Definition(RequestBoundaryListener::class, [
            new IteratorArgument([
                new Reference(StorageProfiles::class),
                new Reference(FileStorage::class),
                new Reference(FileContexts::class),
                new Reference(FileReferenceListener::class),
            ]),
        ]))
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::FINISH_REQUEST, 'method' => 'onKernelFinishRequest', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'method' => 'onKernelTerminateStarted', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'method' => 'onBoundary'])
            ->addTag('kernel.event_listener', ['event' => self::WORKER_MESSAGE_HANDLED, 'method' => 'onBoundary'])
            ->addTag('kernel.event_listener', ['event' => self::WORKER_MESSAGE_FAILED, 'method' => 'onBoundary'])
            // 1.5.1 (audit NM / NL2-v): every unit-of-work state change at PHP_INT_MAX, before any listener that could
            // throw or stop the propagation.
            ->addTag('kernel.event_listener', ['event' => self::WORKER_MESSAGE_RECEIVED, 'method' => 'onMessageReceived', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => self::WORKER_MESSAGE_HANDLED, 'method' => 'onMessageEnded', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => self::WORKER_MESSAGE_FAILED, 'method' => 'onMessageEnded', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => self::WORKER_MESSAGE_SKIPPED, 'method' => 'onMessageEnded', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => self::WORKER_STOPPED, 'method' => 'onWorkerStopped', 'priority' => PHP_INT_MAX])
            // 1.5: the end of the outermost console command is a boundary too (a long command calls reset() per item itself).
            ->addTag('kernel.event_listener', ['event' => ConsoleEvents::COMMAND, 'method' => 'onConsoleCommand', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => ConsoleEvents::ERROR, 'method' => 'onConsoleError', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => ConsoleEvents::TERMINATE, 'method' => 'onConsoleCommandEnded', 'priority' => PHP_INT_MAX])
            ->addTag('kernel.event_listener', ['event' => ConsoleEvents::TERMINATE, 'method' => 'onConsoleTerminate']));
    }

    /**
     * Default FormKit profile of the bundle forms: bundle translation domain, no auto help / placeholder keys, no
     * framework classes (the UiKit scaffolds paint the fields through their slots, REQ-UI-003).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoStorageKitBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoStorageKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerStorage(ContainerBuilder $container, array $config): void
    {
        /** @var array{profiles: array<string, array<string, mixed>>, config_profile_provider: string|null, instance_profile: string|null, endpoints: array{guard_instance_profile: bool, allowed_hosts: list<string>, allow_private: bool, resolve_dns: bool, dns_timeout: float, request_timeout: float}, scopes: array{enabled: bool, resolver: string|null}, doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool}} $config */
        $logger         = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $endpoints      = $config['endpoints'];
        $settingsBacked = $config['instance_profile'] === null;
        $webUi          = $config['web_ui']['enabled'];

        $container->setDefinition(StorageEntityManager::class, new Definition(StorageEntityManager::class, [new Reference('doctrine'), $config['doctrine']['entity_manager']]));
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$config['doctrine']['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
        $container->setDefinition(UploaderCollationListener::class, (new Definition(UploaderCollationListener::class, [new Reference(StorageEntityManager::class)]))
            ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchemaTable']));
        foreach ([StorageProfileRepository::class, StoredFileRepository::class, StorageSyncRepository::class] as $repository) {
            $container->setDefinition($repository, (new Definition($repository, [new Reference(StorageEntityManager::class)]))->setPublic(true));
        }

        $container->setDefinition(self::DNS_LOOKUP_ID, new Definition(HostnameDnsLookup::class, [$endpoints['dns_timeout']]));
        $container->setDefinition(EndpointGuard::class, (new Definition(EndpointGuard::class, [
            $endpoints['allowed_hosts'],
            $endpoints['allow_private'],
            $endpoints['resolve_dns'],
            new Reference(self::DNS_LOOKUP_ID),
            $endpoints['request_timeout'],
        ]))->setPublic(true));
        $container->setDefinition(S3StorageConnectionFactory::class, new Definition(S3StorageConnectionFactory::class, [
            new Reference(EndpointGuard::class),
            new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setAlias(self::CONNECTION_FACTORY_ID, S3StorageConnectionFactory::class)->setPublic(true);
        $container->setAlias(StorageConnectionFactoryInterface::class, self::CONNECTION_FACTORY_ID);
        $container->setDefinition(FileStorage::class, (new Definition(FileStorage::class, [new Reference(self::CONNECTION_FACTORY_ID)]))
            ->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition(StorageProbe::class, (new Definition(StorageProbe::class, [
            new Reference(self::CONNECTION_FACTORY_ID),
            new Reference(EndpointGuard::class),
            new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $logger,
        ]))->setPublic(true));

        $container->setDefinition(ConfigProfiles::class, (new Definition(ConfigProfiles::class, [$config['profiles'], $logger]))->setPublic(true));
        // 1.4 (rule 8): the named buckets are a port; the `profiles` tree stays the default.
        $profilesProvider = $config['config_profile_provider'];
        $container->setAlias(ConfigProfileProviderInterface::class, is_string($profilesProvider) && trim($profilesProvider) !== '' ? $profilesProvider : ConfigProfiles::class)->setPublic(true);
        $container->setDefinition(TargetScopeResolver::class, new Definition(TargetScopeResolver::class));
        $resolver = $config['scopes']['resolver'];
        $container->setAlias(ScopeResolverInterface::class, is_string($resolver) && trim($resolver) !== '' ? $resolver : TargetScopeResolver::class);
        $container->setDefinition(StorageProfiles::class, (new Definition(StorageProfiles::class, [
            new Reference(StorageProfileRepository::class),
            new Reference(StorageEntityManager::class),
            new Reference(ConfigProfileProviderInterface::class),
            new Reference(ScopeResolverInterface::class),
            $config['instance_profile'],
            $endpoints['guard_instance_profile'],
            $config['scopes']['enabled'],
            $settingsBacked ? new Reference(InstanceStorageSettings::class) : null,
        ]))->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']));
        $container->setParameter(InstanceSettingsPass::PARAMETER, $settingsBacked);
        if ($settingsBacked && !($this->instanceSettingsInstalled ?? interface_exists(InstanceSettingsInterface::class))) {
            // 1.3 (rule 8): InstanceSettings is only needed for the settings-backed instance bucket (2.0 moves it to suggest).
            throw new LogicException('NowoStorageKitBundle: the instance bucket is edited in Instance Settings (nowo_storage_kit.instance_profile is null), which needs nowo-tech/instance-settings-bundle: require it, or manage the instance bucket in the configuration (nowo_storage_kit.instance_profile).');
        }
        if ($settingsBacked) {
            $container->setDefinition(InstanceStorageSettings::class, (new Definition(InstanceStorageSettings::class, [
                new Reference(InstanceSettingsInterface::class),
                $endpoints['guard_instance_profile'],
            ]))->setPublic(true));
            $container->setDefinition(StorageSettingsSection::class, (new Definition(StorageSettingsSection::class, [
                new Reference(EndpointGuard::class),
                new Reference(InstanceStorageSettings::class),
                new Reference(StorageProfiles::class),
                $webUi ? new Reference('router') : null,
                $webUi ? new Reference(CsrfOnlyFormFactory::class) : null,
                $endpoints['guard_instance_profile'],
            ]))->setPublic(true)->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => StorageSettingsSection::PRIORITY]));
            $container->setDefinition(StorageSettingsSectionTypeExtension::class, (new Definition(StorageSettingsSectionTypeExtension::class))
                ->addTag('form.type_extension', ['extended_type' => SettingsSectionType::class]));
            $container->setDefinition(InstanceSettingsListener::class, (new Definition(InstanceSettingsListener::class, [
                new Reference(StorageProfiles::class),
                new Reference(FileStorage::class),
                new Reference(StorageEntityManager::class),
                new Reference(AuditRecorder::class),
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]))->addTag('kernel.event_listener', ['event' => SettingsChangedEvent::class]));
        }
        $container->setDefinition(AuditRecorder::class, (new Definition(AuditRecorder::class, [
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $logger,
        ]))->setPublic(true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerFiles(ContainerBuilder $container, array $config): void
    {
        /** @var array{uri_scheme: string, upload: array{path_prefix: string, max_bytes: int, max_pixels: int, variants: array<string, int>}, download: array{url_ttl: int, disposition: string}, limits: array{upload_limit: string|null}, personal_data: array{uploader_key_resolver: string|null, user_provider: string}} $config */
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $limit  = $config['limits']['upload_limit'];
        $keys   = $config['personal_data']['uploader_key_resolver'];
        if (is_string($keys) && trim($keys) !== '') {
            $container->setAlias(UploaderKeyResolverInterface::class, $keys);
        } else {
            $container->setDefinition(UploaderKeyResolverInterface::class, (new Definition(UserIdUploaderKeyResolver::class, [$logger]))->addTag('kernel.reset', ['method' => 'reset']));
        }

        $container->setDefinition(FileContexts::class, (new Definition(FileContexts::class, [new TaggedIteratorArgument(FileContextInterface::TAG)]))
            ->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition(FileUris::class, (new Definition(FileUris::class, [$config['uri_scheme'], $config['upload']['path_prefix']]))->setPublic(true));
        $container->setDefinition(FileReferences::class, (new Definition(FileReferences::class, [new Reference(StorageEntityManager::class), new Reference(FileUris::class)]))->setPublic(true));
        // 1.5 (rule 1): the reference port.
        $container->setAlias(FileReferencesInterface::class, FileReferences::class)->setPublic(true);
        $container->setDefinition(FileReferenceListener::class, (new Definition(FileReferenceListener::class, [
            new Reference(FileReferences::class),
            new Reference(FileUris::class),
            new TaggedIteratorArgument(FileReferenceSourceInterface::TAG),
        ]))
            ->addTag('doctrine.event_listener', ['event' => 'onFlush'])
            ->addTag('doctrine.event_listener', ['event' => 'postFlush'])
            ->addTag('kernel.reset', ['method' => 'reset']));

        $container->setDefinition(ImageProcessor::class, (new Definition(ImageProcessor::class, [
            $config['upload']['max_bytes'],
            $config['upload']['max_pixels'],
            Configuration::variants($config['upload']['variants']),
        ]))->setPublic(true));
        $container->setDefinition(ImageUploader::class, (new Definition(ImageUploader::class, [
            new Reference(FileContexts::class),
            new Reference(StorageProfiles::class),
            new Reference(FileStorage::class),
            new Reference(ImageProcessor::class),
            new Reference(StorageEntityManager::class),
            new Reference(AuditRecorder::class),
            is_string($limit) && trim($limit) !== '' ? new Reference($limit) : null,
            $logger,
            new Reference(UploaderKeyResolverInterface::class),
        ]))->setPublic(true));
        // 1.3 (rule 1): the upload port (DTO return, after-commit audit).
        $container->setAlias(ImageUploaderInterface::class, ImageUploader::class)->setPublic(true);
        $container->setDefinition(FileUrls::class, (new Definition(FileUrls::class, [new Reference('router')]))->setPublic(true));
        $container->setDefinition(self::FILE_DELIVERY_ID, new Definition(FileDelivery::class, [
            new Reference(StorageProfiles::class),
            new Reference(FileStorage::class),
            $config['download']['url_ttl'],
            $config['download']['disposition'],
            new Reference(StoredFileRepository::class),
        ]));
        $container->setAlias(FileDelivery::class, self::FILE_DELIVERY_ID)->setPublic(true);
        // 1.5 (rule 3): the framework-free download port and its HttpFoundation adapter. A host alias of the port wins
        // (FileDeliveryPortsPass checks the service behind it).
        $container->setAlias(FileDeliveryInterface::class, self::FILE_DELIVERY_ID)->setPublic(true);
        $container->setDefinition(FileDeliveryResponder::class, (new Definition(FileDeliveryResponder::class))->setPublic(true));
        $container->setDefinition(StoredImageResolver::class, (new Definition(StoredImageResolver::class, [
            new Reference(StoredFileRepository::class),
            new Reference(FileContexts::class),
            new Reference(FileUrls::class),
            new Reference(FileUris::class),
        ]))->setPublic(true));
        // 1.5 (rule 1): the renderer port.
        $container->setAlias(StoredImageResolverInterface::class, StoredImageResolver::class)->setPublic(true);
        $container->setDefinition(StoredFilesPersonalData::class, (new Definition(StoredFilesPersonalData::class, [
            new Reference(StoredFileRepository::class),
            new Reference(StorageEntityManager::class),
            new Reference(FileUrls::class),
            new Reference(AuditRecorder::class),
            new Reference(UploaderKeyResolverInterface::class),
        ]))->setPublic(true));
        // 1.5 (rule 1): the GDPR port (strings in, scalars out; the erasure never flushes).
        $container->setAlias(StoredFilesPersonalDataInterface::class, StoredFilesPersonalData::class)->setPublic(true);
        $container->setDefinition(StorageCspSources::class, (new Definition(StorageCspSources::class, [new Reference(ConfigProfileProviderInterface::class)]))->setPublic(true));
        if (interface_exists(CspSourceProviderInterface::class)) {
            $container->setDefinition(StorageCspSourceProvider::class, (new Definition(StorageCspSourceProvider::class, [new Reference(StorageCspSources::class)]))
                ->addTag(CspSourceProviderInterface::TAG));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerLifecycle(ContainerBuilder $container, array $config): void
    {
        /** @var array{instance_profile: string|null, purge: array{retention_days: int, batch_size: int, max_batches: int}, sync: array{batch_size: int, slice_seconds: int, stale_seconds: int}, personal_data: array{uploader_key_resolver: string|null, user_provider: string}} $config */
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->setDefinition(FilePurger::class, (new Definition(FilePurger::class, [
            new Reference(StorageEntityManager::class),
            new Reference(FileReferences::class),
            new Reference(FileContexts::class),
            new Reference(StorageProfiles::class),
            new Reference(FileStorage::class),
            new Reference(AuditRecorder::class),
            new TaggedIteratorArgument(FileReferenceSourceInterface::TAG),
            $config['purge']['batch_size'],
            $config['purge']['max_batches'],
            $logger,
        ]))->setPublic(true));
        $container->setDefinition(StorageSyncer::class, (new Definition(StorageSyncer::class, [
            new Reference(StorageSyncRepository::class),
            new Reference(StorageEntityManager::class),
            new Reference(StorageProfiles::class),
            new Reference(FileStorage::class),
            new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['sync']['batch_size'],
            $config['sync']['stale_seconds'],
            $logger,
        ]))->setPublic(true));
        $container->setDefinition(StorageMove::class, (new Definition(StorageMove::class, [
            new Reference(StorageProfileRepository::class),
            new Reference(StorageSyncRepository::class),
            new Reference(StorageProfiles::class),
            new Reference(StorageSyncer::class),
            new Reference(StorageEntityManager::class),
            new Reference(AuditRecorder::class),
            new Reference('messenger.default_bus', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['instance_profile'] === null ? new Reference(InstanceStorageSettings::class) : null,
        ]))->setPublic(true));
        // Removed by MessengerIntegrationPass when the host has no message bus.
        $container->setDefinition(RunStorageSyncHandler::class, (new Definition(RunStorageSyncHandler::class, [
            new Reference(StorageSyncRepository::class),
            new Reference(StorageSyncer::class),
            new Reference('messenger.default_bus'),
            $config['sync']['slice_seconds'],
        ]))->addTag('messenger.message_handler', ['handles' => RunStorageSyncMessage::class]));

        $container->setDefinition(PurgeFilesCommand::class, (new Definition(PurgeFilesCommand::class, [new Reference(FilePurger::class), $config['purge']['retention_days']]))
            ->addTag('console.command', ['command' => PurgeFilesCommand::NAME]));
        $container->setDefinition(BackfillUploaderKeysCommand::class, (new Definition(BackfillUploaderKeysCommand::class, [
            new Reference(StorageEntityManager::class),
            new Reference(UploaderKeyResolverInterface::class),
            new Reference($config['personal_data']['user_provider'], ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('console.command', ['command' => BackfillUploaderKeysCommand::NAME]));
        $container->setDefinition(StorageSyncCommand::class, (new Definition(StorageSyncCommand::class, [new Reference(StorageProfileRepository::class), new Reference(StorageSyncer::class)]))
            ->addTag('console.command', ['command' => StorageSyncCommand::NAME]));
        $container->setDefinition(ForgetRetiredProfilesCommand::class, (new Definition(ForgetRetiredProfilesCommand::class, [new Reference(StorageProfileRepository::class), new Reference(StorageMove::class)]))
            ->addTag('console.command', ['command' => ForgetRetiredProfilesCommand::NAME]));
        if ($config['instance_profile'] === null) {
            $container->setDefinition(AdoptInstanceBucketCommand::class, (new Definition(AdoptInstanceBucketCommand::class, [
                new Reference(StorageProfileRepository::class),
                new Reference(InstanceStorageSettings::class),
                new Reference(StorageProfiles::class),
                new Reference(StorageEntityManager::class),
                new Reference(AuditRecorder::class),
            ]))->addTag('console.command', ['command' => AdoptInstanceBucketCommand::NAME]));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerAccess(ContainerBuilder $container, array $config): void
    {
        /** @var array{upload: array{access_roles: list<string>}, scopes: array{access_checker: string|null}, security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $config */
        $security = $config['security'];
        $customId = $security['access_checker'];
        if (is_string($customId) && trim($customId) !== '') {
            $container->setAlias(StorageAccessCheckerInterface::class, $customId);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllStorageAccessChecker::class, new Definition(AllowAllStorageAccessChecker::class));
            $container->setAlias(StorageAccessCheckerInterface::class, AllowAllStorageAccessChecker::class);
        } else {
            $container->setDefinition('nowo_storage_kit.access_checker.instance', new Definition(RoleAccessChecker::class, [
                new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                Configuration::roles($security['access_roles']),
            ]));
            $container->setAlias(StorageAccessCheckerInterface::class, 'nowo_storage_kit.access_checker.instance');
        }
        $container->setDefinition('nowo_storage_kit.access_checker.upload', new Definition(RoleAccessChecker::class, [
            new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            Configuration::roles($config['upload']['access_roles']),
        ]));
        $container->setDefinition(DenyAllScopeAccessChecker::class, new Definition(DenyAllScopeAccessChecker::class));
        $scopeChecker = $config['scopes']['access_checker'];
        $container->setAlias(ScopeAccessCheckerInterface::class, is_string($scopeChecker) && trim($scopeChecker) !== '' ? $scopeChecker : DenyAllScopeAccessChecker::class);

        $container->setDefinition(AccessGuard::class, (new Definition(AccessGuard::class, [
            new Reference(StorageAccessCheckerInterface::class),
            new Reference(ScopeAccessCheckerInterface::class),
            new Reference('nowo_storage_kit.access_checker.upload'),
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerHttp(ContainerBuilder $container, array $config): void
    {
        /** @var array{upload: array{enabled: bool, path_prefix: string, max_bytes: int, rate_limit: int, rate_interval: string}, scopes: array{enabled: bool, path_prefix: string}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>}} $config */
        $webUi      = $config['web_ui'];
        $upload     = $config['upload'];
        $translator = new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->setDefinition(StorageKitRouteLoader::class, (new Definition(StorageKitRouteLoader::class, [
            $webUi['enabled'],
            $webUi['path_prefix'],
            $config['scopes']['enabled'],
            $config['scopes']['path_prefix'],
            $upload['enabled'],
            $upload['path_prefix'],
        ]))->addTag('routing.loader'));

        $container->setDefinition(UploadEndpoint::class, (new Definition(UploadEndpoint::class, [
            new Reference(AccessGuard::class),
            new Reference(FileContexts::class),
            new Reference(StorageProfiles::class),
            new Reference(FileUrls::class),
            new Reference('router'),
            new Reference('security.csrf.token_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(ImageProcessor::class),
            $webUi['enabled'],
        ]))->setPublic(true));
        $container->setDefinition(StorageKitTwigExtension::class, (new Definition(StorageKitTwigExtension::class, [
            new Reference(UploadEndpoint::class),
            new Reference(FileUrls::class),
            new Reference(StoredImageResolver::class),
            new Reference(AccessGuard::class),
        ]))->addTag('twig.extension'));

        if ($upload['enabled']) {
            $container->setDefinition(self::LIMITER_ID, new Definition(RateLimiterFactory::class, [
                ['id' => 'nowo_storage_kit_upload', 'policy' => 'sliding_window', 'limit' => $upload['rate_limit'], 'interval' => $upload['rate_interval']],
                new Definition(CacheStorage::class, [new Reference('cache.app')]),
            ]));
            // REQ-SF-004 rule 7: not autoconfigured; routes only from the loader.
            $container->setDefinition(FileUploadController::class, (new Definition(FileUploadController::class, [
                new Reference(AccessGuard::class),
                new Reference('security.csrf.token_manager'),
                new Reference(self::LIMITER_ID),
                new Reference(ImageUploaderInterface::class),
                new Reference(FileUrls::class),
                new Reference(FileUris::class),
                $upload['max_bytes'],
                $translator,
            ]))->setAutoconfigured(false)->setPublic(true)->addTag('controller.service_arguments'));
            $container->setDefinition(FileDownloadController::class, (new Definition(FileDownloadController::class, [
                new Reference(AccessGuard::class),
                new Reference(StoredFileRepository::class),
                new Reference(FileContexts::class),
                new Reference(FileDeliveryInterface::class),
                new Reference(FileDeliveryResponder::class),
            ]))->setAutoconfigured(false)->setPublic(true)->addTag('controller.service_arguments'));
        }

        if (!$webUi['enabled']) {
            return;
        }
        $container->setDefinition(StorageKitPages::class, new Definition(StorageKitPages::class, [$webUi['layout_template'], $webUi['templates']]));
        $container->setDefinition(AdminPages::class, new Definition(AdminPages::class, [new Reference(StorageKitPages::class), new Reference(StorageProfiles::class)]));
        $container->setDefinition(ProfileTester::class, new Definition(ProfileTester::class, [
            new Reference(StorageProfiles::class),
            new Reference(StorageProbe::class),
            new Reference(StorageEntityManager::class),
            new Reference(AuditRecorder::class),
            $translator,
        ]));
        $container->setDefinition(StorageProfileType::class, (new Definition(StorageProfileType::class, [new Reference(EndpointGuard::class)]))->addTag('form.type'));
        $container->setDefinition(StorageKitAccessListener::class, (new Definition(StorageKitAccessListener::class, [
            new Reference(AccessGuard::class),
            $webUi['path_prefix'],
        ]))->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => StorageKitAccessListener::PRIORITY]));

        $common = [
            '$guard'       => new Reference(AccessGuard::class),
            '$resolver'    => new Reference(StorageProfiles::class),
            '$profiles'    => new Reference(StorageProfileRepository::class),
            '$files'       => new Reference(StoredFileRepository::class),
            '$tester'      => new Reference(ProfileTester::class),
            '$formFactory' => new Reference('form.factory'),
            '$csrfForms'   => new Reference(CsrfOnlyFormFactory::class),
            '$adminPages'  => new Reference(AdminPages::class),
            '$pages'       => new Reference(StorageKitPages::class),
            '$translator'  => $translator,
        ];
        // REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the routes come only from
        // the `nowo_storage_kit` loader. Autowired only for AbstractController::setContainer().
        $container->setDefinition(StorageSettingsController::class, $this->pageController(StorageSettingsController::class, $common + [
            '$entityManager' => new Reference(StorageEntityManager::class),
            '$audit'         => new Reference(AuditRecorder::class),
        ]));
        $container->setDefinition(StorageMoveController::class, $this->pageController(StorageMoveController::class, $common + [
            '$move' => new Reference(StorageMove::class),
        ]));
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $arguments
     */
    private function pageController(string $class, array $arguments): Definition
    {
        return (new Definition($class, $arguments))
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber');
    }

    /**
     * Registers the FormKit profile {@see NowoStorageKitBundle::FORM_KIT_PROFILE} unless the host defines it.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }
        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoStorageKitBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }
        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoStorageKitBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * Security / feature switches must be literal booleans (an env placeholder would read as true at compile time).
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $switches = [
            'upload.enabled'                   => $config['upload']['enabled'] ?? null,
            'scopes.enabled'                   => $config['scopes']['enabled'] ?? null,
            'web_ui.enabled'                   => $config['web_ui']['enabled'] ?? null,
            'security.allow_unauthenticated'   => $config['security']['allow_unauthenticated'] ?? null,
            'endpoints.allow_private'          => $config['endpoints']['allow_private'] ?? null,
            'endpoints.guard_instance_profile' => $config['endpoints']['guard_instance_profile'] ?? null,
            'endpoints.resolve_dns'            => $config['endpoints']['resolve_dns'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_storage_kit.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for these switches.', $path));
            }
        }
    }

    /**
     * `doctrine.entity_manager` from the raw configs (prepend runs before the configuration is processed).
     */
    private function rawEntityManager(ContainerBuilder $container): ?string
    {
        $name = null;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (isset($config['doctrine']) && is_array($config['doctrine']) && array_key_exists('entity_manager', $config['doctrine'])) {
                $value = $config['doctrine']['entity_manager'];
                $name  = is_string($value) && $value !== '' ? $value : null;
            }
        }

        return $name;
    }

    /**
     * Whether the host declares `doctrine.orm.entity_managers` (else it uses the single-manager shorthand).
     */
    private function hostDeclaresEntityManagers(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $orm = is_array($config['orm'] ?? null) ? $config['orm'] : [];
            if (is_array($orm['entity_managers'] ?? null) && $orm['entity_managers'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The host's default entity manager (its `default_entity_manager`, else the first declared one) when the host
     * declares `$name` under `doctrine.orm.entity_managers`; null otherwise (the pass then reports the unknown name).
     */
    private function hostDefaultEntityManager(ContainerBuilder $container, string $name): ?string
    {
        $declared = false;
        $default  = null;
        $first    = null;
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $orm = is_array($config['orm'] ?? null) ? $config['orm'] : [];
            if (is_string($orm['default_entity_manager'] ?? null)) {
                $default = $orm['default_entity_manager'];
            }
            $managers = $orm['entity_managers'] ?? null;
            if (is_array($managers) && $managers !== []) {
                $first ??= (string) array_key_first($managers);
                $declared = $declared || isset($managers[$name]);
            }
        }

        return $declared ? ($default ?? $first) : null;
    }

    private function hasBundle(ContainerBuilder $container, string $name): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return is_array($bundles) && array_key_exists($name, $bundles);
    }
}
