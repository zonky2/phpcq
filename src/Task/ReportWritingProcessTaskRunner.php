<?php

declare(strict_types=1);

namespace Phpcq\Task;

use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\RuntimeException;
use Phpcq\PluginApi\Version10\TaskRunnerInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;
use Symfony\Component\Process\Process;
use Throwable;
use Traversable;

/**
 * This task runner executes a process.
 */
class ReportWritingProcessTaskRunner implements TaskRunnerInterface
{
    /**
     * @var string[]
     */
    private $command;

    /**
     * @var string|null
     */
    private $cwd;

    /**
     * @var string[]|null
     */
    private $env;

    /**
     * @var resource|string|Traversable|null
     */
    private $input;

    /**
     * @var int|float|null
     */
    private $timeout;

    /**
     * @var ToolReportInterface
     */
    private $report;

    /**
     * @var OutputTransformerFactoryInterface
     */
    private $transformer;

    /**
     * @param string[]                          $command The command to run and its arguments listed as separate entries
     * @param ToolReportInterface               $report
     * @param OutputTransformerFactoryInterface $transformer
     * @param string|null                       $cwd     The working directory or null to use the working dir of the
     *                                                   current PHP process
     * @param string[]|null $env                         The environment variables or null to use the same environment
     *                                                   as the current PHP process
     * @param resource|string|Traversable|null  $input   The input as stream resource, scalar or \Traversable, or null
     *                                                   for no input
     * @param int|float|null                    $timeout The timeout in seconds or null to disable
     */
    public function __construct(
        array $command,
        ToolReportInterface $report,
        OutputTransformerFactoryInterface $transformer,
        string $cwd = null,
        array $env = null,
        $input = null,
        ?float $timeout = 60
    ) {
        $this->command     = $command;
        $this->cwd         = $cwd;
        $this->env         = $env;
        $this->input       = $input;
        $this->timeout     = $timeout;
        $this->report      = $report;
        $this->transformer = $transformer;
    }

    public function run(OutputInterface $output): void
    {
        $process = new Process($this->command, $this->cwd, $this->env, $this->input, $this->timeout);
        $output->writeln(
            'Executing: ' . $process->getCommandLine(),
            OutputInterface::VERBOSITY_VERBOSE,
            OutputInterface::CHANNEL_STDERR
        );

        $transformer = $this->transformer->createFor($this->report);
        try {
            // Fixme: Move fail handling to the processor
            $process->run(function (string $type, string $data) use ($output, $transformer) {
                switch ($type) {
                    case Process::ERR:
                        $transformer->write($data, OutputInterface::CHANNEL_STDERR);
                        $output->write($data, OutputInterface::VERBOSITY_VERY_VERBOSE, OutputInterface::CHANNEL_STDERR);
                        return;
                    case Process::OUT:
                        $transformer->write($data, OutputInterface::CHANNEL_STDOUT);
                        $output->write($data, OutputInterface::VERBOSITY_VERY_VERBOSE);
                        return;
                }
            });
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Process failed with exit code ' . (string) $process->getExitCode() . ': ' . $process->getCommandLine(),
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            $transformer->finish((int) $process->getExitCode());
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Process failed with exit code ' . (string) $process->getExitCode() . ': ' . $process->getCommandLine(),
                (int) $process->getExitCode()
            );
        }
    }
}