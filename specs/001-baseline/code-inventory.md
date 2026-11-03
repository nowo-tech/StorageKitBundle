# Code inventory — 001-baseline

**Last audited**: 2026-11-03
**Scope**: every production file under `src/` (`find src -type f | wc -l`). Tests, demo and generated caches are out of scope.

## Bundle / DI

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoStorageKitBundle.php` | Bundle / DI | FR-DI-001 |
| `src/DependencyInjection/Configuration.php` | Bundle / DI | FR-DI-002 |
| `src/DependencyInjection/NowoStorageKitExtension.php` | Bundle / DI | FR-DI-003 |
| `src/DependencyInjection/Compiler/CsrfProtectionPass.php` | Bundle / DI | FR-DI-004 |
| `src/DependencyInjection/Compiler/DebugOnlySwitchesPass.php` | Bundle / DI | FR-DI-005 |
| `src/DependencyInjection/Compiler/InstanceSettingsPass.php` | Bundle / DI | FR-DI-006, FR-SET-001 |
| `src/DependencyInjection/Compiler/MessengerIntegrationPass.php` | Bundle / DI | FR-DI-007, FR-MOVE-004 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | Bundle / DI | FR-DI-008 |

## Storage

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Storage/FileStorage.php` | Storage | FR-STO-001 |
| `src/Storage/StorageConnectionFactoryInterface.php` | Storage | FR-STO-002 |
| `src/Storage/S3StorageConnectionFactory.php` | Storage | FR-STO-002, FR-SSRF-001 |
| `src/Storage/StorageConnection.php` | Storage | FR-STO-003 |
| `src/Storage/PrivateVisibilityConverter.php` | Storage | FR-STO-003 |
| `src/Storage/UrlPresignerInterface.php` | Storage | FR-STO-004 |
| `src/Storage/AsyncAwsUrlPresigner.php` | Storage | FR-STO-004 |
| `src/Storage/S3Settings.php` | Storage | FR-STO-005, FR-SSRF-001 |
| `src/Storage/StorageProvider.php` | Storage | FR-STO-006 |
| `src/Storage/StorageProbe.php` | Storage | FR-STO-007 |
| `src/Storage/StorageProbeResult.php` | Storage | FR-STO-007 |
| `src/Storage/ErrorScrubber.php` | Storage | FR-STO-008 |

## SSRF

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Security/EndpointGuard.php` | SSRF | FR-SSRF-001 |
| `src/Form/ProfileConstraints.php` | SSRF | FR-SSRF-002 |

## Profiles

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Entity/StorageProfile.php` | Profiles | FR-PROF-001 |
| `src/Profile/StorageProfiles.php` | Profiles | FR-PROF-002 |
| `src/Profile/ConfigProfiles.php` | Profiles | FR-PROF-003 |
| `src/Repository/StorageProfileRepository.php` | Profiles | FR-PROF-004 |

## Instance Settings

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Settings/StorageSettingsSection.php` | Instance Settings | FR-SET-001 |
| `src/Settings/InstanceStorageSettings.php` | Instance Settings | FR-SET-002 |
| `src/EventListener/InstanceSettingsListener.php` | Instance Settings | FR-SET-003 |

## Contexts and scopes

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Context/FileContextInterface.php` | Contexts and scopes | FR-CTX-001 |
| `src/Context/FileContextTarget.php` | Contexts and scopes | FR-CTX-001 |
| `src/Context/FileContexts.php` | Contexts and scopes | FR-CTX-002 |
| `src/Scope/ScopeResolverInterface.php` | Contexts and scopes | FR-SCOPE-001 |
| `src/Scope/TargetScopeResolver.php` | Contexts and scopes | FR-SCOPE-001 |
| `src/Scope/ScopeAccessCheckerInterface.php` | Contexts and scopes | FR-SCOPE-002 |
| `src/Scope/DenyAllScopeAccessChecker.php` | Contexts and scopes | FR-SCOPE-002 |
| `src/Scope/StorageScope.php` | Contexts and scopes | FR-SCOPE-003 |

## Upload

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/FileUploadController.php` | Upload | FR-UP-001 |
| `src/Upload/ImageUploader.php` | Upload | FR-UP-002 |
| `src/Upload/ImageProcessor.php` | Upload | FR-UP-003 |
| `src/Upload/ProcessedImage.php` | Upload | FR-UP-003 |
| `src/Upload/UploadEndpoint.php` | Upload | FR-UP-004 |
| `src/Upload/UploadLimitInterface.php` | Upload | FR-UP-005 |
| `src/Upload/UploadRefused.php` | Upload | FR-UP-006 |

## Download and rendering

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/FileDownloadController.php` | Download and rendering | FR-DL-001 |
| `src/Download/FileDelivery.php` | Download and rendering | FR-DL-002 |
| `src/Download/FileUrls.php` | Download and rendering | FR-DL-003 |
| `src/Download/StoredImageResolver.php` | Download and rendering | FR-DL-004 |
| `src/Download/StoredImage.php` | Download and rendering | FR-DL-004 |
| `src/Http/StorageCspSources.php` | Download and rendering | FR-DL-005 |
| `src/Integration/HttpHardening/StorageCspSourceProvider.php` | Download and rendering | FR-DL-005 |
| `src/Twig/StorageKitTwigExtension.php` | Download and rendering | FR-DL-006, FR-UP-004 |

## References and purge

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Reference/FileReferenceSourceInterface.php` | References and purge | FR-REF-001 |
| `src/Reference/ExistingIds.php` | References and purge | FR-REF-001 |
| `src/Reference/FileReferenceListener.php` | References and purge | FR-REF-002 |
| `src/Reference/FileReferences.php` | References and purge | FR-REF-003 |
| `src/Reference/FileUris.php` | References and purge | FR-REF-004 |
| `src/Lifecycle/FilePurger.php` | References and purge | FR-PURGE-001 |
| `src/Command/PurgeFilesCommand.php` | References and purge | FR-PURGE-002 |

## Moves

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Sync/StorageMove.php` | Moves | FR-MOVE-001 |
| `src/Sync/StorageSyncer.php` | Moves | FR-MOVE-002 |
| `src/Sync/SyncFailure.php` | Moves | FR-MOVE-002 |
| `src/Entity/StorageSync.php` | Moves | FR-MOVE-003 |
| `src/Repository/StorageSyncRepository.php` | Moves | FR-MOVE-003 |
| `src/Message/RunStorageSyncMessage.php` | Moves | FR-MOVE-004 |
| `src/MessageHandler/RunStorageSyncHandler.php` | Moves | FR-MOVE-004 |
| `src/Command/StorageSyncCommand.php` | Moves | FR-MOVE-005 |
| `src/Command/AdoptInstanceBucketCommand.php` | Moves | FR-MOVE-006 |

## Files data

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Entity/StoredFile.php` | Files data | FR-DATA-001 |
| `src/Entity/StoredFileReference.php` | Files data | FR-DATA-001, FR-REF-003 |
| `src/Repository/StoredFileRepository.php` | Files data | FR-DATA-001 |
| `src/Doctrine/StorageEntityManager.php` | Files data | FR-DATA-002 |
| `src/Doctrine/TablePrefixListener.php` | Files data | FR-DATA-002 |

## Personal data and audit

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/PersonalData/StoredFilesPersonalData.php` | Personal data and audit | FR-GDPR-001 |
| `src/Audit/AuditRecorder.php` | Personal data and audit | FR-AUD-001 |
| `src/Audit/StorageAuditEvent.php` | Personal data and audit | FR-AUD-001 |

## Admin UI and access

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/StorageSettingsController.php` | Admin UI and access | FR-UI-001 |
| `src/Controller/StorageMoveController.php` | Admin UI and access | FR-UI-002 |
| `src/Form/StorageProfileType.php` | Admin UI and access | FR-UI-003 |
| `src/Ui/AdminPages.php` | Admin UI and access | FR-UI-004 |
| `src/Ui/StorageKitPages.php` | Admin UI and access | FR-UI-004 |
| `src/Ui/ProfileTester.php` | Admin UI and access | FR-UI-004, FR-STO-007 |
| `src/Ui/Bytes.php` | Admin UI and access | FR-UI-004 |
| `src/Resources/views/settings/edit.html.twig` | Admin UI and access | FR-UI-005 |
| `src/Resources/views/settings/_aside.html.twig` | Admin UI and access | FR-UI-005 |
| `src/Resources/views/move/edit.html.twig` | Admin UI and access | FR-UI-005 |
| `src/Routing/StorageKitRouteLoader.php` | Admin UI and access | FR-UI-006 |
| `src/Security/AccessGuard.php` | Admin UI and access | FR-ACC-001 |
| `src/Security/StorageAccessCheckerInterface.php` | Admin UI and access | FR-ACC-002 |
| `src/Security/RoleAccessChecker.php` | Admin UI and access | FR-ACC-002 |
| `src/Security/AllowAllStorageAccessChecker.php` | Admin UI and access | FR-ACC-002 |
| `src/EventListener/StorageKitAccessListener.php` | Admin UI and access | FR-ACC-003 |

## Translations

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoStorageKitBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoStorageKitBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoStorageKitBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoStorageKitBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoStorageKitBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoStorageKitBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoStorageKitBundle.pt.yaml` | Translations | FR-I18N-001 |

## Coverage summary

| Category | Files |
| --- | --- |
| Bundle / DI | 8 |
| Storage | 12 |
| SSRF | 2 |
| Profiles | 4 |
| Instance Settings | 3 |
| Contexts and scopes | 8 |
| Upload | 7 |
| Download and rendering | 8 |
| References and purge | 7 |
| Moves | 9 |
| Files data | 5 |
| Personal data and audit | 3 |
| Admin UI and access | 16 |
| Translations | 7 |
| **Total production sources** | **99 / 99** (`find src -type f \| wc -l` = 99) |
