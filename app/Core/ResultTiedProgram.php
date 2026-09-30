<?php
declare(strict_types=1);

namespace App\Core;

final class ResultTiedProgram
{
    public function __construct(
        public readonly int $resultId,
        public readonly int $programId,
    ) {
        if ($this->resultId < 1 || $this->programId < 1) {
            throw new \InvalidArgumentException('Result tied program identities must be positive.');
        }
    }
}
