<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

/**
 * Evaluates automation conditions against an event payload.
 *
 * Like the pricing rule evaluator, this is a matcher over a fixed vocabulary
 * rather than an interpreter. Automation rules are written by customers and
 * can send messages to guests and move money, so there is deliberately nothing
 * here that can execute: no expressions, no code, no dynamic property access.
 *
 * A condition group looks like:
 *
 *     {
 *       "match": "all",
 *       "rules": [
 *         {"field": "status", "operator": "equals", "value": "confirmed"},
 *         {"field": "nights", "operator": "greater_than_or_equal", "value": 3},
 *         {"field": "source", "operator": "in", "value": ["airbnb", "direct"]}
 *       ]
 *     }
 *
 * Groups may nest, so "confirmed AND (3+ nights OR a VIP guest)" is
 * expressible. Every evaluation returns a trace explaining what each condition
 * decided, which is stored on the run.
 */
class ConditionEvaluator
{
    /**
     * @return array<string, string> operator => description
     */
    public static function operators(): array
    {
        return [
            'equals' => 'is',
            'not_equals' => 'is not',
            'in' => 'is one of',
            'not_in' => 'is not one of',
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            'starts_with' => 'starts with',
            'greater_than' => 'is greater than',
            'greater_than_or_equal' => 'is at least',
            'less_than' => 'is less than',
            'less_than_or_equal' => 'is at most',
            'between' => 'is between',
            'is_empty' => 'is empty',
            'is_not_empty' => 'is not empty',
            'is_true' => 'is true',
            'is_false' => 'is false',
        ];
    }

    /**
     * Decide whether a rule's conditions hold.
     *
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, mixed>  $payload
     * @return array{passed: bool, trace: list<array<string, mixed>>}
     */
    public function evaluate(?array $conditions, array $payload): array
    {
        // No conditions means the rule applies whenever its trigger fires.
        if ($conditions === null || $conditions === []) {
            return ['passed' => true, 'trace' => []];
        }

        $trace = [];
        $passed = $this->evaluateGroup($conditions, $payload, $trace);

        return ['passed' => $passed, 'trace' => $trace];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $trace
     */
    private function evaluateGroup(array $group, array $payload, array &$trace): bool
    {
        $match = strtolower((string) ($group['match'] ?? 'all'));
        $rules = $group['rules'] ?? [];

        if ($rules === []) {
            return true;
        }

        $results = [];

        foreach ($rules as $rule) {
            // A nested group.
            if (isset($rule['rules'])) {
                $results[] = $this->evaluateGroup($rule, $payload, $trace);

                continue;
            }

            $results[] = $this->evaluateRule($rule, $payload, $trace);
        }

        return match ($match) {
            'any' => in_array(true, $results, true),
            'none' => ! in_array(true, $results, true),
            default => ! in_array(false, $results, true),
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $trace
     */
    private function evaluateRule(array $rule, array $payload, array &$trace): bool
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = strtolower((string) ($rule['operator'] ?? 'equals'));
        $expected = $rule['value'] ?? null;

        if ($field === '' || ! array_key_exists($operator, self::operators())) {
            // An unrecognised condition fails closed: an automation that might
            // message guests must not act on a rule it cannot understand.
            $trace[] = [
                'field' => $field,
                'operator' => $operator,
                'result' => false,
                'reason' => 'Unrecognised condition; treated as not met.',
            ];

            return false;
        }

        // Dot access into the payload only — never into an object.
        $actual = data_get($payload, $field);

        $result = $this->compare($actual, $operator, $expected);

        $trace[] = [
            'field' => $field,
            'operator' => $operator,
            'expected' => $expected,
            'actual' => is_scalar($actual) || $actual === null ? $actual : '[complex]',
            'result' => $result,
            'description' => $this->describe($field, $operator, $expected, $actual, $result),
        ];

        return $result;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => $this->looseEquals($actual, $expected),
            'not_equals' => ! $this->looseEquals($actual, $expected),

            'in' => in_array($this->normalise($actual), array_map($this->normalise(...), (array) $expected), true),
            'not_in' => ! in_array($this->normalise($actual), array_map($this->normalise(...), (array) $expected), true),

            'contains' => is_string($actual)
                && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected)),
            'not_contains' => ! (is_string($actual)
                && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected))),
            'starts_with' => is_string($actual)
                && str_starts_with(mb_strtolower($actual), mb_strtolower((string) $expected)),

            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'greater_than_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'less_than_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,

            'between' => is_numeric($actual)
                && is_array($expected)
                && count($expected) === 2
                && (float) $actual >= (float) $expected[0]
                && (float) $actual <= (float) $expected[1],

            'is_empty' => $actual === null || $actual === '' || $actual === [],
            'is_not_empty' => ! ($actual === null || $actual === '' || $actual === []),

            'is_true' => filter_var($actual, FILTER_VALIDATE_BOOLEAN),
            'is_false' => ! filter_var($actual, FILTER_VALIDATE_BOOLEAN),

            default => false,
        };
    }

    /**
     * Compare without type juggling surprises: "3" and 3 are the same value
     * in a rule a human wrote, but "0" and false are not.
     */
    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN) === filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return $this->normalise($actual) === $this->normalise($expected);
    }

    private function normalise(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return mb_strtolower(trim((string) $value));
        }

        return '';
    }

    private function describe(string $field, string $operator, mixed $expected, mixed $actual, bool $result): string
    {
        $operators = self::operators();

        return sprintf(
            '%s %s %s — %s (actual: %s)',
            str_replace('_', ' ', $field),
            $operators[$operator] ?? $operator,
            is_array($expected) ? implode(', ', array_map('strval', $expected)) : (string) $expected,
            $result ? 'met' : 'not met',
            is_scalar($actual) ? (string) $actual : 'n/a',
        );
    }

    /**
     * Validate a condition document before saving it.
     *
     * @param  array<string, mixed>  $conditions
     * @return list<string>
     */
    public function validate(array $conditions): array
    {
        $errors = [];

        $this->validateGroup($conditions, $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  list<string>  $errors
     */
    private function validateGroup(array $group, array &$errors): void
    {
        $match = strtolower((string) ($group['match'] ?? 'all'));

        if (! in_array($match, ['all', 'any', 'none'], true)) {
            $errors[] = sprintf('Unknown match mode [%s]; use all, any or none.', $match);
        }

        foreach ($group['rules'] ?? [] as $rule) {
            if (isset($rule['rules'])) {
                $this->validateGroup($rule, $errors);

                continue;
            }

            $operator = strtolower((string) ($rule['operator'] ?? ''));

            if (! array_key_exists($operator, self::operators())) {
                $errors[] = sprintf(
                    'Unknown operator [%s]. Available: %s.',
                    $operator,
                    implode(', ', array_keys(self::operators())),
                );
            }

            if (blank($rule['field'] ?? null)) {
                $errors[] = 'Every condition needs a field.';
            }

            if ($operator === 'between' && (! is_array($rule['value'] ?? null) || count($rule['value']) !== 2)) {
                $errors[] = 'A "between" condition needs exactly two values.';
            }
        }
    }
}
