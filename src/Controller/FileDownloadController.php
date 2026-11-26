<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Controller;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Download\FileRangeDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileUrls;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

use function strtolower;

/**
 * `GET <upload.path_prefix>/files/{uuid}/{variant}` — 404 unless the viewer may read the context the file belongs to
 * (no difference between missing and forbidden; anonymous viewers are asked to the context too) and the file is not
 * expired (1.6: erased or removed, waiting for the purge), then
 * the download port ({@see FileDeliveryInterface}, {@see FileDelivery} unless the host aliases its own): a pre-signed
 * redirect for redirect-delivered config profiles, the bytes otherwise (sandboxed, `nosniff`, configured disposition),
 * turned into a response by {@see FileDeliveryResponder} (1.5). 2.2: when the port also serves ranges
 * ({@see FileRangeDeliveryInterface}, the bundle's own does), a single `Range` is answered `206` (`416` past the end),
 * and a generic file is always an `attachment` unless it is audio / video and `download.disposition` is `inline`.
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
    public function download(string $uuid, string $variant, ?Request $request = null): Response
    {
        $uuid = strtolower($uuid);
        $file = $this->files->findOneByUuid($uuid);
        if (!$file instanceof StoredFile || $file->isExpired() || ($this->contexts->readable($this->guard->user(), [$file])[$uuid] ?? false) !== true) {
            throw new NotFoundHttpException();
        }

        try {
            // 2.2: a single `Range` gets a 206 when the port can serve ranges (an `If-Range` without a validator: the whole file).
            $range = $request instanceof Request && !$request->headers->has('If-Range') ? $request->headers->get('Range') : null;

            return $this->responder->toResponse($this->delivery instanceof FileRangeDeliveryInterface
                ? $this->delivery->deliverInContext($uuid, $file->getContextType(), $file->getContextId(), $variant, $range)
                : $this->delivery->deliver($uuid, $variant));
        } catch (FileNotDeliverable $e) {
            throw new NotFoundHttpException('', $e); // no message: the same 404 as 1.4
        }
    }
}
