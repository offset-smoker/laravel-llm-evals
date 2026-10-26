<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Drivers;

/**
 * What a driver produced for one case. `stopReason` carries the provider's
 * stop reason when there is one, so a refusal the provider reports as a
 * stop reason is graded as a refusal even when the text is empty.
 */
final readonly class Response
{
    public function __construct(
        public string $text,
        public ?string $stopReason = null,
        public ?string $model = null,
        public Usage $usage = new Usage(),
    ) {}
}
