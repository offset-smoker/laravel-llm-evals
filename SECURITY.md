# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please report vulnerabilities privately by email to b@wagner.codes, not in a
public issue. Include the version, a description of the problem and, if you
can, the steps or a dataset that reproduce it. You should get a reply within
five working days. Once a fix is released, the report is credited in the
changelog unless you ask otherwise.

## What the package does to keep data where it belongs

This section describes the package's behaviour so you can check it against
your own requirements. It is not a guarantee about your deployment.

- **API keys** are read only from configuration (`llm-evals.claude.api_key`,
  which the published config fills from `ANTHROPIC_API_KEY`). The package
  never writes a key to output, reports, recordings or exceptions.
- **Prompts are never logged.** The package has no logging calls at all, and
  architecture tests fail the build if one is added: a logger, `Log`,
  `error_log`, `syslog`, `trigger_error`, `fopen`, `fwrite`, `fputs`, the
  `printf` family, `echo`, `print`, inline output, or any `php://` stream
  name in `src/`. All console output goes through the Symfony console output
  of `evals:run`. Console and JSON reports name cases by id and never include
  case inputs or the system prompt.
- **Model outputs** appear in reports only with `--with-outputs`, and in
  recording files only with `--record`. Both are opt-in, because outputs can
  repeat what was in the prompt. Treat recordings like any other test fixture
  that may contain data from your prompts.
- **Exceptions from drivers and graders** are contained per case. A
  `DriverFailed` keeps its message; any other exception is reported only by
  class, file and line, because its message may quote the prompt. An
  exception raised while a case runs never reaches the application's error
  reporter.
- **Errors from the Claude API** are turned into a `DriverFailed` exception
  whose message holds only the HTTP status, the error type, the case id and
  the request id. The SDK exception, which carries the full HTTP request
  including the authorization header, is deliberately not chained to it, so
  an error reporter that serialises previous exceptions cannot pick it up.
- **Grader reasons never quote the output.** They name the expectation and
  what was found (a count of invented citations, the violated JSON Schema
  keyword and its location in the schema), so a default report holds no
  model output.
- **Untrusted text in the terminal.** Model output, keys and values from
  dataset files, grader reasons and every error message are escaped for the
  Symfony console formatter and stripped of control characters (including
  ANSI escape sequences) before they are printed. YAML parse errors give the
  line number, not the line.
- **Dataset files** are validated strictly: unknown keys, wrong types and
  out-of-range values are errors; sizes are bounded (5 MB per dataset, 5,000
  cases, 200,000 characters per input; 20 MB per recording; 250,000 values
  once YAML aliases are expanded, so a small alias bomb is rejected before
  it is walked). YAML is parsed without PHP object, constant or custom tag
  support, and a `!php/object` tag is rejected. Names and ids are checked
  against anchored patterns that also refuse a trailing newline.
- **Paths.** `evals:run` only loads datasets inside the configured datasets
  directory, after resolving symlinks, whether it lists the directory or is
  given dataset names. A `recorded` control must be a relative path with no
  `..` segments that still resolves inside the dataset's directory after
  symlinks are resolved.
- **Recordings** are written atomically, and `--record` keeps the earlier
  response for any case that errors, so an outage cannot erase a good
  recording.
- **JSON Schema references.** Any `$ref`, `$dynamicRef` or `$recursiveRef`
  that does not start with `#`, anywhere in the schema and under any keyword,
  is rejected when the dataset loads. So is an `$id` below the root schema,
  because it would change what a local reference points at. The validator's
  non-standard extension keywords (`$filters`, `$slots`, `$inject`, `$data`
  and the like) are turned off. Nothing is resolved over the network or from
  the filesystem.
- **Regular expressions** come from your own dataset files. A pattern that
  hits PCRE's backtracking limit fails its case rather than hanging the run.
  Do not load datasets from people you do not trust.

## Not covered

The package sends case inputs to Anthropic when you run the `claude` driver.
Whether that is acceptable for your data is your decision; keep personal data
out of datasets you send to any external model.
