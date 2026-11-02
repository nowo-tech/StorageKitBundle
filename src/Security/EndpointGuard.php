<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Security;

use InvalidArgumentException;
use Nowo\OutboundUrlGuardBundle\Dns\HostnameDnsLookup;
use Nowo\OutboundUrlGuardBundle\Network\PrivateNetworkTarget;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;

use const DNS_A;
use const DNS_AAAA;
use const PHP_URL_HOST;

/**
 * SEC-STORAGE-SSRF: where the server may send storage requests for a profile edited from a page (`restricted`
 * settings: every scope profile, and the instance profile unless `endpoints.guard_instance_profile` is false).
 *
 * Two layers:
 *
 * 1. {@see self::check()} before saving / testing / connecting: the endpoint host (IDNA-mapped, every IP spelling —
 *    decimal, octal, hex, short, IPv4-mapped / NAT64 / 6to4 IPv6 — canonicalised by outbound-url-guard) must not be a
 *    cloud-metadata host or address (always refused), nor — unless allow-listed or `allow_private` — localhost,
 *    `*.local` / `*.internal`, a single-label name, or a private / reserved address; with `resolve_dns`, every A /
 *    AAAA answer is checked and an unresolvable name is refused.
 * 2. {@see self::httpClient()} for every request of a restricted profile: Symfony's {@see NoPrivateNetworkHttpClient}
 *    checks the address actually connected to (DNS rebinding, redirects), redirects are off and requests time out.
 *    For an allow-listed host or `allow_private`, only the metadata ranges stay blocked at connect time.
 *
 * Config-tree profiles (ops configuration) and an unguarded instance profile skip layer 1 and use a plain client with
 * the same timeout. The browser-facing endpoint is only signed for, never fetched by the server.
 */
final readonly class EndpointGuard
{
    /** Ranges closed at connect time even for allow-listed / private endpoints (link-local covers 169.254.169.254). */
    public const METADATA_SUBNETS = ['169.254.0.0/16', 'fe80::/10', '100.100.100.200/32', 'fd00:ec2::254/128', '::ffff:169.254.0.0/112'];

    /** Reason: the endpoint is private, local, reserved or cloud metadata. */
    public const NOT_ALLOWED = 'endpoint_not_allowed';

    /** Reason: the endpoint host does not resolve. */
    public const UNRESOLVABLE = 'endpoint_unresolvable';

    /** Reason: the endpoint is not a valid http(s) origin. */
    public const INVALID = 'endpoint_invalid';

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts hosts accepted even when private (exact, case-insensitive)
     * @param bool $allowPrivate accept private / loopback hosts (dev only; cloud metadata stays refused)
     * @param bool $resolveDns resolve names and refuse those pointing at private addresses
     * @param float $requestTimeout seconds of one storage request (REQ-RUNTIME-001)
     */
    public function __construct(
        array $allowedHosts = [],
        private bool $allowPrivate = false,
        private bool $resolveDns = true,
        private HostnameDnsLookup $dns = new HostnameDnsLookup(),
        private float $requestTimeout = 30.0,
    ) {
        $this->allowedHosts = array_values(array_map(static fn (string $host): string => strtolower(rtrim(trim($host, '[] '), '.')), $allowedHosts));
    }

    /**
     * Null when the endpoint may be used, else a reason ({@see self::NOT_ALLOWED}, {@see self::UNRESOLVABLE},
     * {@see self::INVALID}). Null / empty = AWS itself: allowed.
     */
    public function check(?string $endpoint): ?string
    {
        if ($endpoint === null || trim($endpoint) === '') {
            return null;
        }
        try {
            $endpoint = (string) S3Settings::endpoint($endpoint);
        } catch (InvalidArgumentException) {
            return self::INVALID;
        }
        $host = parse_url($endpoint, PHP_URL_HOST);

        return $this->checkHost(is_string($host) ? $host : '');
    }

    /**
     * {@see self::check()} of the server-side endpoint of restricted settings; unrestricted settings pass.
     */
    public function checkSettings(S3Settings $settings): ?string
    {
        return $settings->restricted ? $this->check($settings->endpoint) : null;
    }

    /**
     * The HTTP client the bucket's requests go through: for restricted settings, the connect-time private-network
     * filter, no redirects; always the request timeout.
     */
    public function httpClient(?HttpClientInterface $client, S3Settings $settings): HttpClientInterface
    {
        $client = ($client ?? HttpClient::create())->withOptions(['timeout' => $this->requestTimeout, 'max_duration' => $this->requestTimeout * 4]);
        if (!$settings->restricted) {
            return $client;
        }
        $host    = $settings->endpoint === null ? null : parse_url($settings->endpoint, PHP_URL_HOST);
        $relaxed = $this->allowPrivate || (is_string($host) && $this->isAllowListed($host));

        return new NoPrivateNetworkHttpClient($client->withOptions(['max_redirects' => 0]), $relaxed ? self::METADATA_SUBNETS : null);
    }

    /**
     * Whether a host is on `endpoints.allowed_hosts`.
     */
    public function isAllowListed(string $host): bool
    {
        return in_array(strtolower(rtrim(trim($host, '[] '), '.')), $this->allowedHosts, true);
    }

    private function checkHost(string $host): ?string
    {
        // check() passes a host S3Settings::endpoint() validated: a valid name or IP literal.
        $ascii = rtrim((string) PrivateNetworkTarget::asciiHost(trim($host, '[] ')), '.');
        $ip    = PrivateNetworkTarget::canonicalIp($ascii);
        if (PrivateNetworkTarget::isCloudMetadataHost($ascii) || ($ip !== null && PrivateNetworkTarget::isCloudMetadataIp($ip))) {
            return self::NOT_ALLOWED;
        }
        if ($this->isAllowListed($ascii) || $this->allowPrivate) {
            return null;
        }
        if (PrivateNetworkTarget::isBlockedHostName($ascii)) {
            return self::NOT_ALLOWED;
        }
        if ($ip !== null) {
            return PrivateNetworkTarget::isBlockedIp($ip) ? self::NOT_ALLOWED : null;
        }
        // A single-label name ("minio", "rustfs") is an internal service name: allow-list it explicitly.
        if (!str_contains($ascii, '.')) {
            return self::NOT_ALLOWED;
        }
        if (!$this->resolveDns) {
            return null;
        }
        $addresses = $this->resolve($ascii);
        if ($addresses === []) {
            return self::UNRESOLVABLE;
        }
        foreach ($addresses as $address) {
            $canonical = PrivateNetworkTarget::canonicalIp($address) ?? $address;
            if (PrivateNetworkTarget::isBlockedIp($canonical) || PrivateNetworkTarget::isCloudMetadataIp($canonical)) {
                return self::NOT_ALLOWED;
            }
        }

        return null;
    }

    /**
     * A and AAAA addresses of a host name (gethostbynamel fallback), through the bounded lookup.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = $this->dns->dnsGetRecord($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_array($record) && is_string($record[$field] ?? null) && $record[$field] !== '') {
                    $addresses[] = $record[$field];
                }
            }
        }
        if ($addresses === []) {
            $fallback = $this->dns->hostByNameL($host);
            foreach (is_array($fallback) ? $fallback : [] as $address) {
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
