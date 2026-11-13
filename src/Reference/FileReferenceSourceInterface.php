<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

use Nowo\StorageKitBundle\Context\FileContextInterface;

/**
 * A kind of text that can point at stored files (comments, descriptions, wiki pages…), implemented by the host and
 * tagged `nowo_storage_kit.reference_source` (autoconfigured). {@see FileReferenceListener} asks it for the text of
 * the entities a flush writes; the purge asks which source ids still exist (texts deleted by SQL cascades leave no
 * event). Together they give the reference-aware purge (REQ-MEDIA-005).
 */
interface FileReferenceSourceInterface
{
    /** Service tag. */
    public const TAG = 'nowo_storage_kit.reference_source';

    /**
     * The `source_type` stored on the reference: 1–16 characters `[a-z0-9_]`.
     */
    public function type(): string;

    /**
     * The entity class whose rows are the texts.
     *
     * @return class-string
     */
    public function entityClass(): string;

    /**
     * The field holding the text (a flush that does not change it changes no reference).
     */
    public function field(): string;

    /**
     * The source id of an entity (1–64 characters, known before the insert: a UUID, not an auto-increment id). Matched
     * byte for byte (REQ-SEC-006, 1.5.3): an id with edge white space, a control, zero-width or bidi character, invalid
     * UTF-8 or more than 64 characters references nothing (logged).
     */
    public function id(object $entity): string;

    /**
     * The text as saved ('' for a deleted or emptied one: it points at nothing any more).
     */
    public function text(object $entity): string;

    /**
     * The file context the text belongs to ([type, id] as {@see FileContextInterface}
     * stores it on uploads). Only files of that context are referenced: a UUID learned elsewhere cannot keep someone
     * else's orphan from the purge. Null: none (nothing referenced).
     *
     * @return array{0: string, 1: string}|null
     */
    public function context(object $entity): ?array;

    /**
     * Which source ids still exist (one query), exactly as asked: the bundle compares the answer with `===`.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function existing(array $ids): array;
}
