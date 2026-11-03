<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use AsyncAws\Core\Exception\Http\HttpException as AwsHttpException;
use AsyncAws\Core\Exception\Http\NetworkException as AwsNetworkException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function mb_substr;
use function preg_replace;
use function str_replace;

/**
 * Turns storage failures into something safe to keep or show (SEC-004: secrets never in logs, events, exceptions or
 * pages): a generic reason with the HTTP status of the answer, and — for the log only — the message with the access
 * key, the secret and any `X-Amz-Signature` / `X-Amz-Credential` query value masked, cut to 500 characters.
 */
final class ErrorScrubber
{
    /** Longest scrubbed message. */
    public const MAX = 500;

    /**
     * A generic reason ({@see StorageProbeResult}::REASON_*) and the HTTP status of the storage's answer, if any.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function reason(Throwable $e): array
    {
        for ($cause = $e; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof AwsHttpException || $cause instanceof HttpExceptionInterface) {
                try {
                    return [StorageProbeResult::REASON_HTTP, $cause->getResponse()->getStatusCode()];
                } catch (HttpClientException) {
                    return [StorageProbeResult::REASON_UNREACHABLE, null];
                }
            }
            if ($cause instanceof AwsNetworkException || $cause instanceof TransportExceptionInterface) {
                return [StorageProbeResult::REASON_UNREACHABLE, null];
            }
        }

        return [StorageProbeResult::REASON_OTHER, null];
    }

    /**
     * The message for the log, without credentials or signatures.
     */
    public static function message(Throwable $e, S3Settings ...$settings): string
    {
        $message = $e->getMessage();
        foreach ($settings as $one) {
            $message = str_replace([$one->secret(), $one->accessKey], '***', $message);
        }
        $message = (string) preg_replace('/(X-Amz-(?:Signature|Credential|Security-Token)=)[^&\s"]+/i', '$1***', $message);

        return mb_substr($message, 0, self::MAX);
    }
}
