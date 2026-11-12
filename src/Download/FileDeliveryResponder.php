<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function flush;

/**
 * HttpFoundation adapter of the download port (1.5): turns a {@see DeliverableFile} into the same `Response` that
 * `FileDelivery::respond()` returned up to 1.4 — a `RedirectResponse` (`302`) or a `StreamedResponse` (`200`, the body
 * written chunk by chunk and flushed), the VO's headers, a private cache of its max age, then its policy headers.
 */
final readonly class FileDeliveryResponder
{
    /**
     * The response of `$file`.
     */
    public function toResponse(DeliverableFile $file): Response
    {
        if ($file->isRedirect()) {
            $response = new RedirectResponse($file->location(), Response::HTTP_FOUND, $file->headers);
        } else {
            // @igor-ignore - The closure is the response body callback of this request only.
            $response = new StreamedResponse(static function () use ($file): void {
                $file->writeTo(static function (string $chunk): void {
                    echo $chunk;
                    flush();
                });
            }, $file->statusCode, $file->headers);
        }
        $response->setPrivate();
        $response->setMaxAge($file->maxAge);
        foreach ($file->policyHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
