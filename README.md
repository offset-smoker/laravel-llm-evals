# offset-smoker/laravel-llm-evals

An evaluation harness for the LLM features in a Laravel application.

A prompt change, a model upgrade or a new system message can quietly make an
LLM feature worse, and a normal test suite will not notice, because the code
still runs. This package checks the *output*: you write golden cases in JSON
or YAML, each with code-based graders (contains, regex, JSON Schema,
citations, refusal, reading level), and `php artisan evals:run` scores your
feature against them and exits non-zero when it falls below a threshold. Every
dataset must also declare a deliberately bad baseline, and the run fails if
that baseline scores too well, because graders that a fixed answer can pass
are not measuring anything. Runs can replay recorded responses, so CI needs
no API key and spends nothing.

## Requirements

- PHP 8.3 or 8.4
- Laravel 12 (12.69 or later) or 13 (13.30 or later)
- A PSR-18 HTTP client for the Claude driver. Laravel applications already
  have Guzzle.

## Installation

```bash
composer require --dev offset-smoker/laravel-llm-evals
```

The service provider is discovered automatically. Publishing the config is
optional:

```bash
php artisan vendor:publish --tag=llm-evals-config
```

## Usage

The package ships a small synthetic dataset (plain-language rewrites of
notices from a fictional "Example Township") and a recording of good
responses to it. Copy both into your application and run it:

```bash
mkdir -p evals/recordings
cp vendor/offset-smoker/laravel-llm-evals/examples/evals/plain-language-notices.yaml evals/
cp vendor/offset-smoker/laravel-llm-evals/examples/evals/recordings/plain-language-notices.json evals/recordings/
php artisan evals:run
```

```text
plain-language-notices  3 cases, threshold 100%
  control (constant): 1/3 passed, 33.3%, ceiling 34%  held
  subject (recorded):
    ✓ water-main-closure
    ✓ permit-hearing-json
    ✓ refuses-resident-address
  3/3 passed (100%), mean grader score 100%
  PASSED (exit 0)

1 dataset run, exit code 0.
```

### Evaluate your own feature

Register the feature as a driver, in `AppServiceProvider::boot()` for
example. The closure receives each case and returns the text your feature
produced (or a `Response`, if you also want to pass a stop reason or token
usage):

```php
use App\Services\NoticeRewriter;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\CallbackDriver;
use OffsetSmoker\LlmEvals\Drivers\DriverRegistry;

$this->app->make(DriverRegistry::class)->extend('notice-rewriter', fn () => new CallbackDriver(
    fn (EvalCase $case): string => $this->app->make(NoticeRewriter::class)->rewrite($case->input),
    'notice-rewriter',
));
```

```bash
php artisan evals:run --driver=notice-rewriter
```

If your feature cannot answer a case, throw
`OffsetSmoker\LlmEvals\Drivers\DriverFailed` with a message that is safe to
print. Any other exception also fails just that case, but the report shows
only its class, file and line, because exception messages often quote the
prompt.

A failing case lists the graders it failed and why:

```text
  subject (notice-rewriter):
    ✗ water-main-closure
        contains "Elm Street": not found
        cites every source: cites 0 of the required 1
        reads at grade 8.0 or below: grade 28.2
```

### Evaluate Claude directly, and record the result

The `claude` driver sends each case to Claude through the official Anthropic
PHP SDK (`anthropic-ai/sdk`), with the dataset's `system` prompt as the
system message. `--record` saves the responses as the dataset's recording,
so the same outputs can be re-graded offline later:

```bash
ANTHROPIC_API_KEY=your-key php artisan evals:run --driver=claude --record
```

### In Pest

With Pest installed, the package adds two expectations:

```php
use OffsetSmoker\LlmEvals\Drivers\RecordedDriver;

it('passes the notices dataset', function () {
    expect(base_path('evals/plain-language-notices.yaml'))
        ->toPassEval(RecordedDriver::fromDirectory(base_path('evals/recordings')));
});

it('keeps rewrites readable', function () {
    expect('The water main under Elm Street is off on Tuesday at 9 a.m. [notice-101]')
        ->toPassGraders([['contains' => 'Elm Street'], ['reading_level' => 8], ['citations' => true]], ['notice-101']);
});
```

`toPassEval()` also takes a closure, `fn (EvalCase $case): string`, in place
of a driver.

### In CI

```yaml
- run: mkdir -p build && php artisan evals:run --json=build/evals.json
```

| Exit code | Meaning |
|---|---|
| 0 | Every dataset passed |
| 1 | A subject scored below its dataset's threshold |
| 2 | A control scored above its ceiling, so that dataset's subject was not run |
| 3 | Invalid input: a dataset, recording, argument or configuration problem |

With several datasets the highest code wins. An empty datasets directory is
exit 3, not a pass.

## Writing a dataset

```yaml
name: plain-language-notices      # lowercase; also the recording's file name
threshold: 1.0                    # share of cases that must pass, 0 to 1 (default 1)
system: |                         # optional; sent as the system prompt by the claude driver
  You rewrite public notices for residents...

control:                          # required, see below
  constant: "I can't help with that."
  ceiling: 0.34

cases:
  - id: water-main-closure
    sources: [notice-101]         # ids the output may cite
    input: |
      Rewrite notice-101 in plain language: ...
    expect:
      - contains: Elm Street
      - regex: /Tuesday/
      - citations: {min: 1, all: true}
      - reading_level: {max_grade: 8}
      - refusal: false
```

A case passes when all of its graders pass. A dataset passes when its share
of passing cases is at or above `threshold`. Reports also show a mean grader
score (the share of graders passed, averaged over cases) to show partial
progress.

Everything in the dataset files is validated before anything runs. Unknown
keys, wrong types, duplicate case ids, invalid regular expressions or
schemas, a case with no graders, and a missing or incomplete control are all
errors that name the file and the location, such as
`cases[2].expect[0].regex is not a valid PCRE pattern`. Case ids may not be
all digits.

Grader reasons describe the expectation and what was found without quoting
the output: `contains "Elm Street": not found`, `cites 1 id(s) that are not
sources of this case`, `schema violations: pattern at #/properties/date`.

### Graders

| Grader | Arguments | Passes when |
|---|---|---|
| `contains` | `"text"` or `{value, case_sensitive}` | the output contains the text (case-insensitive by default) |
| `not_contains` | same as `contains` | the output does not contain the text |
| `regex` | `"/pattern/flags"` | the PCRE pattern matches |
| `not_regex` | `"/pattern/flags"` | the pattern does not match |
| `json_schema` | a JSON Schema object | the output is JSON valid against the schema; one Markdown code fence around it is allowed. Only local `$ref`s (`#/...`) are accepted, `$id` only at the root, and the validator's non-standard extension keywords are off |
| `citations` | `true` or `{min, all, pattern}` | at least `min` (default 1) of the case's `sources` are cited, no cited id is outside them, and with `all` every source is cited. Citations look like `[notice-101]`; `pattern` takes a PCRE with a named `(?<id>...)` group |
| `refusal` | `true`, `false` or `{expected, phrases}` | the output refuses (or answers) as expected. A `refusal` stop reason from the provider counts as a refusal |
| `reading_level` | a number or `{max_grade}` | the Flesch-Kincaid grade level is at or below the number |

Add your own with the `GraderFactory`. The closure gets the grader entry and
its type key:

```php
use OffsetSmoker\LlmEvals\Graders\GraderFactory;
use OffsetSmoker\LlmEvals\Support\Spec;

$this->app->make(GraderFactory::class)->extend('mentions_township', fn (Spec $entry, string $type) => new MentionsTownship);
```

`MentionsTownship` implements `OffsetSmoker\LlmEvals\Graders\Grader`
(`describe(): string` and `grade(Response $response, EvalCase $case): Grade`).

### The falsifiability control

Every dataset names a control: a deliberately bad baseline that its graders
should reject.

| Control | What it returns |
|---|---|
| `constant: "text"` | the same text for every case |
| `echo: true` | each case's input, unchanged |
| `recorded: controls/bad.json` | responses from a recording file, relative to the dataset's directory |

Keep control recordings in a subdirectory such as `controls/`: every `.json`
and `.yaml` file at the top level of the datasets directory is loaded as a
dataset. A control recording must exist when the dataset loads, stay inside
the dataset's directory (symlinks are resolved), and hold a response for
every case. A control that cannot produce an answer would "fail" every case
and look as if it held, so a control error is exit 3, never a pass.

The control's pass rate must be at or below `ceiling` (default 0), and the
ceiling must be below the dataset's `threshold`. The control runs first. When
it passes more cases than its ceiling allows, the run stops with exit code 2
and the subject is never called, so a dataset whose graders cannot tell good
output from bad cannot report a pass, and does not spend money on a model
call to find that out. The report lists the cases the control passed and the
graders it satisfied, which are the ones to tighten.

The example dataset's control is a fixed refusal. It correctly passes the one
case that expects a refusal, and fails the other two, so its ceiling is 0.34.

## Configuration

`config/llm-evals.php`:

| Key | Default | Purpose |
|---|---|---|
| `datasets_path` | `base_path('evals')` | where datasets are read; arguments to `evals:run` must resolve inside it |
| `recordings_path` | `base_path('evals/recordings')` | where the `recorded` driver reads and `--record` writes `<name>.json` |
| `default_driver` | `recorded` (`LLM_EVALS_DRIVER`) | the driver used without `--driver` |
| `claude.api_key` | `ANTHROPIC_API_KEY` | read from the environment only |
| `claude.model` | `claude-opus-5-5` (`LLM_EVALS_CLAUDE_MODEL`) | for example `claude-sonnet-5-5` or `claude-haiku-5-5`; cloud-platform ids such as `us.anthropic.claude-opus-5-5` are accepted too |
| `claude.max_tokens` | `4096` (`LLM_EVALS_CLAUDE_MAX_TOKENS`) | 1 to 32,000 |
| `claude.effort` | `medium` (`LLM_EVALS_CLAUDE_EFFORT`) | `low`, `medium`, `high`, `xhigh` or `max` |

To send requests through another Anthropic client (a different base URL, or
Claude on a cloud platform), bind an `Anthropic\Client` in the container; the
`claude` driver uses that binding instead of building one from the key.

`evals:run` options: `--driver=`, `--record`, `--json=path.json`, and
`--with-outputs` to include model outputs in the console and JSON reports
(they are left out by default; see [SECURITY.md](SECURITY.md)).

## Design notes

- **No refusal fallback.** The Claude driver does not configure a fallback
  model. An eval measures the model it names; if a refusal were silently
  answered by another model, the score would describe a model you did not
  choose. A refusal is reported as a stop reason and graded.
- **Prompt caching.** The `system` prompt is sent as one block with
  `cache_control: ephemeral`, so every case after the first can read it from
  the cache. The report shows cache reads and writes, which is how you can
  tell. Prompts shorter than the model's minimum cacheable length are not
  cached.
- **Code-based graders only.** There is no LLM-as-judge grader. Each grader
  is deterministic, so a recorded run grades the same way every time.

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

The suite has 192 tests: unit tests for every grader, the dataset loader, the
runner, recordings and the Claude driver; feature tests for `evals:run` and
the Pest expectations on Orchestra Testbench; and architecture tests,
including one that fails if anything in `src/` logs. The Claude driver's
tests go through the real SDK, with a PSR-18 client that replays recorded
Messages API responses, so no test touches the network. Larastan runs at
level `max` with `phpstan-strict-rules`, no baseline and no ignored errors.
CI runs all of it on PHP 8.3 and 8.4, on Laravel 12 and 13, with the highest
and the lowest allowed dependencies.

## Status and limitations

Version 0.1.0. The core is complete and tested, but it is new, so expect the
API to move before 1.0.

- The Claude driver sends one user message per case: no multi-turn
  conversations, images, documents or tools, and no streaming (hence the
  32,000 `max_tokens` cap).
- Cases run one after another. There is no concurrency and no Message
  Batches support, so large live runs are slow.
- Each case is run once. There is no repeated sampling or statistical
  comparison between runs.
- Token counts are reported, but not converted to cost.
- `reading_level` uses Flesch-Kincaid with a heuristic syllable count, and the
  `refusal` phrase list is English. Both are estimates; leave a margin.
- The recorded API responses in the test fixtures were written by hand in the
  Messages API format, not captured from live calls, and CI never calls the
  live API. The SDK request and response handling is exercised; the live
  service is not.
- JSON Schemas written inline in YAML or JSON lose the difference between
  `{}` and `[]`. In schema positions (`properties` entries, `items`, `allOf`
  members and so on) an empty value is read as `{}`, the schema that accepts
  anything. Inside `enum`, `const`, `default` and `examples`, an empty map is
  read as an empty list.
- Developed and tested on Linux and macOS. Path handling on Windows has not
  been tested.

## How this was built

Written with Claude Code under my direction and review.

## License

MIT. See [LICENSE](LICENSE).
