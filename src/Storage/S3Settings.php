<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use InvalidArgumentException;
use Nowo\OutboundUrlGuardBundle\Network\PrivateNetworkTarget;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use SensitiveParameter;

use function array_filter;
use function array_shift;
use function array_values;
use function explode;
use function filter_var;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * Where an S3-compatible bucket lives and how to reach it: AWS S3 (no endpoint) or any compatible service (Cloudflare
 * R2, Wasabi, Backblaze B2, Scaleway, MinIO, RustFS…) at `endpoint`. `publicEndpoint` is the address browsers follow
 * for pre-signed URLs when it differs from the one the server uses (a container name in dev); null = the same.
 *
 * Every option that ends up in a URL is validated here, whatever its source (SEC-STORAGE-SSRF, MailKit audit CA M1):
 * the region is a plain `[a-z0-9-]` token (it builds `s3.<region>.amazonaws.com`), the bucket follows the S3 naming
 * rules, endpoints are `http(s)://host[:port]` with a valid host name or IP literal and nothing else (no user info,
 * path, query or fragment), the prefix has no `..` segment. This is structural validation; whether the server may
 * connect to the endpoint is {@see EndpointGuard}'s decision. `restricted` profiles
 * (edited from a page) go through that guard and its connect-time filter.
 *
 * DSN form (credentials URL-encoded):
 *
 *   s3://ACCESS_KEY:SECRET@host[:port]/bucket[/prefix]?region=eu-west-1&path_style=1&tls=0&public_endpoint=http://localhost:9124
 *
 * Host `aws` (or `s3.amazonaws.com`) means AWS itself (no custom endpoint). The secret never appears in exceptions,
 * `var_dump()` / `print_r()` output or {@see self::fingerprint()}.
 */
final readonly class S3Settings
{
    /** A region token: lowercase letters, digits and inner dashes (us-east-1, fr-par, auto). */
    public const REGION_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** Longest accepted region token. */
    public const REGION_MAX = 32;

    /** S3 bucket naming rules (3–63 characters, lowercase letters, digits, dots and dashes). */
    public const BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/';

    /** Object key prefix: the S3 "safe characters" and slashes, at most 191 characters. */
    public const PREFIX_PATTERN = '#^[A-Za-z0-9!_.*\'()\-/]{0,191}$#';

    /** Longest accepted access key id. */
    public const ACCESS_KEY_MAX = 191;

    /** Longest accepted secret. */
    public const SECRET_MAX = 255;

    /** Region used when none is given. */
    public const DEFAULT_REGION = 'us-east-1';

    public string $region;

    public ?string $endpoint;

    public ?string $publicEndpoint;

    public string $prefix;

    /**
     * @param string $bucket bucket name
     * @param string $accessKey access key id
     * @param string $secret secret access key (never shown)
     * @param string $region region token
     * @param string|null $endpoint server-side endpoint (`http(s)://host[:port]`), null = AWS
     * @param string|null $publicEndpoint browser-facing endpoint for pre-signed URLs, null = the server-side one
     * @param string $prefix object key prefix inside the bucket
     * @param bool $pathStyle address the bucket in the path instead of the host name
     * @param bool $restricted edited from a page: the server connects through the SSRF guard
     *
     * @throws InvalidArgumentException when an option is malformed (the message never contains the secret)
     */
    public function __construct(
        public string $bucket,
        public string $accessKey,
        #[SensitiveParameter]
        public string $secret,
        string $region = self::DEFAULT_REGION,
        ?string $endpoint = null,
        ?string $publicEndpoint = null,
        string $prefix = '',
        public bool $pathStyle = false,
        public bool $restricted = false,
    ) {
        if (preg_match(self::BUCKET_PATTERN, $bucket) !== 1 || str_contains($bucket, '..')) {
            throw new InvalidArgumentException('The bucket name must follow the S3 naming rules (3-63 lowercase letters, digits, dots and dashes).');
        }
        if ($accessKey === '' || strlen($accessKey) > self::ACCESS_KEY_MAX || preg_match('/[\x00-\x20\x7F]/', $accessKey) === 1) {
            throw new InvalidArgumentException('The access key must be 1-191 printable characters without spaces.');
        }
        if ($secret === '' || strlen($secret) > self::SECRET_MAX || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            throw new InvalidArgumentException('The secret must be 1-255 printable characters.');
        }
        $this->region         = self::region($region);
        $this->endpoint       = self::endpoint($endpoint, 'endpoint');
        $this->publicEndpoint = self::endpoint($publicEndpoint, 'public endpoint');
        $this->prefix         = self::prefix($prefix);
    }

    /**
     * Parses a `s3://KEY:SECRET@host[:port]/bucket[/prefix]?region=…&path_style=1&tls=0&public_endpoint=…` DSN.
     *
     * @throws InvalidArgumentException when the DSN is malformed (generic message: the DSN holds the secret)
     */
    public static function fromDsn(#[SensitiveParameter] string $dsn, bool $restricted = false): self
    {
        $parts = parse_url(trim($dsn));
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 's3' || !isset($parts['host'], $parts['user'], $parts['pass'])) {
            throw new InvalidArgumentException('The storage DSN must look like s3://KEY:SECRET@host/bucket.');
        }
        $path = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $s): bool => $s !== ''));
        if ($path === []) {
            throw new InvalidArgumentException('The storage DSN needs a bucket: s3://KEY:SECRET@host/bucket.');
        }
        parse_str($parts['query'] ?? '', $query);
        $option   = static fn (string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';
        $host     = strtolower($parts['host']);
        $endpoint = null;
        if (!in_array($host, ['aws', 's3.amazonaws.com'], true)) {
            $endpoint = ($option('tls') === '0' ? 'http' : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return new self(
            bucket: array_shift($path),
            accessKey: rawurldecode($parts['user']),
            secret: rawurldecode($parts['pass']),
            region: $option('region') !== '' ? $option('region') : self::DEFAULT_REGION,
            endpoint: $endpoint,
            publicEndpoint: $option('public_endpoint') !== '' ? $option('public_endpoint') : null,
            prefix: implode('/', $path),
            pathStyle: in_array($option('path_style'), ['1', 'true'], true),
            restricted: $restricted,
        );
    }

    /**
     * The same settings with the guard on or off.
     */
    public function withRestricted(bool $restricted): self
    {
        return new self($this->bucket, $this->accessKey, $this->secret, $this->region, $this->endpoint, $this->publicEndpoint, $this->prefix, $this->pathStyle, $restricted);
    }

    /**
     * Whether the S3 client addresses the bucket in the path. Restricted profiles with a custom endpoint always do,
     * so the host the server connects to is exactly the endpoint host the guard checked (no `bucket.` sub-domain).
     */
    public function usesPathStyle(): bool
    {
        return $this->pathStyle || ($this->restricted && $this->endpoint !== null);
    }

    /**
     * The origin browsers load pre-signed URLs from (CSP `img-src`), with the same addressing as the S3 client.
     */
    public function browserOrigin(): string
    {
        $base   = $this->publicEndpoint ?? $this->endpoint ?? 'https://s3.' . $this->region . '.amazonaws.com';
        $parts  = (array) parse_url($base);
        $host   = (string) ($parts['host'] ?? '');
        $port   = $parts['port'] ?? null;
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($this->virtualHosted($host) ? $this->bucket . '.' : '') . $host;

        return is_int($port) ? $origin . ':' . $port : $origin;
    }

    /**
     * Identifies a connection (memoised clients) without exposing the secret.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\n", [$this->bucket, $this->accessKey, hash('sha256', $this->secret), $this->region, (string) $this->endpoint, (string) $this->publicEndpoint, $this->prefix, $this->pathStyle ? '1' : '0', $this->restricted ? 'r' : '']));
    }

    /**
     * The object key under the prefix (`prefix/2026/10/<uuid>`).
     */
    public function key(string $relative): string
    {
        return $this->prefix === '' ? $relative : $this->prefix . '/' . $relative;
    }

    /**
     * Debug output without the credentials.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'bucket'         => $this->bucket,
            'accessKey'      => '***',
            'secret'         => '***',
            'region'         => $this->region,
            'endpoint'       => $this->endpoint,
            'publicEndpoint' => $this->publicEndpoint,
            'prefix'         => $this->prefix,
            'pathStyle'      => $this->pathStyle,
            'restricted'     => $this->restricted,
        ];
    }

    /**
     * A region token, validated (it builds the AWS host name `s3.<region>.amazonaws.com`).
     *
     * @throws InvalidArgumentException
     */
    public static function region(string $region): string
    {
        $region = trim($region) === '' ? self::DEFAULT_REGION : trim($region);
        if (strlen($region) > self::REGION_MAX || preg_match(self::REGION_PATTERN, $region) !== 1) {
            throw new InvalidArgumentException('The region must be a token of lowercase letters, digits and dashes (for example eu-west-1).');
        }

        return $region;
    }

    /**
     * An object key prefix without leading / trailing slashes, `..` or empty segments.
     *
     * @throws InvalidArgumentException
     */
    public static function prefix(string $prefix): string
    {
        $prefix = trim($prefix, " \t/");
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1 || str_contains($prefix, '//')) {
            throw new InvalidArgumentException('The prefix may only hold letters, digits, slashes and !_.*\'()- (at most 191 characters).');
        }
        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('The prefix must not contain "." or ".." segments.');
            }
        }

        return $prefix;
    }

    /**
     * An endpoint normalised to `scheme://host[:port]`, or null.
     *
     * @throws InvalidArgumentException when it is not a bare http(s) origin
     */
    public static function endpoint(?string $endpoint, string $what = 'endpoint'): ?string
    {
        $endpoint = $endpoint === null ? '' : trim($endpoint);
        if ($endpoint === '') {
            return null;
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || preg_match('/[\s\\\\;,\'"<>]/', $endpoint) === 1
        ) {
            throw new InvalidArgumentException(sprintf('The %s must be an http(s) origin such as https://s3.example.com (no path, query or credentials).', $what));
        }
        $host      = (string) $parts['host'];
        $ipv6      = str_starts_with($host, '[') && str_ends_with($host, ']');
        $bare      = $ipv6 ? substr($host, 1, -1) : $host;
        $ascii     = PrivateNetworkTarget::asciiHost($bare);
        $validHost = $ascii !== null && $ascii !== '' && (
            ($ipv6 && filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
            || (!$ipv6 && (PrivateNetworkTarget::canonicalIp($ascii) !== null || PrivateNetworkTarget::isValidHostName(trim($ascii, '.'))))
        );
        if (!$validHost) {
            throw new InvalidArgumentException(sprintf('The %s host is not a valid host name or IP address.', $what));
        }
        $port = $parts['port'] ?? null;

        return strtolower($parts['scheme']) . '://' . ($ipv6 ? '[' . strtolower($bare) . ']' : (string) $ascii) . (is_int($port) ? ':' . $port : '');
    }

    /**
     * async-aws puts the bucket in the host name unless path style is asked for or the name / host cannot carry it.
     */
    private function virtualHosted(string $host): bool
    {
        return !$this->usesPathStyle()
            && !str_contains($this->bucket, '.')
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }
}
