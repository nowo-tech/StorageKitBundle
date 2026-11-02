<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Context;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_fill_keys;
use function array_flip;
use function array_keys;
use function array_map;
use function preg_match;
use function spl_object_id;
use function sprintf;

/**
 * Registry of the upload contexts and the batched read check: the files of a text are grouped by context and each
 * context type is asked once; decisions are memoised per viewer for the request ({@see self::reset()} between worker
 * requests). An unknown context type reads nothing.
 */
final class FileContexts implements ResetInterface
{
    /** Context type syntax (stored, in URLs and CSRF token ids). */
    public const TYPE_PATTERN = '[a-z0-9_]{1,16}';

    /** Context id syntax (stored, in URLs and CSRF token ids: no `/`). */
    public const ID_PATTERN = '[A-Za-z0-9_.:\-]{1,64}';

    /** @var array<string, FileContextInterface> */
    private array $types = [];

    /** @var array<string, bool> "viewer|type|id" => readable */
    private array $decisions = [];

    /**
     * @param iterable<FileContextInterface> $types tagged contexts
     *
     * @throws InvalidArgumentException on a malformed or duplicate type
     */
    public function __construct(iterable $types = [])
    {
        foreach ($types as $type) {
            $name = $type->type();
            if (!self::isType($name) || isset($this->types[$name])) {
                throw new InvalidArgumentException(sprintf('File context type "%s" is malformed ([a-z0-9_]{1,16}) or registered twice.', $name));
            }
            $this->types[$name] = $type;
        }
    }

    /**
     * Whether `$type` has the context type syntax.
     */
    public static function isType(string $type): bool
    {
        return preg_match('/^' . self::TYPE_PATTERN . '$/D', $type) === 1;
    }

    /**
     * Whether `$id` has the context id syntax.
     */
    public static function isId(string $id): bool
    {
        return preg_match('/^' . self::ID_PATTERN . '$/D', $id) === 1;
    }

    /**
     * The context of a type, or null.
     */
    public function get(string $type): ?FileContextInterface
    {
        return $this->types[$type] ?? null;
    }

    /**
     * The registered context types.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->types);
    }

    /**
     * Which files the viewer may read.
     *
     * @param list<StoredFile> $files
     *
     * @return array<string, bool> file uuid => the viewer may read it
     */
    public function readable(?object $viewer, array $files): array
    {
        $prefix  = $this->viewerKey($viewer) . '|';
        $pending = [];
        foreach ($files as $file) {
            $key = $prefix . $file->getContextType() . '|' . $file->getContextId();
            if (!isset($this->decisions[$key])) {
                $pending[$file->getContextType()][$file->getContextId()] = true;
            }
        }
        foreach ($pending as $type => $ids) {
            $ids     = array_map(strval(...), array_keys($ids));
            $allowed = array_flip($this->get($type)?->readable($viewer, $ids) ?? []);
            foreach ($ids as $id) {
                // @igor-ignore - Request-scoped memo, cleared by reset() (kernel.reset).
                $this->decisions[$prefix . $type . '|' . $id] = isset($allowed[$id]);
            }
        }
        $readable = array_fill_keys(array_map(static fn (StoredFile $f): string => $f->getUuid(), $files), false);
        foreach ($files as $file) {
            $readable[$file->getUuid()] = $this->decisions[$prefix . $file->getContextType() . '|' . $file->getContextId()];
        }

        return $readable;
    }

    /**
     * Drops the memoised decisions.
     */
    public function reset(): void
    {
        $this->decisions = [];
    }

    private function viewerKey(?object $viewer): string
    {
        return match (true) {
            $viewer === null                 => 'anon',
            $viewer instanceof UserInterface => 'u:' . $viewer->getUserIdentifier(),
            default                          => 'o:' . spl_object_id($viewer),
        };
    }
}
