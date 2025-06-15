# Directory Structure Standardization

## Issue Fixed

The project had an inconsistent directory structure for services:
- `src/Service/BridgeManager.php` (singular)
- `src/Services/` (plural) with all other services

## Resolution

✅ **Moved** `BridgeManager.php` from `src/Service/` to `src/Services/`
✅ **Updated** namespace from `App\Service` to `App\Services`
✅ **Updated** all import statements in controllers and dependency injection
✅ **Removed** empty `src/Service/` directory

## Updated Files

1. **Moved**: `src/Service/BridgeManager.php` → `src/Services/BridgeManager.php`
2. **Updated namespace**: `namespace App\Service;` → `namespace App\Services;`
3. **Updated imports**:
   - `src/Controller/BridgeController.php`
   - `src/Controller/BridgeBookingController.php`
   - `index.php` (dependency injection)

## Current Consistent Structure

```
src/
├── Bridge/
├── Controller/
├── Middleware/
└── Services/           # All services now in plural directory
    ├── AlertService.php
    ├── BridgeManager.php
    ├── CancellationDetectionService.php
    ├── CancellationService.php
    ├── DeletionSyncService.php
    └── OutlookEventDetectionService.php
```

## Benefits

- **Consistency**: All services now follow the same naming convention
- **PSR-4 Compliance**: Standard namespace structure
- **Maintainability**: Easier to locate and organize service classes
- **Convention**: Follows common PHP framework patterns (plural for collections)

All syntax validated and working correctly.
