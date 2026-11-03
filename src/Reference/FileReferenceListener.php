<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Contracts\Service\ResetInterface;

use function is_a;
use function is_array;

/**
 * On every flush, the texts written, changed or removed ({@see FileReferenceSourceInterface}) whose old or new value
 * mentions a stored file update their references once the flush is done — a text without files costs no query. A text
 * removed by an SQL cascade is caught by the purge instead.
 */
final class FileReferenceListener implements ResetInterface
{
    /** @var list<FileReferenceSourceInterface> */
    private array $sources = [];

    /** @var array<string, array{0: string, 1: string, 2: string, 3: array{0: string, 1: string}|null}> "type|id" => [type, id, text, file context] */
    private array $pending = [];

    /**
     * @param iterable<FileReferenceSourceInterface> $sources
     */
    public function __construct(
        private readonly FileReferences $references,
        private readonly FileUris $uris,
        iterable $sources = [],
    ) {
        foreach ($sources as $source) {
            $this->sources[] = $source;
        }
    }

    /**
     * Collects the texts of the flush.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->sources === []) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $source = $this->source($entity);
            if ($source instanceof FileReferenceSourceInterface && $this->uris->mentions($source->text($entity))) {
                $this->queue($source, $entity, $source->text($entity));
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $source = $this->source($entity);
            $change = $source instanceof FileReferenceSourceInterface ? ($uow->getEntityChangeSet($entity)[$source->field()] ?? null) : null;
            if ($source instanceof FileReferenceSourceInterface && is_array($change) && ($this->uris->mentions($change[0]) || $this->uris->mentions($change[1]))) {
                $this->queue($source, $entity, $source->text($entity));
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $source = $this->source($entity);
            if ($source instanceof FileReferenceSourceInterface && $this->uris->mentions($source->text($entity))) {
                $this->queue($source, $entity, '');
            }
        }
    }

    /**
     * Writes the collected references.
     */
    public function postFlush(): void
    {
        $pending       = $this->pending;
        $this->pending = [];
        foreach ($pending as [$type, $id, $text, $context]) {
            $this->references->sync($type, $id, $text, $context);
        }
    }

    /**
     * Drops what a failed flush left behind.
     */
    public function reset(): void
    {
        $this->pending = [];
    }

    private function source(object $entity): ?FileReferenceSourceInterface
    {
        foreach ($this->sources as $source) {
            if (is_a($entity, $source->entityClass())) {
                return $source;
            }
        }

        return null;
    }

    private function queue(FileReferenceSourceInterface $source, object $entity, string $text): void
    {
        $id = $source->id($entity);
        // @igor-ignore - Flush-scoped buffer, emptied by postFlush() and reset() (kernel.reset).
        $this->pending[$source->type() . '|' . $id] = [$source->type(), $id, $text, $text === '' ? null : $source->context($entity)];
    }
}
