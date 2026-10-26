<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Reports;

use OffsetSmoker\LlmEvals\Runner\CaseResult;
use OffsetSmoker\LlmEvals\Runner\RunReport;
use OffsetSmoker\LlmEvals\Runner\SuiteResult;
use OffsetSmoker\LlmEvals\Runner\Verdict;
use OffsetSmoker\LlmEvals\Support\Text;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a run for people: one line per case, the failing graders' reasons,
 * and the aggregate. Everything that came from a file or a model is escaped
 * and stripped of control characters before it reaches the terminal.
 */
final class ConsoleReport
{
    public static function render(RunReport $report, OutputInterface $output, bool $withOutputs = false): void
    {
        $dataset = $report->dataset;
        $output->writeln('');
        $output->writeln('<options=bold>' . self::escape($dataset->name) . '</>  ' . count($dataset->cases) . ' cases, threshold ' . self::percent($dataset->threshold));

        $control = $report->control;
        $output->writeln(sprintf(
            '  control (%s): %d/%d passed, %s, ceiling %s  %s',
            self::escape($control->driver),
            $control->passedCount(),
            count($control->cases),
            self::percent($control->passRate()),
            self::percent($dataset->control->ceiling),
            $report->controlHeld() ? '<info>held</info>' : '<error>TOO STRONG</error>',
        ));

        if (! $report->controlHeld()) {
            $output->writeln('  <comment>The control passed more cases than its ceiling allows, so these graders cannot tell good output from bad. The subject was not run.</comment>');
            self::cases($control, $output, false, onlyPassing: true);
        }

        $subject = $report->subject;

        if ($subject !== null) {
            $output->writeln('  subject (' . self::escape($subject->driver) . '):');
            self::cases($subject, $output, $withOutputs, onlyPassing: false);
            $output->writeln(sprintf(
                '  %d/%d passed (%s), mean grader score %s%s',
                $subject->passedCount(),
                count($subject->cases),
                self::percent($subject->passRate()),
                self::percent($subject->meanScore()),
                self::usage($subject),
            ));
        }

        $verdict = $report->verdict();
        $tag = $verdict === Verdict::Passed ? 'info' : 'error';
        $output->writeln("  <{$tag}>" . strtoupper($verdict->label()) . "</{$tag}> (exit {$verdict->value})");
    }

    private static function cases(SuiteResult $suite, OutputInterface $output, bool $withOutputs, bool $onlyPassing): void
    {
        foreach ($suite->cases as $case) {
            if ($onlyPassing && ! $case->passed()) {
                continue;
            }

            $mark = $case->passed() ? '<info>✓</info>' : '<error>✗</error>';
            $output->writeln("    {$mark} " . self::escape($case->caseId) . self::detail($case));

            foreach ($case->graderResults as $graded) {
                if ($graded->grade->passed && ! $onlyPassing) {
                    continue;
                }

                $output->writeln('        ' . self::escape($graded->grader) . ': ' . self::escape($graded->grade->reason));
            }

            if ($withOutputs && $case->response !== null) {
                foreach (explode("\n", Text::terminalSafe($case->response->text)) as $line) {
                    $output->writeln('        │ ' . OutputFormatter::escape($line));
                }
            }
        }
    }

    private static function detail(CaseResult $case): string
    {
        if ($case->error !== null) {
            return '  ' . self::escape($case->error);
        }

        $stop = $case->response?->stopReason;

        return $stop !== null && $stop !== 'end_turn' ? '  (stop reason: ' . self::escape($stop) . ')' : '';
    }

    private static function usage(SuiteResult $suite): string
    {
        $usage = $suite->usage();

        if ($usage->isEmpty()) {
            return '';
        }

        return sprintf(
            '; tokens: %d in, %d out, %d cache read, %d cache write',
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cacheReadInputTokens,
            $usage->cacheCreationInputTokens,
        );
    }

    private static function escape(string $text): string
    {
        return OutputFormatter::escape(Text::excerpt($text, 300));
    }

    private static function percent(float $fraction): string
    {
        return round($fraction * 100, 1) . '%';
    }
}
