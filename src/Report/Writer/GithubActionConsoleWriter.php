<?php

declare(strict_types=1);

namespace Phpcq\Report\Writer;

use Generator;
use Phpcq\PluginApi\Version10\Report\ToolReportInterface;
use Phpcq\Report\Buffer\ReportBuffer;
use Symfony\Component\Console\Output\OutputInterface;

final class GithubActionConsoleWriter
{
    use RenderRangeTrait;

    /**
     * @var OutputInterface
     */
    private $output;

    /** @var int */
    private $wrapWidth;

    /** @var ReportBuffer */
    private $report;

    /**
     * @var Generator|DiagnosticIteratorEntry[]
     * @psalm-var Generator<int, DiagnosticIteratorEntry>
     */
    private $diagnostics;

    public static function writeReport(OutputInterface $output, ReportBuffer $report): void
    {
        $instance = new self($output, $report);
        $instance->write();
    }

    public function __construct(
        OutputInterface $output,
        ReportBuffer $report,
        int $wrapWidth = 80
    ) {
        $this->output      = $output;
        $this->report      = $report;
        $this->wrapWidth   = $wrapWidth;
        $this->diagnostics = DiagnosticIterator::filterByMinimumSeverity(
            $report,
            ToolReportInterface::SEVERITY_MINOR
        )
            ->thenSortByFileAndRange()
            ->getIterator();
    }

    public function write(): void
    {
        foreach ($this->diagnostics as $diagnostic) {
            $this->writeDiagnostic($diagnostic);
        }
    }

    private function writeDiagnostic(DiagnosticIteratorEntry $entry): void
    {
        $message = $this->compileMessage($entry);
        $range   = $this->compileRange($entry);

        switch ($entry->getDiagnostic()->getSeverity()) {
            case ToolReportInterface::SEVERITY_MINOR:
                $this->output->writeln(sprintf('::warning %s::%s', $range, $message));
                break;
            default:
                $this->output->writeln(sprintf('::error %s::%s', $range, $message));
        }
    }

    private function compileMessage(DiagnosticIteratorEntry $entry): string
    {
        $diagnostic = $entry->getDiagnostic();
        $message    = $this->renderRangePrefix($entry) . $entry->getDiagnostic()->getMessage();

        $reportedBy = 'reported by ' . $entry->getTool()->getToolName();
        if (null !== ($source = $diagnostic->getSource())) {
            $reportedBy .= ': ' . $source;
        }
        $message .= ' (' . $reportedBy;

        if (null !== ($url = $diagnostic->getExternalInfoUrl())) {
            $message .= ', see ' . $url;
        }
        $message .= ')';

        return $message;
    }

    private function compileRange(DiagnosticIteratorEntry $entry): string
    {
        if (null === ($range = $entry->getRange())) {
            return '';
        }

        $buffer = 'file=' . $range->getFile();
        if (null !== ($line = $range->getStartLine())) {
            $buffer .= ',line=' . $line;
        }
        if (null !== ($column = $range->getStartColumn())) {
            $buffer .= ',col=' . $column;
        }

        return $buffer;
    }

    private function renderRangePrefix(DiagnosticIteratorEntry $entry): string
    {
        if (null === ($range = $entry->getRange()) || null === $range->getEndLine()) {
            return '';
        }

        return $this->renderRange($range) . ' ';
    }
}