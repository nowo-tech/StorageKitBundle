# Security Policy

## Supported Versions

| Version | Supported                                             |
| ------- | ----------------------------------------------------- |
| 2.x     | ✅                                                    |
| 1.x     | security fixes (Moderate+) until 6 months after 2.0.0 |
| < 1.0   | :x:                                                   |

After 2.0.0 is released, the 1.x line receives security fixes for 6 months: fixes rated Moderate and above are
backported to the last 1.x minor; Low ones are fixed in 2.x only.

## Reporting a Vulnerability

We take the security of StorageKitBundle seriously. If you believe you have found a security vulnerability, please report it privately:

- **Email**: hectorfranco@nowo.tech
- **Do not** open a public GitHub issue for security-sensitive bugs.

Please include:

- Type of issue (e.g., injection, XSS, auth bypass, deserialization risk, etc.)
- Affected file(s) and version/tag/commit
- Steps to reproduce
- Impact assessment
- PoC (if available)

## Response Timeline

- Initial acknowledgment: within 48 hours
- Follow-up status: within 7 days
- Resolution: depends on complexity and impact

## Disclosure Policy

- We confirm receipt and validate the report.
- We prepare and publish a fix as soon as possible.
- We coordinate disclosure with the reporter.
- We credit responsible disclosure (unless anonymity is requested).

For technical details and the release security checklist, see [docs/SECURITY.md](../docs/SECURITY.md).
