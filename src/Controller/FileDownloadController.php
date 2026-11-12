<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Controller;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
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
 * `GET <upload.path_prefix>/files/{uuid}/{variant}` — 404 unless the viewer may read the context the file belongs to
 * (no difference between missing and forbidden; anonymous viewers are asked to the context too), then
 * the download port ({@see FileDeliveryInterface}, {@see FileDelivery} unless the host aliases its own): a pre-signed
 * redirect for redirect-delivered config profiles, the bytes otherwise (sandboxed, `nosniff`, configured disposition),
 * turned into a response by {@see FileDeliveryResponder} (1.5).
 */
final class FileDownloadController
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly StoredFileRepository $files,
        private readonly FileContexts $contexts,
        private readonly FileDeliveryInterface $delivery,
        private readonly FileDeliveryResponder $responder = new FileDeliveryResponder(),
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

        try {
            return $this->responder->toResponse($this->delivery->deliver($uuid, $variant));
        } catch (FileNotDeliverable $e) {
            throw new NotFoundHttpException('', $e); // no message: the same 404 as 1.4
        }
    }
}
