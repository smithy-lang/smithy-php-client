<?php

declare(strict_types=1);

namespace Smithy\Client\Endpoint\Bytecode;

/**
 * Opcode and condition-type constants for the endpoint bytecode evaluator.
 *
 * These are integer constants read from the packed instruction stream by
 * {@see Evaluator}. Numeric values match smithy-java's rules-engine so that
 * bytecode blobs are portable across languages if we ever need to swap them.
 *
 * The class is never instantiated. All members are class-level constants
 * to give the PHP tracing JIT a hint that they can be inlined.
 */
final class Opcodes
{
    private function __construct() {}

    /** Push a constant onto the stack. Operand: 1-byte const index. */
    public const LOAD_CONST = 0;

    /** Push a constant onto the stack. Operand: 2-byte const index. */
    public const LOAD_CONST_W = 1;

    /** Store top of stack into a register (does not pop). Operand: 1-byte register. */
    public const SET_REGISTER = 2;

    /** Load a register value onto the stack. Operand: 1-byte register. */
    public const LOAD_REGISTER = 3;

    /** Replace top-of-stack with its logical negation. */
    public const NOT = 4;

    /** Replace top-of-stack with `true` if non-null, else `false`. */
    public const ISSET = 5;

    /** Push `true` iff register is non-null. Operand: 1-byte register. */
    public const TEST_REGISTER_ISSET = 6;

    /** Push `true` iff register is null. Operand: 1-byte register. */
    public const TEST_REGISTER_NOT_SET = 7;

    /** Push an empty list. */
    public const LIST0 = 8;

    /** Wrap top-of-stack in a single-element list. */
    public const LIST1 = 9;

    /** Wrap the top two stack values in a two-element list. */
    public const LIST2 = 10;

    /** Pop N values into a list. Operand: 1-byte size. */
    public const LISTN = 11;

    /** Push an empty map. */
    public const MAP0 = 12;

    /** Build a single-entry map from stack `[value, key]`. */
    public const MAP1 = 13;

    /** Build a two-entry map. */
    public const MAP2 = 14;

    /** Build a three-entry map. */
    public const MAP3 = 15;

    /** Build a four-entry map. */
    public const MAP4 = 16;

    /** Build an N-entry map. Operand: 1-byte size. */
    public const MAPN = 17;

    /**
     * Concatenate N string arguments popped from the stack. This is
     * essentially the smithy rules-engine string template of the form
     * "foo{X}bar" pre-split into literal/reference parts at compile time.
     *
     * Operand: 1-byte argument count.
     */
    public const RESOLVE_TEMPLATE = 18;

    /** Call function with zero arguments. Operand: 1-byte function index. */
    public const FN0 = 19;

    /** Call function with one argument. */
    public const FN1 = 20;

    /** Call function with two arguments. */
    public const FN2 = 21;

    /** Call function with three arguments. */
    public const FN3 = 22;

    /** Call function with N arguments (function-defined arity). */
    public const FN = 23;

    /** Get named property from top-of-stack. Operand: 2-byte prop-name const index. */
    public const GET_PROPERTY = 24;

    /** Get element by (unsigned) index. Operand: 1-byte index. */
    public const GET_INDEX = 25;

    /** Get named property from a register. Operand: 1-byte register, 2-byte prop-name const. */
    public const GET_PROPERTY_REG = 26;

    /** Get element by index from a register. Operand: 1-byte register, 1-byte index. */
    public const GET_INDEX_REG = 27;

    /** Replace top-of-stack with `true` iff it is boolean `true`. */
    public const IS_TRUE = 28;

    /** Push `true` iff register is boolean `true`. Operand: 1-byte register. */
    public const TEST_REGISTER_IS_TRUE = 29;

    /** Push `true` iff register is boolean `false`. Operand: 1-byte register. */
    public const TEST_REGISTER_IS_FALSE = 30;

    /** Pop two values, push equality (loose deep-equality). */
    public const EQUALS = 31;

    /** Pop two strings, push equality. */
    public const STRING_EQUALS = 32;

    /** Pop two booleans, push equality. */
    public const BOOLEAN_EQUALS = 33;

    /** Substring. Operands: 1-byte start, 1-byte end, 1-byte reverse-flag. */
    public const SUBSTRING = 34;

    /** Pop `[string, allowDots]`, push validity. */
    public const IS_VALID_HOST_LABEL = 35;

    /** Pop URL string, push parsed URL structure (or null). */
    public const PARSE_URL = 36;

    /** Percent-encode top-of-stack string. */
    public const URI_ENCODE = 37;

    /** Pop error message and throw. */
    public const RETURN_ERROR = 38;

    /**
     * Build & return an endpoint from stack values. Operand: 1-byte flags
     * (bit 0 = has headers, bit 1 = has properties).
     */
    public const RETURN_ENDPOINT = 39;

    /** Pop value and return it. */
    public const RETURN_VALUE = 40;

    /** Split string. Stack: `[string, delimiter, limit]` -> list. */
    public const SPLIT = 41;

    /** Jump-if-non-null-or-pop. Operand: 2-byte forward offset. */
    public const JNN_OR_POP = 42;

    /** Negative-index accessor (positive byte, means -N). */
    public const GET_NEGATIVE_INDEX = 43;

    /** Negative-index accessor for register. */
    public const GET_NEGATIVE_INDEX_REG = 44;

    /** Pop top. if not TRUE, jump forward. Operand: 2-byte forward offset. */
    public const JMP_IF_FALSE = 45;

    /** Unconditional forward jump. Operand: 2-byte offset. */
    public const JUMP = 46;

    /**
     * Substring-of-register equals a constant string.
     * Operands: 1-byte register, 1-byte start, 1-byte end, 1-byte flags,
     * 2-byte const index.
     */
    public const SUBSTRING_EQ = 47;

    /**
     * Split register value by constant delimiter, get element at index.
     * Operands: 1-byte register, 2-byte delimiter const, 1-byte signed index.
     */
    public const SPLIT_GET = 49;

    /**
     * Select constant based on boolean register value.
     * Operands: 1-byte register, 2-byte true-const, 2-byte false-const.
     */
    public const SELECT_BOOL_REG = 50;

    /**
     * Compare register string value to a constant string.
     * Operands: 1-byte register, 2-byte const index.
     */
    public const STRING_EQUALS_REG_CONST = 51;

    /**
     * Store top-of-stack into a register and return it (fused SET+RETURN).
     * Operand: 1-byte register.
     */
    public const SET_REG_RETURN = 52;

    /**
     * Build a URI from `[host, path]`, scheme from constant pool.
     * Operand: 2-byte scheme const index.
     */
    public const BUILD_URI = 53;

    /**
     * Same stack layout as MAPN, but produces a lightweight property-getter
     * object instead of a general map. Currently equivalent to MAPN in PHP.
     * kept as a separate opcode for compatibility with the Java bytecode.
     */
    public const STRUCTN = 54;

    /**
     * Static-template fusion. Replaces the LOAD_CONST / LOAD_REGISTER /
     * GET_PROPERTY_REG per-segment sequence + RESOLVE_TEMPLATE with a
     * single opcode that walks a compact metadata record and emits the
     * concatenated string in one arm.
     *
     * Operands: 2-byte constant-pool index pointing at a
     * `list<array{0:int, 1:mixed}>` where the leading int is a segment
     * tag:
     *   - 0: literal string. Payload is the string.
     *   - 1: register ref. Payload is the int register index.
     *   - 2: register-property ref. Payload is `[reg-idx, prop-const-idx]`.
     */
    public const BUILD_TEMPLATE = 55;

    // Each BDD condition has a type tag telling the BDD walker whether the
    // condition can be evaluated inline (without entering the bytecode
    // dispatch loop) and, if so, which specialized check to perform.

    /** Condition is "register X is non-null". Operand: 1-byte register. */
    public const COND_ISSET = 1;

    /** Condition is "register X is boolean true". Operand: 1-byte register. */
    public const COND_IS_TRUE = 2;

    /** Condition is "register X is boolean false". Operand: 1-byte register. */
    public const COND_IS_FALSE = 3;

    /** Condition is "register X is null". Operand: 1-byte register. */
    public const COND_NOT_SET = 4;

    /**
     * Condition is "register X (as string) equals constant Y".
     * Operand: packed int, low 8 bits = register, upper bits = const index.
     */
    public const COND_STRING_EQ_REG_CONST = 5;

    /** Condition must be evaluated via full bytecode dispatch. */
    public const COND_FULL = 0;
}
