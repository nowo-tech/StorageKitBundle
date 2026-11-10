<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\PersonalData;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Stringable;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function hash;
use function is_callable;
use function is_int;
use function is_string;
use function str_contains;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Default {@see UploaderKeyResolverInterface}: `<user class>:<id>`, for example `App\Entity\Customer:5`.
 *
 * - The class is the real class of the user (a Doctrine proxy `…\__CG__\App\Entity\Customer` gives
 *   `App\Entity\Customer`), so two user classes (admins and customers, two firewalls) with the same id never share a
 *   key.
 * - The id is the user's public `getId()` without arguments, when it returns an integer, a non-empty string or a
 *   stringable value (a `Uuid`, `Ulid`).
 * - A key longer than {@see UploaderKeyResolverInterface::MAX_LENGTH}, or one with a NUL byte (the class name of an
 *   anonymous class), becomes `sha256:<hex>` of it (never cut).
 * - No key (null) when there is no usable `getId()` (missing, private / protected, with required arguments, an
 *   uninitialised id, a getter that throws, an empty value): the file is then matched by the user identifier only.
 *   This is logged once per user class at `warning`.
 *
 * Users of several tenants in separate tables with overlapping ids (and the same class) need their own resolver that
 * adds the tenant, for example `sprintf('%s:%s:%s', $user::class, $user->getTenantId(), $user->getId())`.
 */
final class UserIdUploaderKeyResolver implements UploaderKeyResolverInterface, ResetInterface
{
    /** Marker of Doctrine proxy class names. */
    private const PROXY_MARKER = '\\__CG__\\';

    /** @var array<class-string, true> user classes already reported as key-less */
    private array $reported = [];

    /**
     * Logger for user classes without a usable id.
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * `<user class>:<id>`, or null.
     */
    public function keyOf(object $user): ?string
    {
        $id = $this->idOf($user);
        if ($id === null) {
            $this->report($user);

            return null;
        }
        $key = self::realClass($user) . ':' . $id;

        return strlen($key) > self::MAX_LENGTH || str_contains($key, "\0") ? 'sha256:' . hash('sha256', $key) : $key;
    }

    /**
     * Forgets which user classes were reported (worker mode).
     */
    public function reset(): void
    {
        $this->reported = [];
    }

    /**
     * The class of `$user`, without a Doctrine proxy prefix.
     */
    public static function realClass(object $user): string
    {
        $class  = $user::class;
        $marker = strrpos($class, self::PROXY_MARKER);

        return $marker === false ? $class : substr($class, $marker + strlen(self::PROXY_MARKER));
    }

    private function idOf(object $user): ?string
    {
        $getter = [$user, 'getId'];
        if (!is_callable($getter)) {
            return null;
        }
        try {
            if ((new ReflectionMethod($user, 'getId'))->getNumberOfRequiredParameters() > 0) {
                return null;
            }
            $id = $getter();
            if (is_int($id)) {
                return (string) $id;
            }
            if (!is_string($id) && !$id instanceof Stringable) {
                return null;
            }
            $id = trim((string) $id);
        } catch (Throwable) {
            return null;
        }

        return $id === '' ? null : $id;
    }

    private function report(object $user): void
    {
        if (isset($this->reported[$user::class])) {
            return;
        }
        $this->reported[$user::class] = true;
        $this->logger->warning('Storage Kit: user class {class} has no usable getId(); its uploads are linked by user identifier only (GDPR export / erasure miss them after an identifier change). Configure personal_data.uploader_key_resolver.', ['class' => $user::class]);
    }
}
