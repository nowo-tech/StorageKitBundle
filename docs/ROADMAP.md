# Roadmap

## Table of contents

- [1.0 (this release)](#10-this-release)
- [Deferred](#deferred)
- [Done since 1.0](#done-since-10)
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

## Done since 1.0

- **Strip GIF / animated WebP metadata** (2.2.1, SEC-INFO-GIF-METADATA): rebuilt without decoding by
  `GifMetadataStripper` / `WebpMetadataStripper`; malformed ones refused.
- **Non-image attachments** (2.2): generic files through `FileUploaderInterface` into contexts with their own allow-list
  and size (`GenericFileContextInterface`), generated variants, the image pipeline as a port, a server-side reader and
  context-bound downloads with `Range` (ChatKit 2.7 attachments).

## Later candidates

- A platform-wide scan port and quarantine state for generic files (ChatKit owns them in 2.7, Q-259-3 option C: move
  them here when a second bundle needs them).

- Prefetch the images of a whole page in one call (today one batched resolution per text).
- Deleting a retired bucket's objects from the Move page once nothing points at them.
- Usage per owner on the Storage page and a quota helper on top of `UploadLimitInterface`.
