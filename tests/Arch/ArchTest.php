<?php

declare(strict_types=1);

arch('php preset')->preset()->php();

arch('security preset')->preset()->security();

arch('strict preset')->preset()->strict();

arch('the package never logs: no logger, Log facade or error_log anywhere in src')
    ->expect('OffsetSmoker\LlmEvals')
    ->not->toUse(['Illuminate\Support\Facades\Log', 'Psr\Log\LoggerInterface', 'Illuminate\Log', 'logger', 'info', 'error_log', 'syslog', 'openlog', 'trigger_error', 'user_error', 'fwrite', 'fputs', 'dump', 'dd', 'var_dump', 'print_r']);

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
