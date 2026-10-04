# Changelog

All notable changes to this plugin are documented here, newest first, in the
[Keep a Changelog](https://keepachangelog.com/) format.

## [0.1.3] - 2026-10-04

### Changed

- Release archives leave out development files (`.github`, `.camp`, `tests` and similar) through `.gitattributes` export-ignore rules, which the camp release workflow requires. No change to the plugin itself; this release carries 0.1.2 to the camp registry.

## [0.1.2] - 2026-10-04

### Added

- The full GPL-3.0 licence text as `LICENSE` in the repository root. The plugin's licence is
  unchanged (GPL-3.0-or-later); the file was simply missing.

### Changed

- Maturity raised from Alpha to Beta.
- Continuous integration tests against the released Moodle 5.3 (MOODLE_503_STABLE) instead of
  Moodle's development branch.
- composer.json: the moodle/moodle requirement uses caret constraints (`^4.5 || ^5.0`), so
  later Moodle 5.x releases are no longer excluded.

## [0.1.1] - 2026-10-04

### Added

- Attachments by button, drag and drop or paste, with progress and clear limit errors;
  images shown as wide as the panel allows, with a full-size viewer.
- Rich text editor in a dialogue, with attachments and embedded images.
- @-mentions in group conversations with their own notification type.
- Reactions; edit and delete-for-everyone of your own messages with an "edited" marker and
  earlier versions; pinned messages.
- Message email for one-to-one conversations held for a short delay and sent once, with
  the text as it stands.
- Scheduled send, checked again at the time of sending.
- Message search across all conversations; "seen by" in group conversations.
- Link previews (off by default, protected against server-side request forgery).
- Moodle 4.5, 5.1, 5.2 and 5.3; installable with Composer (`composer.json`).
- Attachment safety: server-side content checks (programs and disguised files refused),
  scanning by the site's antivirus through Moodle's antivirus manager, Moodle's draft upload
  rate limit, a per-user storage quota and optional attachment retention.
- Resizable message drawer.
- Privacy API provider for all plugin data.
