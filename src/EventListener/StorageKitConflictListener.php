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
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
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
 * handler that already completed, a committed ConcurrencyConflict). Without transaction-doctrine's `commit_detection`
 * the bundle takes its own mark on the main request (1.7.1, audit UH-SK-4, {@see self::onRequest()}), on the ledger its
 * commit-tracking middleware feeds from the bundle's connection (`conflict_response.commit_tracking`). The Storage and
 * Move pages get an HttpException with that status and those headers, rendered by your error page; the upload endpoint
 * keeps its JSON error shape `{error, message}` and adds `code`; the download endpoint gets a `text/plain` answer.
 *
 * Not conflicts, never mapped: unique violations and lost connections (they stay real errors). Host routes are never
 * answered (the `_controller` must be one of the bundle's). Priority -64: after the application's own exception
 * listeners, before the framework's error listener (-128). Stateless: the mark lives on the request and the ledger is
 * a monotonic counter read by difference (FrankenPHP worker mode with kernel reset off).
 */
final readonly class StorageKitConflictListener
{
    /** `error` value of the upload endpoint's JSON answer. */
    public const ERROR = 'conflict';

    /** Generic, client-safe text when nothing was committed (also the translation fallback). */
    public const MESSAGE = 'The same data was being changed at the same time. Try again.';

    /** Generic, client-safe text when part of the request was committed (also the translation fallback). */
    public const COMMITTED_MESSAGE = 'Part of the request was saved before a conflict. Reload before trying again.';

    /** 1.7.1: request attribute of the bundle's own commit mark. */
    public const MARK_ATTRIBUTE = '_nowo_storage_kit_commit_mark';

    /** 1.7.1: kernel.request priority of {@see self::onRequest()} (before routing, the firewall and your listeners). */
    public const REQUEST_PRIORITY = 4096;

    private const CONTROLLER_PREFIX = 'Nowo\\StorageKitBundle\\Controller\\';

    /**
     * @param ConflictResponsePolicy $policy status (409 or 503) and Retry-After (`conflict_response`)
     * @param CommitLedger|null $ledger commits of the bundle's connection (null: transaction-doctrine's mark, or none)
     * @param RequestStack|null $requestStack the main request's mark for a sub-request
     */
    public function __construct(
        private ConflictResponsePolicy $policy = new ConflictResponsePolicy(),
        private ?TranslatorInterface $translator = null,
        private ?CommitLedger $ledger = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Takes the bundle's commit mark on the main request (1.7.1; only registered without transaction-doctrine's
     * `commit_detection`).
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger && $event->isMainRequest()) {
            // @igor-ignore - Per-request Request attribute; not shared worker state.
            $event->getRequest()->attributes->set(self::MARK_ATTRIBUTE, new CommitMark($this->ledger));
        }
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
        $code    = StorageConflicts::code($request, $failure, $this->ownMark($request));
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

    /**
     * The bundle's mark of the request, or of the main request for a sub-request; null when none was taken.
     */
    private function ownMark(Request $request): ?CommitMark
    {
        $mark = $request->attributes->get(self::MARK_ATTRIBUTE) ?? $this->requestStack?->getMainRequest()?->attributes->get(self::MARK_ATTRIBUTE);

        return $mark instanceof CommitMark ? $mark : null;
    }

    private function message(ConflictCode $code): string
    {
        [$key, $fallback] = $code === ConflictCode::Committed ? ['conflict.committed', self::COMMITTED_MESSAGE] : ['conflict.retry', self::MESSAGE];
        $translated       = $this->translator?->trans($key, [], NowoStorageKitBundle::TRANSLATION_DOMAIN);

        return $translated === null || $translated === $key ? $fallback : $translated;
    }
}
