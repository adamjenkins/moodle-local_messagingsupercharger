# Changes

## v0.1.2

- Maturity raised from Alpha to Beta.
- The full GPL-3.0 licence text is now included as `LICENSE`. The plugin's licence is
  unchanged (GPL-3.0-or-later); the file was simply missing.
- Continuous integration now tests against the released Moodle 5.3 (MOODLE_503_STABLE)
  instead of Moodle's development branch.
- composer.json: the moodle/moodle requirement now uses caret constraints
  (`^4.5 || ^5.0`), so later Moodle 5.x releases are no longer excluded.
