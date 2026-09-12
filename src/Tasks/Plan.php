<?php

declare(strict_types=1);

namespace AIPanel\Tasks;

/**
 * An ordered, validated list of task invocations awaiting human approval.
 */
final class Plan implements \JsonSerializable
{
    /** @var list<array{task:string,node_id:int,params:array<string,mixed>,destructive:bool}> */
    private array $steps = [];

    public function __construct(public readonly string $summary = '')
    {
    }

    /** @param array<string,mixed> $params */
    public function addStep(string $task, int $nodeId, array $params): void
    {
        $this->steps[] = [
            'task' => $task,
            'node_id' => $nodeId,
            'params' => $params,
            'destructive' => Catalogue::isDestructive($task),
        ];
    }

    /** @return list<array{task:string,node_id:int,params:array<string,mixed>,destructive:bool}> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    public function hasDestructiveSteps(): bool
    {
        foreach ($this->steps as $step) {
            if ($step['destructive']) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'summary' => $this->summary,
            'destructive' => $this->hasDestructiveSteps(),
            'steps' => $this->steps,
        ];
    }
}
