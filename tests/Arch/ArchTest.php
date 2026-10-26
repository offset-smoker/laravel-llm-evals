<?php

declare(strict_types=1);

arch('php preset')->preset()->php();

arch('security preset')->preset()->security();

arch('strict preset')->preset()->strict();

arch('the package never logs: no logger, Log facade or error_log anywhere in src')
    ->expect('OffsetSmoker\LlmEvals')
    ->not->toUse(['Illuminate\Support\Facades\Log', 'Psr\Log\LoggerInterface', 'Illuminate\Log', 'logger', 'info', 'error_log', 'syslog', 'openlog', 'trigger_error', 'user_error', 'fwrite', 'fputs', 'fopen', 'printf', 'vprintf', 'fprintf', 'vfprintf', 'dump', 'dd', 'var_dump', 'print_r']);

it('never writes straight to output or to a php:// stream anywhere in src', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS));
    $offenders = [];

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
            $streamLiteral = $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && stripos($token->text, 'php://') !== false;

            if ($token->is([T_ECHO, T_PRINT, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO]) || $streamLiteral) {
                $offenders[] = $file->getFilename() . ':' . $token->line . ' ' . trim($token->text);
            }
        }
    }

    expect($offenders)->toBe([]);
});

arch('configuration is read through config, never env, outside the config file')
    ->expect('OffsetSmoker\LlmEvals')
    ->not->toUse(['env', 'getenv', 'putenv']);

arch('the core does not depend on Laravel')
    ->expect(['OffsetSmoker\LlmEvals\Dataset', 'OffsetSmoker\LlmEvals\Graders', 'OffsetSmoker\LlmEvals\Runner', 'OffsetSmoker\LlmEvals\Drivers\Driver', 'OffsetSmoker\LlmEvals\Drivers\RecordedDriver', 'OffsetSmoker\LlmEvals\Drivers\ClaudeDriver'])
    ->not->toUse('Illuminate');

arch('graders implement the contract')
    ->expect('OffsetSmoker\LlmEvals\Graders')
    ->classes()
    ->toImplement('OffsetSmoker\LlmEvals\Graders\Grader')
    ->ignoring(['OffsetSmoker\LlmEvals\Graders\Grade', 'OffsetSmoker\LlmEvals\Graders\GraderFactory']);
