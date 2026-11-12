<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support\Legacy;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

use function strtolower;

/**
 * LITERAL COPY of the v1.4.0 class (only the namespace and the class names changed: FileDelivery is
 * FileDeliveryV140), kept as the reference of the 1.5 equivalence tests. Do not edit.
 *
 * Original description:
 *
 * `GET <upload.path_prefix>/files/{uuid}/{variant}` — 404 unless the viewer may read the context the file belongs to
 * (no difference between missing and forbidden; anonymous viewers are asked to the context too), then
 * {@see FileDeliveryV140}: a pre-signed redirect for redirect-delivered config profiles, the bytes otherwise (sandboxed,
 * `nosniff`, configured disposition).
 */
final class FileDownloadControllerV140
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly StoredFileRepository $files,
        private readonly FileContexts $contexts,
        private readonly FileDeliveryV140 $delivery,
    ) {
    }

    /**
     * Downloads a variant of a file.
     */
    #[Route(path: '/files/{uuid}/{variant}', name: FileUrls::ROUTE_DOWNLOAD, requirements: ['uuid' => Requirement::UUID, 'variant' => 'original|preview|thumb'], methods: ['GET'])]
    public function download(string $uuid, string $variant): Response
    {
        $uuid = strtolower($uuid);
        $file = $this->files->findOneByUuid($uuid);
        if (!$file instanceof StoredFile || ($this->contexts->readable($this->guard->user(), [$file])[$uuid] ?? false) !== true) {
            throw new NotFoundHttpException();
        }

        return $this->delivery->respond($file, $variant);
    }
}
