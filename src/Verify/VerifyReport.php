<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\Status;

final class VerifyReport
{
    /** @var list<array{step: string, status: Status, detail: string}> */
    private array $steps = [];

    /** @var array<string, mixed> */
    public array $backup = [];

    /** @var list<array<string, mixed>> */
    public array $databases = [];

    /** @var array<string, mixed>|null */
    public ?array $manifest = null;

    public ?string $keptAt = null;

    public function add(string $step, Status $status, string $detail): void
    {
        $this->steps[] = ['step' => $step, 'status' => $status, 'detail' => $detail];
    }

    public function addResult(string $step, CheckResult $result): void
    {
        $this->add($step, $result->status, $result->message);
    }

    /**
     * @return list<array{step: string, status: Status, detail: string}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function status(): Status
    {
        if ($this->steps === []) {
            return Status::Fail;
        }

        return Status::worst(...array_column($this->steps, 'status'));
    }

    public function passed(): bool
    {
        return $this->status() !== Status::Fail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'backup' => $this->backup,
            'steps' => array_map(fn (array $step): array => [...$step, 'status' => $step['status']->value], $this->steps),
            'databases' => $this->databases,
            'manifest' => $this->manifest,
            'kept_at' => $this->keptAt,
        ];
    }
}
