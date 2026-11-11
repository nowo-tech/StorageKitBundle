<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection;

use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Ui\StorageKitPages;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_keys;
use function array_values;
use function arsort;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strtotime;
use function trim;

/**
 * Configuration tree of `nowo_storage_kit` (REQ-SF-006: strict nodes, safe defaults, fail-closed validation).
 */
final class Configuration implements ConfigurationInterface
{
    /** Extension alias / root key. */
    public const ALIAS = 'nowo_storage_kit';

    /** Default URL prefix of the instance Storage / Move pages. */
    public const DEFAULT_ADMIN_PREFIX = '/admin/storage';

    /** Default URL prefix of the scope Storage / Move pages. */
    public const DEFAULT_SCOPE_PREFIX = '/storage/scopes';

    /** Default URL prefix of the upload / download endpoints. */
    public const DEFAULT_FILES_PREFIX = '/storage';

    /** Name syntax of a `profiles` entry. */
    public const PROFILE_NAME_PATTERN = '/^[a-z0-9_]{1,64}$/';

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->info('Nowo Storage Kit — S3-compatible buckets per scope, image uploads, reference-aware purge, verified bucket moves.')
            ->children()
                ->append($this->profilesNode())
                ->scalarNode('instance_profile')
                    ->info('Name of a "profiles" entry that is the instance bucket (managed by the configuration: its Storage page is read-only). Null = the instance bucket is edited on the Storage page (stored encrypted).')
                    ->defaultNull()
                ->end()
                ->scalarNode('config_profile_provider')
                    ->info('Service id of your Nowo\\StorageKitBundle\\Profile\\ConfigProfileProviderInterface (1.4): the named buckets of "profiles" and "instance_profile" come from it instead of the "profiles" tree (secret manager, tenant configuration). Null = the "profiles" tree.')
                    ->defaultNull()
                ->end()
                ->scalarNode('uri_scheme')
                    ->info('Scheme of the file URIs kept in texts: "storage-file" gives ![alt](storage-file:<uuid>).')
                    ->defaultValue('storage-file')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(FileUris::SCHEME_PATTERN, $v) !== 1)
                        ->thenInvalid('nowo_storage_kit.uri_scheme must be 2-32 characters: a lowercase letter, then letters, digits, "+", "." or "-"; got %s.')
                    ->end()
                ->end()
                ->append($this->endpointsNode())
                ->append($this->uploadNode())
                ->arrayNode('download')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('url_ttl')
                            ->info('Lifetime of a pre-signed download URL (redirect-delivered profiles) and of the private browser cache, in seconds.')
                            ->defaultValue(300)
                            ->min(60)
                            ->max(3600)
                        ->end()
                        ->enumNode('disposition')
                            ->info('Content-Disposition of downloads. "attachment" (default) still renders in <img>; streamed files are always sandboxed and nosniff.')
                            ->values(['attachment', 'inline'])
                            ->defaultValue('attachment')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('purge')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('retention_days')
                            ->info('Days an orphan (a file no text points at) is kept before nowo:storage-kit:purge deletes it.')
                            ->defaultValue(FilePurger::RETENTION_DAYS)
                            ->min(1)
                            ->max(365)
                        ->end()
                        ->integerNode('batch_size')
                            ->info('Rows per statement of the purge (keyset batches).')
                            ->defaultValue(FilePurger::BATCH)
                            ->min(10)
                            ->max(5000)
                        ->end()
                        ->integerNode('max_batches')
                            ->info('Batches per purge step and run at most (a large backlog is spread over runs).')
                            ->defaultValue(FilePurger::MAX_BATCHES)
                            ->min(1)
                            ->max(10_000)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sync')
                    ->info('Bucket moves (Move page and nowo:storage-kit:sync).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('batch_size')
                            ->info('Files read per query.')
                            ->defaultValue(StorageSyncer::BATCH)
                            ->min(1)
                            ->max(500)
                        ->end()
                        ->integerNode('slice_seconds')
                            ->info('Seconds one Messenger message runs a sync before queueing the next slice.')
                            ->defaultValue(RunStorageSyncHandler::SLICE_SECONDS)
                            ->min(10)
                            ->max(3600)
                        ->end()
                        ->integerNode('stale_seconds')
                            ->info('A running sync not updated for this long is taken over (its worker died); also the lock TTL.')
                            ->defaultValue(StorageSyncer::STALE_SECONDS)
                            ->min(30)
                            ->max(3600)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('scopes')
                    ->info('Buckets per scope (organisation, tenant…): a scope resolver maps upload contexts to scopes; a scope access checker decides who manages a scope\'s storage.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('resolver')
                            ->info('Service id implementing ScopeResolverInterface; null = the scope the file context reports.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing ScopeAccessCheckerInterface (required when scopes are enabled).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix of the scope Storage / Move pages (must not be under web_ui.path_prefix).')
                            ->defaultValue(self::DEFAULT_SCOPE_PREFIX)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !self::isPath($v))
                                ->thenInvalid('nowo_storage_kit.scopes.path_prefix must be an absolute path such as "/storage/scopes", got %s.')
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => ($v['enabled'] ?? false) === true && (!is_string($v['access_checker'] ?? null) || trim($v['access_checker']) === ''))
                        ->thenInvalid('nowo_storage_kit.scopes.access_checker must be set when scopes are enabled (nobody could manage a scope otherwise).')
                    ->end()
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('upload_limit')
                            ->info('Service id implementing UploadLimitInterface (a plan quota per owner); null = no limit beyond upload.max_bytes.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('personal_data')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('uploader_key_resolver')
                            ->info('Service id implementing PersonalData\\UploaderKeyResolverInterface (the stable key stored with each upload, matched by the GDPR export / erasure); null = the user\'s getId().')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('user_provider')
                            ->info('Service id of the user provider nowo:storage-kit:backfill-uploader-keys loads users with (by stored user identifier).')
                            ->defaultValue('security.user_providers')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->info('Prefix of the bundle table and index names (REQ-ORM-002): "app_" gives app_storage_kit_file.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/', $v) !== 1)
                                ->thenInvalid('nowo_storage_kit.doctrine.table_prefix must contain only letters, digits and underscores, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('entity_manager')
                            ->info('Keep null: a dedicated entity manager is not supported yet (the mapping stays on the default manager).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->info('Storage and Move pages (REQ-UI-001 / REQ-UI-003).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('false = no pages (no routes, no controllers); the upload endpoint, CLI and PHP API stay available.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix of the instance pages; protect it with security.access_control in the host as well.')
                            ->defaultValue(self::DEFAULT_ADMIN_PREFIX)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !self::isPath($v))
                                ->thenInvalid('nowo_storage_kit.web_ui.path_prefix must be an absolute path such as "/admin/storage", got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout the pages extend. Null (default) = nowo_ui_kit.layouts.admin.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template): settings, move. Extend @!NowoStorageKitBundle/... and override a block.')
                            ->useAttributeAsKey('page')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(StorageKitPages::TEMPLATES)) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Unknown nowo_storage_kit.web_ui.templates page(s) "%s"; allowed: %s.', implode('", "', array_diff(array_keys($v), array_keys(StorageKitPages::TEMPLATES))), implode(', ', array_keys(StorageKitPages::TEMPLATES))));
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('REQ-UI-002 private access of the instance pages.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('Roles that open the instance Storage / Move pages (any of them). Must not be empty unless access_checker is set or allow_unauthenticated is true.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing StorageAccessCheckerInterface; null = role-based default.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV / DEMO only (refused without kernel.debug): open the instance pages without login.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            if (($v['allow_unauthenticated'] ?? false) === true || (is_string($v['access_checker'] ?? null) && trim($v['access_checker']) !== '')) {
                                return false;
                            }

                            return self::roles($v['access_roles'] ?? []) === [];
                        })
                        ->thenInvalid('nowo_storage_kit.security.access_roles must contain at least one non-empty role when no access_checker is set and allow_unauthenticated is false (an empty list would deny everybody).')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => self::hostProfiles($v) && is_array($v['profiles'] ?? null) && $v['profiles'] !== [])
                ->thenInvalid('nowo_storage_kit.profiles and config_profile_provider are exclusive: the provider replaces the "profiles" tree (return those buckets from your provider).')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => is_string($v['instance_profile'] ?? null) && !self::hostProfiles($v) && !isset($v['profiles'][$v['instance_profile']]))
                ->then(static function (array $v): never {
                    throw new InvalidConfigurationException(sprintf('nowo_storage_kit.instance_profile "%s" is not a configured profile (nowo_storage_kit.profiles).', (string) $v['instance_profile']));
                })
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => self::nested($v['scopes']['path_prefix'] ?? '', $v['web_ui']['path_prefix'] ?? '') || self::nested($v['upload']['path_prefix'] ?? '', $v['web_ui']['path_prefix'] ?? ''))
                ->thenInvalid('nowo_storage_kit.scopes.path_prefix and upload.path_prefix must not be under web_ui.path_prefix (the instance-page access rule would apply to them).')
            ->end();

        return $treeBuilder;
    }

    /**
     * Whether the named buckets come from a host `config_profile_provider` (their names are known only at runtime).
     *
     * @param array<array-key, mixed> $v
     */
    private static function hostProfiles(array $v): bool
    {
        $provider = $v['config_profile_provider'] ?? null;

        return is_string($provider) && trim($provider) !== '';
    }

    /**
     * Non-empty role strings of a configured list.
     *
     * @return list<string>
     */
    public static function roles(mixed $roles): array
    {
        $list = [];
        foreach (is_array($roles) ? array_values($roles) : [] as $role) {
            if (is_string($role) && trim($role) !== '') {
                $list[] = trim($role);
            }
        }

        return $list;
    }

    private function profilesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('profiles'))->getRootNode();
        $node
            ->info('Buckets set by operations (trusted configuration, not checked by the SSRF guard). Either "dsn" (s3://KEY:SECRET@host/bucket/prefix?region=…&path_style=1&tls=0&public_endpoint=…, e.g. %env(STORAGE_KIT_DSN)%) or the fields. Credentials belong in environment variables / secrets, never in committed YAML.')
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->children()
                    ->scalarNode('dsn')->defaultNull()->end()
                    ->scalarNode('endpoint')->defaultNull()->end()
                    ->scalarNode('public_endpoint')->defaultNull()->end()
                    ->scalarNode('region')->defaultValue(S3Settings::DEFAULT_REGION)->end()
                    ->scalarNode('bucket')->defaultNull()->end()
                    ->scalarNode('prefix')->defaultValue('')->end()
                    ->scalarNode('access_key')->defaultNull()->end()
                    ->scalarNode('secret')->defaultNull()->end()
                    ->booleanNode('path_style')->defaultFalse()->end()
                    ->enumNode('delivery')
                        ->info('"redirect": downloads are redirected to pre-signed URLs of the bucket (its origin is added to the CSP img-src). "proxy": the application streams them.')
                        ->values([ConfigProfiles::DELIVERY_REDIRECT, ConfigProfiles::DELIVERY_PROXY])
                        ->defaultValue(ConfigProfiles::DELIVERY_REDIRECT)
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $v): bool => (!is_string($v['dsn'] ?? null) || trim($v['dsn']) === '') && !(is_string($v['bucket'] ?? null) && is_string($v['access_key'] ?? null) && is_string($v['secret'] ?? null)))
                    ->thenInvalid('A nowo_storage_kit.profiles entry needs a "dsn", or "bucket", "access_key" and "secret".')
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function (array $v): bool {
                    foreach (array_keys($v) as $name) {
                        if (!is_string($name) || preg_match(self::PROFILE_NAME_PATTERN, $name) !== 1) {
                            return true;
                        }
                    }

                    return false;
                })
                ->thenInvalid('nowo_storage_kit.profiles names must be 1-64 lowercase letters, digits or underscores.')
            ->end();

        return $node;
    }

    private function endpointsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('endpoints'))->getRootNode();
        $node
            ->info('SSRF guard of the buckets typed into a page (every scope bucket; the instance bucket unless guard_instance_profile is false).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('guard_instance_profile')
                    ->info('Also guard the instance bucket edited on the Storage page (recommended).')
                    ->defaultTrue()
                ->end()
                ->arrayNode('allowed_hosts')
                    ->info('Hosts accepted even when private or single-label (an internal MinIO, "rustfs" in a demo). Exact, case-insensitive; cloud metadata stays refused.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('allow_private')
                    ->info('DEV only (refused without kernel.debug): accept private / loopback endpoints. Cloud metadata stays refused.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('resolve_dns')
                    ->info('Resolve endpoint names and refuse those pointing at private addresses (every A / AAAA answer).')
                    ->defaultTrue()
                ->end()
                ->floatNode('dns_timeout')
                    ->info('Deadline of one DNS lookup, in seconds (REQ-RUNTIME-001).')
                    ->defaultValue(2.0)
                    ->min(0.1)
                    ->max(10.0)
                ->end()
                ->floatNode('request_timeout')
                    ->info('Idle timeout of one storage request, in seconds (REQ-RUNTIME-001; the total is bounded at four times this).')
                    ->defaultValue(30.0)
                    ->min(1.0)
                    ->max(300.0)
                ->end()
            ->end();

        return $node;
    }

    private function uploadNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('upload'))->getRootNode();
        $node
            ->info('Image upload and download endpoints (POST <path_prefix>/upload/{type}/{id}, GET <path_prefix>/files/{uuid}/{variant}).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('path_prefix')
                    ->info('URL prefix of the endpoints.')
                    ->defaultValue(self::DEFAULT_FILES_PREFIX)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !self::isPath($v))
                        ->thenInvalid('nowo_storage_kit.upload.path_prefix must be an absolute path such as "/storage", got %s.')
                    ->end()
                ->end()
                ->arrayNode('access_roles')
                    ->info('Roles allowed to upload at all (any of them, never empty); the file context then decides where.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ROLE_USER'])
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => self::roles($v) === [])
                        ->thenInvalid('nowo_storage_kit.upload.access_roles must contain at least one non-empty role (an empty list would deny everybody).')
                    ->end()
                ->end()
                ->integerNode('max_bytes')
                    ->info('Largest accepted upload, in bytes (also check upload_max_filesize / post_max_size).')
                    ->defaultValue(ImageProcessor::MAX_BYTES)
                    ->min(1024)
                    ->max(50 * 1024 * 1024)
                ->end()
                ->integerNode('max_pixels')
                    ->info('Largest accepted image, in pixels (width × height), checked before decoding.')
                    ->defaultValue(ImageProcessor::MAX_PIXELS)
                    ->min(1)
                    ->max(100_000_000)
                ->end()
                ->arrayNode('variants')
                    ->info('Generated variants (name => longest side in pixels) for larger images. Names: preview, thumb.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->integerPrototype()->min(16)->max(8192)->end()
                    ->defaultValue(ImageProcessor::SIZES)
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), ['preview', 'thumb']) !== [])
                        ->thenInvalid('nowo_storage_kit.upload.variants accepts the names "preview" and "thumb" only.')
                    ->end()
                ->end()
                ->integerNode('rate_limit')
                    ->info('Uploads one user may make per rate_interval (sliding window over cache.app).')
                    ->defaultValue(30)
                    ->min(1)
                    ->max(1000)
                ->end()
                ->scalarNode('rate_interval')
                    ->info('Window of the rate limit, a relative time such as "1 minute".')
                    ->defaultValue('1 minute')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v) === '' || strtotime('+' . $v, 0) === false || strtotime('+' . $v, 0) <= 0)
                        ->thenInvalid('nowo_storage_kit.upload.rate_interval must be a relative time such as "1 minute", got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * The variants sorted largest first (each is made from the previous one).
     *
     * @param array<string, int> $variants
     *
     * @return array<string, int>
     */
    public static function variants(array $variants): array
    {
        arsort($variants);

        return $variants;
    }

    private static function isPath(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '/') && trim($value, '/ ') !== '' && preg_match('#^/[A-Za-z0-9_\-/.]*$#', $value) === 1;
    }

    private static function nested(mixed $inner, mixed $outer): bool
    {
        $inner = rtrim(is_string($inner) ? $inner : '', '/');
        $outer = rtrim(is_string($outer) ? $outer : '', '/');

        return $inner === $outer || str_starts_with($inner, $outer . '/');
    }
}
