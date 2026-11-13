<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Context;

/**
 * A kind of text files are uploaded into (comments of an issue, a board, a wiki page…), implemented by the host and
 * tagged `nowo_storage_kit.file_context` (autoconfigured). Who may **write** decides uploads; who may **read** the text
 * decides downloads — batched: one query per call, whatever the number of files of a text.
 *
 * Context ids are matched byte for byte (REQ-SEC-006): {@see self::readable()} and {@see self::existing()} return ids
 * exactly as they were asked (`abc` is not `ABC`); the bundle compares the answer with `===`, so an id the host returns
 * in another spelling counts as not readable / not existing.
 */
interface FileContextInterface
{
    /** Service tag. */
    public const TAG = 'nowo_storage_kit.file_context';

    /**
     * The `context_type` stored on the file: 1–16 characters `[a-z0-9_]`.
     */
    public function type(): string;

    /**
     * The context ids the viewer may read.
     *
     * @param object|null $viewer security user (null = anonymous)
     * @param list<string> $ids context ids
     *
     * @return list<string>
     */
    public function readable(?object $viewer, array $ids): array;

    /**
     * Which contexts still exist (the files of a deleted one become orphans), one query.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public function existing(array $ids): array;

    /**
     * What the context belongs to when `$user` may write in it; null = unknown or forbidden (answered 404).
     *
     * @param object $user security user
     */
    public function writableTarget(object $user, string $id): ?FileContextTarget;
}
