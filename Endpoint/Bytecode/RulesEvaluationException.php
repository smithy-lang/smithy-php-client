<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

use RuntimeException;

/**
 * Report a rules error or invalid endpoint bytecode evaluation.
 *
 * The ruleset may explicitly return an error, such as an unsupported
 * parameter combination, or the evaluator may encounter an internal fault
 * such as an unknown opcode, invalid result reference, or a program that
 * reaches the end without returning.
 */
final class RulesEvaluationException extends RuntimeException {}
