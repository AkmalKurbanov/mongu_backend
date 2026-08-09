# Changelog

All notable changes to this project will be documented in this file.

## [1.0.1] - 2025

### Changed
- Switched to `SettingModel` base class for the Settings model.
- Added `category` to backend settings registration.
- Improved temp file cleanup — guaranteed deletion via `finally` block.

## [1.0.2] - 2026-04-22

### Changed
- Aligned plugin code and author code with the Marketplace author code `DMdev`.

## [1.0.0] - 2025

### Added
- Initial release.
- Auto-resize uploaded images to fit within configurable max dimensions (no upscaling).
- Convert all uploaded images to WebP format (quality 85).
- Read dimension limits from Tailor blueprint field config (`imageWidth` / `imageHeight`).
- Global fallback settings with defaults of 1200×1200 px.
- Enable/disable toggle in backend settings.
- Safe error handling — upload is never interrupted by processing failures.
