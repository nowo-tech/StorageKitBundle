<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;

use const DNS_A;

/**
 * DNS double: host => list of addresses (IPv4 as A, IPv6 as AAAA); `fallback` answers only gethostbynamel.
 */
final class FakeDns extends HostnameDnsLookup
{
    /**
     * @param array<string, list<string>> $records
     * @param array<string, list<string>> $fallback
     */
    public function __construct(
        private readonly array $records = [],
        private readonly array $fallback = [],
    ) {
        parent::__construct(1.0, '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dnsGetRecord(string $hostname, int $type): array
    {
        $rows = [];
        foreach ($this->records[$hostname] ?? [] as $address) {
            $v6 = str_contains($address, ':');
            if ($type === DNS_A && !$v6) {
                $rows[] = ['ip' => $address];
            } elseif ($type !== DNS_A && $v6) {
                $rows[] = ['ipv6' => $address];
            }
        }

        return $rows;
    }

    public function hostByNameL(string $hostname): array|false
    {
        return $this->fallback[$hostname] ?? false;
    }
}
