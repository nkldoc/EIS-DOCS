<?php

declare(strict_types=1);

function safeCmp(string $knownValue, string $userValue): bool
{
    return hash_equals($knownValue, $userValue);
}

