<?php

declare(strict_types=1);

namespace Phpcq\OutputTransformer;

use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;

final class ConsoleOutputTransformerFactory implements OutputTransformerFactoryInterface
{
    /**
     * @var string
     */
    private $toolName;

    /**
     * ConsoleOutputTransformerFactory constructor.
     *
     * @param string $toolName
     */
    public function __construct(string $toolName)
    {
        $this->toolName = $toolName;
    }

    /** @SuppressWarnings(PHPMD.UnusedLocalVariable) */
    public function createFor(ToolReportInterface $report): OutputTransformerInterface
    {
        return new class ($report) implements OutputTransformerInterface {
            /** @var ToolReportInterface */
            private $report;
            /** @var BufferedLineReader */
            private $data;
            /** @var string */
            private $stdErr = '';
            /** @var string */
            private $stdOut = '';

            /**
             * Create a new instance.
             */
            public function __construct(ToolReportInterface $report)
            {
                $this->report = $report;
                $this->data   = new BufferedLineReader();
            }

            public function write(string $data, int $channel): void
            {
                $this->data->push($data);
                if (OutputInterface::CHANNEL_STDOUT === $channel) {
                    $this->stdOut .= $data;
                    return;
                }
                if (OutputInterface::CHANNEL_STDERR === $channel) {
                    $this->stdErr .= $data;
                    return;
                }
            }

            public function finish(int $exitCode): void
            {
                $content = '';
                while ($line = $this->data->fetch()) {
                    $content .= $line;
                }

                [$status, $severity] = $this->calculateStatusAndSeverity($exitCode);
                $this->report->addDiagnostic($severity, $content)->end();

                if ('' !== $this->stdErr) {
                    $this->report->addBufferAsAttachment($this->stdErr, 'stderr.log');
                }
                if ('' !== $this->stdOut) {
                    $this->report->addBufferAsAttachment($this->stdOut, 'stdout.log');
                }

                $this->report->finish($status);
            }

            /**
             * @return string[]
             *
             * @psalm-return array{0: string, 1: string}
             */
            private function calculateStatusAndSeverity(int $exitCode): array
            {
                if (0 === $exitCode) {
                    return [
                        ToolReportInterface::STATUS_PASSED,
                        ToolReportInterface::SEVERITY_INFO,
                    ];
                }
                return [
                    ToolReportInterface::STATUS_FAILED,
                    ToolReportInterface::SEVERITY_ERROR,
                ];
            }
        };
    }
}