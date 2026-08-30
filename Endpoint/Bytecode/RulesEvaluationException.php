<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

use RuntimeException;

/**
 * Thrown when endpoint bytecode fails to produce a valid endpoint.
 *
 * Two sources:
 *  - The ruleset explicitly returned an error result (e.g., "FIPS is
 *    enabled but this partition does not support FIPS"). Message is the
 *    error string from the ruleset.
 *  - The evaluator hit an internal fault (bad opcode, stack underflow,
 *    type mismatch). Message includes the offending program counter.
 */
final class RulesEvaluationException extends RuntimeException {}
