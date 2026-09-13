<?php

declare(strict_types=1);

namespace AIPanel\Tasks;

/**
 * Validates a task invocation against the catalogue before it can be queued.
 *
 * This is the choke point that makes assistant-proposed work safe: the LLM
 * produces task names and parameters, and anything that is not in the
 * catalogue, has the wrong shape, or targets a node with the wrong role is
 * rejected here rather than reaching an agent.
 */
final class TaskValidator
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> the normalised parameter set
     * @throws ValidationException
     */
    public function validate(string $task, array $params, ?string $nodeRole = null): array
    {
        $errors = [];

        if (!Catalogue::has($task)) {
            throw new ValidationException(["unknown task '{$task}'"]);
        }

        $spec = Catalogue::get($task);
        $required = Catalogue::requiredRole($task);
        if ($nodeRole !== null && $required !== 'any' && $nodeRole !== $required) {
            $errors[] = "task '{$task}' requires a node with role '{$required}', got '{$nodeRole}'";
        }

        /** @var array<string,array<string,mixed>> $paramSpec */
        $paramSpec = $spec['params'];

        foreach (array_keys($params) as $key) {
            if (!isset($paramSpec[$key])) {
                $errors[] = "unexpected parameter '{$key}' for task '{$task}'";
            }
        }

        $clean = [];
        foreach ($paramSpec as $name => $rules) {
            $present = array_key_exists($name, $params) && $params[$name] !== '' && $params[$name] !== null;

            if (!$present) {
                if ($rules['required']) {
                    $errors[] = "missing required parameter '{$name}'";
                }
                continue;
            }

            $value = $params[$name];

            switch ($rules['type']) {
                case 'string':
                    if (!is_string($value)) {
                        $errors[] = "parameter '{$name}' must be a string";
                        continue 2;
                    }
                    $value = trim($value);
                    if (isset($rules['pattern']) && !preg_match((string) $rules['pattern'], $value)) {
                        $errors[] = "parameter '{$name}' has an invalid format";
                        continue 2;
                    }
                    if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                        $errors[] = "parameter '{$name}' must be one of: " . implode(', ', $rules['enum']);
                        continue 2;
                    }
                    break;

                case 'int':
                    if (!is_numeric($value)) {
                        $errors[] = "parameter '{$name}' must be an integer";
                        continue 2;
                    }
                    $value = (int) $value;
                    break;

                case 'bool':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($value === null) {
                        $errors[] = "parameter '{$name}' must be a boolean";
                        continue 2;
                    }
                    break;

                case 'array':
                    if (!is_array($value)) {
                        $errors[] = "parameter '{$name}' must be an array";
                        continue 2;
                    }
                    foreach ($value as $item) {
                        if (!is_string($item)) {
                            $errors[] = "parameter '{$name}' must contain only strings";
                            continue 3;
                        }
                    }
                    break;
            }

            $clean[$name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }
}
