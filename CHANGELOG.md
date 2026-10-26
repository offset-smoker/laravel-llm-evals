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
