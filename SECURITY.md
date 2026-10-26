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
  an architecture test fails the build if one is added. Console and JSON
  reports name cases by id and never include case inputs or the system
  prompt.
- **Model outputs** appear in reports only with `--with-outputs`, and in
  recording files only with `--record`. Both are opt-in, because outputs can
  repeat what was in the prompt. Treat recordings like any other test fixture
  that may contain data from your prompts.
- **Errors from the Claude API** are turned into a `DriverFailed` exception
  whose message holds only the HTTP status, the error type, the case id and
  the request id. The SDK exception, which carries the full HTTP request
  including the authorization header, is deliberately not chained to it, so
  an error reporter that serialises previous exceptions cannot pick it up.
- **Untrusted text in the terminal.** Model output, file contents and grader
  reasons are escaped for the Symfony console formatter and stripped of
  control characters (including ANSI escape sequences) before they are
  printed.
- **Dataset files** are validated strictly: unknown keys, wrong types and
  out-of-range values are errors; sizes are bounded (5 MB per dataset, 5,000
  cases, 200,000 characters per input; 20 MB per recording). YAML is parsed
  without PHP object, constant or custom tag support, and a `!php/object` tag
  is rejected.
- **Paths.** `evals:run` only loads datasets inside the configured datasets
  directory, after resolving symlinks. A `recorded` control must be a
  relative path inside the dataset's directory, with no `..` segments.
- **JSON Schema `$ref`s** are never resolved over the network or from the
  filesystem; a schema that needs one is rejected when the dataset loads.
- **Regular expressions** come from your own dataset files. A pattern that
  hits PCRE's backtracking limit fails its case rather than hanging the run.
  Do not load datasets from people you do not trust.

## Not covered

The package sends case inputs to Anthropic when you run the `claude` driver.
Whether that is acceptable for your data is your decision; keep personal data
out of datasets you send to any external model.
