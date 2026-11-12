<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Download;

use Error;
use LogicException;
use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function strlen;
use function unserialize;
use function var_export;

/**
 * 1.5: the download VO keeps the pre-signed URL (a bearer credential until it expires) and the body source (a closure
 * over the bucket connection and its credentials) out of every dump, cast, encoding and serialization; a copy carries
 * nothing; the stream is cut at the stored size; the HttpFoundation adapter builds the 1.4 response shape.
 */
final class DeliverableFileTest extends TestCase
{
    private const SECRET_URL = 'https://files.storage.test/2026/11/x?X-Amz-Signature=REDACTED-TEST-SIGNATURE';

    private const SECRET_IN_CLOSURE = 'bucket-credential-REDACTED-TEST';

    /**
     * @return iterable<string, array{DeliverableFile}>
     */
    public static function files(): iterable
    {
        yield 'redirect' => [DeliverableFile::redirect(self::SECRET_URL, 240)];
        $credential = self::SECRET_IN_CLOSURE;
        yield 'stream' => [DeliverableFile::stream(static fn (): iterable => [$credential], 5, 240, ['Content-Length' => '5'])];
    }

    #[DataProvider('files')]
    public function testNoDumpCastOrEncodingShowsTheSecretPart(DeliverableFile $file): void
    {
        $cloner = new VarCloner();
        $dumper = new CliDumper();
        $dumps  = [
            'VarDumper'   => (string) $dumper->dump($cloner->cloneVar($file), true),
            'var_export'  => var_export($file, true),
            'print_r'     => print_r($file, true),
            'json_encode' => (string) json_encode($file),
            '(array)'     => print_r((array) $file, true),
        ];
        ob_start();
        var_dump($file);
        $dumps['var_dump'] = (string) ob_get_clean();

        foreach ($dumps as $how => $dump) {
            self::assertStringNotContainsString('REDACTED-TEST', $dump, $how);
            self::assertStringNotContainsString('storage.test', $dump, $how);
        }
        self::assertStringContainsString($file->mode, $dumps['print_r'], 'the public shape stays visible');
    }

    #[DataProvider('files')]
    public function testItCannotBeSerializedNorCloned(DeliverableFile $file): void
    {
        try {
            serialize($file);
            self::fail('serialized');
        } catch (LogicException $e) {
            self::assertStringContainsString('cannot be serialized', $e->getMessage());
        }
        try {
            unserialize('O:' . strlen(DeliverableFile::class) . ':"' . DeliverableFile::class . '":0:{}');
            self::fail('unserialized');
        } catch (LogicException $e) {
            self::assertStringContainsString('cannot be unserialized', $e->getMessage());
        }
        $this->expectException(Error::class);
        self::assertNotSame($file, $this->copyOf($file));
    }

    /** `clone` of a VO whose __clone() is private: an Error. */
    private function copyOf(DeliverableFile $file): DeliverableFile
    {
        return clone $file; // @phpstan-ignore clone.privateMethod
    }

    public function testARedirectHasNoBodyAndAStreamNoLocation(): void
    {
        $redirect = DeliverableFile::redirect(self::SECRET_URL, 240);
        self::assertTrue($redirect->isRedirect());
        self::assertSame(302, $redirect->statusCode);
        self::assertSame(self::SECRET_URL, $redirect->location());
        try {
            $redirect->writeTo(static function (): void {
            });
            self::fail('a redirect has no body');
        } catch (LogicException $e) {
            self::assertSame('This deliverable file is not a stream.', $e->getMessage());
        }

        $stream = DeliverableFile::stream(static fn (): iterable => ['abc', 'defgh'], 8, 0);
        self::assertFalse($stream->isRedirect());
        self::assertSame([200, 8, DeliverableFile::POLICY_HEADERS], [$stream->statusCode, $stream->size, $stream->policyHeaders]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This deliverable file is not a redirect.');
        $stream->location();
    }

    public function testTheStreamIsCutAtTheStoredSize(): void
    {
        $sent   = '';
        $stream = DeliverableFile::stream(static fn (): iterable => ['abc', 'defgh'], 5, 0);
        try {
            $stream->writeTo(static function (string $chunk) use (&$sent): void {
                $sent .= $chunk;
            });
            self::fail('cut');
        } catch (RuntimeException) {
            self::assertSame('abcde', $sent);
        }
        $sent = '';
        self::assertSame(5, DeliverableFile::stream(static fn (): iterable => ['ab', 'cde'], 5, 0)->writeTo(static function (string $chunk) use (&$sent): void {
            $sent .= $chunk;
        }));
        self::assertSame('abcde', $sent);
    }

    public function testTheAdapterBuildsThe140ResponseShape(): void
    {
        $responder = new FileDeliveryResponder();
        $redirect  = $responder->toResponse(DeliverableFile::redirect(self::SECRET_URL, 240));
        self::assertInstanceOf(RedirectResponse::class, $redirect);
        self::assertSame([302, self::SECRET_URL, 'max-age=240, private', 'noindex', 'no-referrer'], [$redirect->getStatusCode(), $redirect->getTargetUrl(), $redirect->headers->get('Cache-Control'), $redirect->headers->get('X-Robots-Tag'), $redirect->headers->get('Referrer-Policy')]);

        $stream = $responder->toResponse(DeliverableFile::stream(static fn (): iterable => ['ab', 'cd'], 4, 0, ['Content-Length' => '4', 'Content-Type' => 'image/png']));
        self::assertInstanceOf(StreamedResponse::class, $stream);
        self::assertSame([200, '4', 'image/png', 'max-age=0, private'], [$stream->getStatusCode(), $stream->headers->get('Content-Length'), $stream->headers->get('Content-Type'), $stream->headers->get('Cache-Control')]);
        ob_start();
        $stream->sendContent();
        self::assertSame('abcd', ob_get_clean());
    }
}
