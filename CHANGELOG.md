# Changelog

All notable changes to this package are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-26

First release.

### Added

- Golden datasets in JSON or YAML, validated in full before anything runs.
- Graders: `contains`, `not_contains`, `regex`, `not_regex`, `json_schema`,
  `citations`, `refusal` and `reading_level`, plus `GraderFactory::extend()`
  for your own.
- A required falsifiability control per dataset (`constant`, `echo` or
  `recorded`) whose pass rate must stay at or below a ceiling. The control
  runs first; when it is too strong the subject is not run.
- Drivers: `claude` (official Anthropic PHP SDK, cached system prompt),
  `recorded`, and `CallbackDriver` for your application's own feature.
- `php artisan evals:run` with exit codes 0 to 3, `--record`, `--json` and
  `--with-outputs`.
- Pest expectations `toPassEval()` and `toPassGraders()`.
- Hardening from an independent review before release: control recordings
  are checked at load time and a control that errors is exit 3; exceptions
  from drivers and graders are contained per case without their messages;
  dataset discovery resolves symlinks; YAML alias bombs are rejected; error
  messages are stripped of control characters and never quote YAML lines;
  grader reasons never quote output; non-local JSON Schema references are
  rejected anywhere in a schema; `--record` keeps earlier responses for cases
  that error.
- From a second review: the README registers drivers and graders with
  `callAfterResolving()`, so a `--no-dev` deploy no longer breaks; JSON Schema
  references are rejected under every keyword (draft-07 `dependencies`
  included), a nested `$id` is rejected and the validator's non-standard
  extension keywords are off; `--record` checks existing recordings and the
  recordings directory before the subject runs; a dataset that stops with a
  setup error no longer drops the other datasets or the JSON report (now
  with an `errors` list); duplicate dataset names, `threshold: 0` and source
  ids the default citation format cannot cite are rejected at load; CI
  actions are pinned to commit SHAs, with Dependabot.
