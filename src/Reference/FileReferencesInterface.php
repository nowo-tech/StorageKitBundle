<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

/**
 * Reference port (1.5, architecture bar rule 1): which stored files a host text points at, for hosts that track their
 * texts themselves instead of (or besides) a {@see FileReferenceSourceInterface} on a Doctrine entity — for example a
 * DDD host that saves its aggregates through its own repositories. Implemented by {@see FileReferences} (service alias
 * of this interface). Both methods write with DBAL statements and never flush an entity manager.
 */
interface FileReferencesInterface
{
    /**
     * Stores the files the text `$type` / `$id` points at now (only files of the text's own context; a file no other
     * text points at any more becomes an orphan for the purge).
     *
     * @param array{0: string, 1: string}|null $context the text's file context [type, id] (null: references nothing new)
     */
    public function sync(string $type, string $id, string $text, ?array $context): void;

    /**
     * Drops every reference of the given texts (they no longer exist).
     *
     * @param list<string> $ids
     */
    public function forget(string $type, array $ids): void;
}
