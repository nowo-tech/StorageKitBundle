# Roadmap

## Table of contents

- [1.0 (this release)](#10-this-release)
- [Deferred](#deferred)
- [Later candidates](#later-candidates)

## 1.0 (this release)

S3-compatible buckets for the instance (`storage` Instance Settings section or a configuration profile) and for scopes,
image uploads for Markdown editors with host-decided access, proxied or pre-signed downloads, reference-aware purge,
verified bucket moves with switch, SSRF-guarded endpoints, audit events and GDPR hooks. See the
[CHANGELOG](CHANGELOG.md#100---2026-11-03).

## Deferred

| Item | Why it waits | Source |
| --- | --- | --- |
| **Local filesystem provider** | 1.0 is external-storage only (Beacon requires an S3-compatible bucket). A local adapter would need its own download path, purge and move rules. User decision pending. | — |
| **Image proxy for stable caching** | Proxy downloads cost one PHP request per `<img>`, and pre-signed URLs change on every request, so browsers cache for `url_ttl − 60` s only. Options: fetch-join the profile, a stable expiry bucket for signatures, a same-origin caching image proxy. | PERF-FILE-DOWNLOAD-REQUEST |
| **Imagick / worker-side processing** | GD decodes in the request: about 96 MB per 24 MP image (the default cap; progressive JPEGs need about 144 MB more in libjpeg), and FrankenPHP worker threads keep their peak RSS. Options: size hints, Imagick, processing on a Messenger worker. | PERF-IMAGE-GD-PEAK-MEMORY |
| **Strip GIF / animated WebP metadata** | They are stored as uploaded (re-encoding with GD loses the animation): comments and XMP survive. Strip extension blocks without re-encoding. | SEC-INFO-GIF-METADATA |
| **Non-image attachments** | Uploads accept PNG, JPEG, GIF and WebP only. PDFs and other files need their own allow-list, download disposition and preview rules. | — |

## Later candidates

- Prefetch the images of a whole page in one call (today one batched resolution per text).
- Deleting a retired bucket's objects from the Move page once nothing points at them.
- Usage per owner on the Storage page and a quota helper on top of `UploadLimitInterface`.
