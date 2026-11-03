<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * What an editor of a context needs to upload into it (`nowo_storage_upload(type, id)` in Twig, or
 * {@see self::milkdownOptions()} for MilkdownEditorBundle's upload hook):
 *
 * - `on`: the endpoint URL (`POST <upload.path_prefix>/upload/{type}/{id}`, multipart field `file`), the CSRF token
 *   to send in the `X-CSRF-Token` header (token id `nowo_storage_kit/upload/<type>/<id>`: bound to the action and
 *   the target), the size cap and the accepted types;
 * - `off`: the user may write there but no bucket is set up (`setup_url` for whoever may set one up);
 * - `none`: no upload feature (the user may not upload, or not in that context).
 */
final readonly class UploadEndpoint
{
    /** Request header of the CSRF token. */
    public const CSRF_HEADER = 'X-CSRF-Token';

    /** Multipart field of the file. */
    public const FILE_FIELD = 'file';

    /**
     * @param bool $adminUi `web_ui.enabled` (setup links)
     */
    public function __construct(
        private AccessGuard $guard,
        private FileContexts $contexts,
        private StorageProfiles $profiles,
        private FileUrls $urls,
        private UrlGeneratorInterface $router,
        private ?CsrfTokenManagerInterface $csrf,
        private ImageProcessor $processor,
        private bool $adminUi = true,
    ) {
    }

    /**
     * CSRF token id of an upload into a context.
     */
    public static function tokenId(string $contextType, string $contextId): string
    {
        return 'nowo_storage_kit/upload/' . $contextType . '/' . $contextId;
    }

    /**
     * The upload configuration of a context for the current user.
     *
     * Depends on the signed-in user and the stored profiles.
     *
     * @phpstan-impure
     *
     * @return array{state: 'none'|'off'|'on', url?: string, token?: string, header?: string, field?: string, max_bytes?: int, accept?: list<string>, setup_url?: string|null}
     */
    public function config(string $contextType, string $contextId): array
    {
        $user = $this->guard->user();
        if ($user === null || !$this->csrf instanceof CsrfTokenManagerInterface || !$this->guard->canUpload() || !FileContexts::isType($contextType) || !FileContexts::isId($contextId)) {
            return ['state' => 'none'];
        }
        $target = $this->contexts->get($contextType)?->writableTarget($user, $contextId);
        if (!$target instanceof FileContextTarget) {
            return ['state' => 'none'];
        }
        if (!$this->profiles->forTarget($target) instanceof StorageProfile) {
            return ['state' => 'off', 'setup_url' => $this->setupUrl($target)];
        }

        return [
            'state'     => 'on',
            'url'       => $this->urls->upload($contextType, $contextId),
            'token'     => $this->csrf->getToken(self::tokenId($contextType, $contextId))->getValue(),
            'header'    => self::CSRF_HEADER,
            'field'     => self::FILE_FIELD,
            'max_bytes' => $this->processor->maxBytes(),
            'accept'    => ImageProcessor::MIME_TYPES,
        ];
    }

    /**
     * MilkdownEditorBundle 1.1 upload options (`upload_url`, `upload_csrf_token`, `upload_max_size`,
     * `upload_accept`) for a context, or [] when uploads are not on there.
     *
     * @phpstan-impure
     *
     * @return array{upload_url?: string, upload_csrf_token?: string, upload_max_size?: int, upload_accept?: list<string>}
     */
    public function milkdownOptions(string $contextType, string $contextId): array
    {
        $config = $this->config($contextType, $contextId);
        if ($config['state'] !== 'on') {
            return [];
        }

        return [
            'upload_url'        => $config['url'] ?? '',
            'upload_csrf_token' => $config['token'] ?? '',
            'upload_max_size'   => $config['max_bytes'] ?? 0,
            'upload_accept'     => $config['accept'] ?? [],
        ];
    }

    private function setupUrl(FileContextTarget $target): ?string
    {
        if (!$this->adminUi) {
            return null;
        }
        $scope = $target->scope;
        if ($scope !== null && $this->profiles->scopesEnabled() && $this->guard->isScopeGranted($scope)) {
            return $this->router->generate(StorageSettingsController::ROUTE_SCOPE, ['scope' => $scope]);
        }

        return $this->guard->isGranted() ? $this->router->generate(StorageSettingsController::ROUTE) : null;
    }
}
