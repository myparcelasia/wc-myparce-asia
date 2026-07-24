# Changelog

## [1.0.8] - 2026-07-24
### Fixed
- Fix /check_price API now sends provider_code parameter, reducing response time from ~5s to ~1s per call.

## [1.0.7] - 2026-07-23
### Fixed
- Fix AWB price missing SST value: now calculated as effective_price + sst_price from API response.

## [1.0.6] - 2026-07-23
### Fixed
- Fix checkout page not showing shipping options with custom themes: register shipping method natively with WooCommerce and auto-add to all shipping zones.
- Replace aggressive zone cleanup with automatic zone setup to ensure shipping method is always available.

## [1.0.5] - 2026-07-23
### Added
- Add backward compatibility with previous plugin (mpawoo): MYPARCEL ASIA metabox, To Process, and batch pages now reflect legacy shipping item data (tracking number, courier code, shipping price).
- Increase pagination from 10 to 50 rows per page across all list views.

## [1.0.4] - 2026-07-23
### Added
- Add search by customer name, pagination, and dynamic `#` row numbering column to "To Process", "Manage Batches", and "Manage Batch (Create AWB)" tables.
- Stylize pagination controls to match design mockup with active/disabled states.

### Fixed
- Fix connection verification error alert when saving API Settings connection.
- Fix returning results with correctly aligned css classes on Detail View page.

## [1.0.3] - 2026-07-20
### Fixed
- Fix: Prevent duplicated "Connection failed" message when API returns an empty error message.


## [1.0.2] - 2026-07-17
### Changed
- Change updater to new file location


## [1.0.1] - 2026-07-17
### Fixed
- Fix: chosen courier at checkout is now reflected correctly in Order Page and To Process page

## [1.0.0] - Initial Release
- Core shipping calculation and WooCommerce integration
- Batch AWB creation
- Single AWB creation
