<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Profile;

use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class ConfigProfilesTest extends TestCase
{
    /**
     * @param array<string, bool|string|null> $values
     *
     * @return array{dsn: string|null, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string|null, prefix: string, access_key: string|null, secret: string|null, path_style: bool, delivery: string}
     */
    private function entry(array $values): array
    {
        /** @var array{dsn: string|null, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string|null, prefix: string, access_key: string|null, secret: string|null, path_style: bool, delivery: string} $entry */
        $entry = $values + ['dsn' => null, 'endpoint' => null, 'public_endpoint' => null, 'region' => 'us-east-1', 'bucket' => null, 'prefix' => '', 'access_key' => null, 'secret' => null, 'path_style' => false, 'delivery' => 'redirect'];

        return $entry;
    }

    public function testProfilesFromADsnOrFields(): void
    {
        /** @var list<string> $logged */
        $logged = [];
        $logger = new class($logged) extends AbstractLogger {
            /**
             * @param list<string> $logged
             *
             * @phpstan-ignore property.onlyWritten (written through the reference)
             */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) json_encode($context);
            }
        };
        $profiles = new ConfigProfiles([
            'main'   => $this->entry(['dsn' => 's3://key:test-secret-not-real@aws/files']),
            'fields' => $this->entry(['bucket' => 'other', 'access_key' => 'k', 'secret' => 's', 'endpoint' => 'https://s3.example.com', 'path_style' => true, 'delivery' => 'proxy']),
            'broken' => $this->entry(['dsn' => 's3://key:test-secret-not-real@aws/files?region=evil.com/']),
            'empty'  => $this->entry(['dsn' => '']),
        ], $logger);

        self::assertSame(['main', 'fields', 'broken', 'empty'], $profiles->names());
        self::assertTrue($profiles->has('main'));
        self::assertFalse($profiles->has('nope'));
        self::assertSame('files', $profiles->settings('main')?->bucket);
        self::assertSame('https://s3.example.com', $profiles->settings('fields')?->endpoint);
        self::assertNull($profiles->settings('broken'));
        self::assertNull($profiles->settings('empty'));
        self::assertNull($profiles->settings('nope'));
        self::assertSame(ConfigProfiles::DELIVERY_REDIRECT, $profiles->delivery('main'));
        self::assertSame(ConfigProfiles::DELIVERY_PROXY, $profiles->delivery('fields'));
        self::assertSame(ConfigProfiles::DELIVERY_PROXY, $profiles->delivery('nope'));
        self::assertCount(2, $logged);
        foreach ($logged as $line) {
            self::assertStringNotContainsString('test-secret-not-real', $line);
        }
    }
}
