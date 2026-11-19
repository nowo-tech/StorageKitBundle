<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;
use function str_starts_with;

/**
 * Since 1.7 (REQ-RUNTIME-003 rule 6): a storage conflict on one of the bundle's own routes answers the platform
 * conflict answer instead of a 500.
 *
 * The answer is **409** (`conflict_response.status: 503` for hosts that want it) with `X-Conflict-Code`:
 * `CONFLICT_001` and `Retry-After` when nothing was committed, `CONFLICT_002` and no `Retry-After` when part of the
 * request was ({@see StorageConflicts::code()}: the request commit mark of transaction-doctrine >= 1.6, a Messenger
 * handler that already completed, a committed ConcurrencyConflict). The Storage and Move pages get an HttpException
 * with that status and those headers, rendered by your error page; the upload endpoint keeps its JSON error shape
 * `{error, message}` and adds `code`; the download endpoint gets a `text/plain` answer.
 *
 * Not conflicts, never mapped: unique violations and lost connections (they stay real errors). Host routes are never
 * answered (the `_controller` must be one of the bundle's). Priority -64: after the application's own exception
 * listeners, before the framework's error listener (-128). Stateless.
 */
final readonly class StorageKitConflictListener
{
    /** `error` value of the upload endpoint's JSON answer. */
    public const ERROR = 'conflict';

    /** Generic, client-safe text when nothing was committed (also the translation fallback). */
    public const MESSAGE = 'The same data was being changed at the same time. Try again.';

    /** Generic, client-safe text when part of the request was committed (also the translation fallback). */
    public const COMMITTED_MESSAGE = 'Part of the request was saved before a conflict. Reload before trying again.';

    private const CONTROLLER_PREFIX = 'Nowo\\StorageKitBundle\\Controller\\';

    /**
     * @param ConflictResponsePolicy $policy status (409 or 503) and Retry-After (`conflict_response`)
     */
    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Answers a storage conflict of a bundle route.
     */
    public function __invoke(ExceptionEvent $event): void
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_PREFIX)) {
            return;
        }
        $failure = $event->getThrowable();
        if (!StorageConflicts::isConflict($failure)) {
            return;
        }
        $code    = StorageConflicts::code($request, $failure);
        $hint    = StorageConflicts::retryAfterHint($failure);
        $message = $this->message($code);
        if (str_starts_with($controller, StorageSettingsController::class) || str_starts_with($controller, StorageMoveController::class)) {
            $event->setThrowable($this->policy->httpExceptionFor($code, $message, $failure, $hint));

            return;
        }
        $headers = $this->policy->headersFor($code, $hint) + [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, private',
        ];
        if (str_starts_with($controller, FileUploadController::class)) {
            $event->setResponse(new JsonResponse(['error' => self::ERROR, 'message' => $message, 'code' => $code->value], $this->policy->status, $headers));

            return;
        }
        $event->setResponse(new Response($message, $this->policy->status, $headers + ['Content-Type' => 'text/plain; charset=utf-8']));
    }

    private function message(ConflictCode $code): string
    {
        [$key, $fallback] = $code === ConflictCode::Committed ? ['conflict.committed', self::COMMITTED_MESSAGE] : ['conflict.retry', self::MESSAGE];
        $translated       = $this->translator?->trans($key, [], NowoStorageKitBundle::TRANSLATION_DOMAIN);

        return $translated === null || $translated === $key ? $fallback : $translated;
    }
}
