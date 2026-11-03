<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Controller;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function file_get_contents;
use function hash;
use function in_array;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function trim;

use const PATHINFO_FILENAME;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;

/**
 * `POST <upload.path_prefix>/upload/{type}/{id}` (multipart field `file`, CSRF token in the `X-CSRF-Token` header,
 * token id bound to the context: `nowo_storage_kit/upload/<type>/<id>`) — an image pasted, dropped or picked in an
 * editor. Checks, in order: CSRF, signed-in user with `upload.access_roles`, per-user rate limit, the PHP upload, the
 * size cap; then {@see ImageUploader} (context write access, bucket, content sniffing, pixel cap, limit).
 *
 * Answer 201 `{uuid, uri, url, original_url, name, alt, image: true, markdown}` — `url` is the relative preview
 * download path MilkdownEditorBundle inserts as `![alt](url)`, `markdown` uses the `<scheme>:<uuid>` URI; both forms
 * are tracked as references. Errors `{error, message}`: 403 CSRF / access / limit, 404 unknown or forbidden context,
 * 409 storage not set up, 413 too large, 422 not an accepted image, 429 rate limited, 503 bucket failure. A refusal
 * stores nothing; the PHP upload buffer is the only file on disk and goes with the request.
 */
final class FileUploadController
{
    /** Status per refusal reason (default 422). */
    public const STATUS = [
        UploadRefused::CSRF    => Response::HTTP_FORBIDDEN,
        UploadRefused::ACCESS  => Response::HTTP_FORBIDDEN,
        UploadRefused::RATE    => Response::HTTP_TOO_MANY_REQUESTS,
        UploadRefused::CONTEXT => Response::HTTP_NOT_FOUND,
        UploadRefused::OFF     => Response::HTTP_CONFLICT,
        UploadRefused::SIZE    => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        UploadRefused::LIMIT   => Response::HTTP_FORBIDDEN,
        UploadRefused::STORAGE => Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly RateLimiterFactory $limiter,
        private readonly ImageUploader $uploader,
        private readonly FileUrls $urls,
        private readonly FileUris $uris,
        private readonly int $maxBytes,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Uploads one image into a context.
     */
    #[Route(path: '/upload/{type}/{id}', name: FileUrls::ROUTE_UPLOAD, requirements: ['type' => FileContexts::TYPE_PATTERN, 'id' => FileContexts::ID_PATTERN], methods: ['POST'])]
    public function upload(Request $request, string $type, string $id): JsonResponse
    {
        try {
            $stored = $this->store($request, $type, $id);
        } catch (UploadRefused $e) {
            return $this->refuse($e->reason);
        }
        $alt = trim((string) preg_replace('/[\[\]()\\\\<>]+/', ' ', pathinfo($stored->getOriginalName(), PATHINFO_FILENAME)));
        $alt = $alt === '' ? StoredFile::ANONYMOUS_NAME : $alt;
        $uri = $this->uris->uri($stored->getUuid());

        $response = new JsonResponse([
            'uuid'         => $stored->getUuid(),
            'uri'          => $uri,
            'url'          => $this->urls->download($stored->getUuid(), StoredFile::VARIANT_PREVIEW),
            'original_url' => $this->urls->download($stored->getUuid(), StoredFile::VARIANT_ORIGINAL),
            'name'         => $stored->getOriginalName(),
            'alt'          => $alt,
            'image'        => true,
            'markdown'     => sprintf('![%s](%s)', $alt, $uri),
        ], Response::HTTP_CREATED);

        return $this->noStore($response);
    }

    /**
     * Rate-limit key of an uploader: the user identifier, or — for a non-Symfony user object, which has no stable
     * identity across requests — the session id, else the client IP (audit CJ I6). Hashed: no identifier in the
     * limiter storage.
     */
    public static function rateKey(object $user, Request $request): string
    {
        if ($user instanceof UserInterface) {
            return 'u:' . hash('sha256', $user->getUserIdentifier());
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof SessionInterface && $session->isStarted() && $session->getId() !== '') {
            return 's:' . hash('sha256', $session->getId());
        }

        return 'ip:' . hash('sha256', (string) $request->getClientIp());
    }

    /**
     * @throws UploadRefused
     */
    private function store(Request $request, string $type, string $id): StoredFile
    {
        $token = (string) $request->headers->get(UploadEndpoint::CSRF_HEADER, '');
        if ($token === '' || !$this->csrf->isTokenValid(new CsrfToken(UploadEndpoint::tokenId($type, $id), $token))) {
            throw new UploadRefused(UploadRefused::CSRF);
        }
        $user = $this->guard->user();
        if ($user === null || !$this->guard->canUpload()) {
            throw new UploadRefused(UploadRefused::ACCESS);
        }
        if (!$this->limiter->create(self::rateKey($user, $request))->consume()->isAccepted()) {
            throw new UploadRefused(UploadRefused::RATE);
        }
        $file = $request->files->get(UploadEndpoint::FILE_FIELD);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new UploadRefused($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? UploadRefused::SIZE : UploadRefused::EMPTY);
        }
        if ($file->getSize() > $this->maxBytes) {
            throw new UploadRefused(UploadRefused::SIZE);
        }

        return $this->uploader->upload($user, $type, $id, (string) file_get_contents($file->getPathname()), $file->getClientOriginalName());
    }

    private function refuse(string $reason): JsonResponse
    {
        return $this->noStore(new JsonResponse([
            'error'   => $reason,
            'message' => $this->translator?->trans('upload.error.' . $reason, [], NowoStorageKitBundle::TRANSLATION_DOMAIN) ?? $reason,
        ], self::STATUS[$reason] ?? Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
