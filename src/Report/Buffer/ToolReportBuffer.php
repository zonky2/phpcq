<?php

declare(strict_types=1);

namespace Phpcq\Report\Buffer;

use Generator;
use Phpcq\PluginApi\Version10\ReportInterface;

final class ToolReportBuffer
{
    /** @var string */
    private $toolName;

    /** @var string */
    private $status;

    /** @var DiagnosticBuffer[] */
    private $diagnostics = [];

    /** @var AttachmentBuffer[] */
    private $attachments = [];

    /** @var DiffBuffer[] */
    private $diffs = [];

    /** @var string */
    private $reportName;

    public function __construct(string $toolName, string $reportName)
    {
        $this->toolName   = $toolName;
        $this->reportName = $reportName;
        $this->status     = ReportInterface::STATUS_STARTED;
    }

    /**
     * Get toolName.
     *
     * @return string
     */
    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getReportName(): string
    {
        return $this->reportName;
    }

    public function setStatus(string $status): void
    {
        if ($this->status === ReportInterface::STATUS_FAILED) {
            return;
        }

        $this->status = $status;
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function addDiagnostic(DiagnosticBuffer $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /**
     * @return Generator
     *
     * @psalm-return Generator<int, DiagnosticBuffer, mixed, void>
     */
    public function getDiagnostics(): Generator
    {
        foreach ($this->diagnostics as $diagnostic) {
            yield $diagnostic;
        }
    }

    public function addAttachment(AttachmentBuffer $buffer): void
    {
        $this->attachments[] = $buffer;
    }

    public function addDiff(DiffBuffer $buffer): void
    {
        $this->diffs[] = $buffer;
    }

    /**
     * Get attachments.
     *
     * @return AttachmentBuffer[]
     * @psalm-return list<AttachmentBuffer>
     */
    public function getAttachments(): array
    {
        return array_values($this->attachments);
    }

    /**
     * Get diffs.
     *
     * @return DiffBuffer[]
     * @psalm-return list<DiffBuffer>
     */
    public function getDiffs(): array
    {
        return array_values($this->diffs);
    }
}
