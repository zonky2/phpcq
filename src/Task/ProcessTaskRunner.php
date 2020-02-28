<?php

declare(strict_types=1);

namespace Phpcq\Task;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;
use Traversable;

/**
 * This task runner executes a process.
 */
class ProcessTaskRunner implements TaskRunnerInterface
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
     * @param string[]       $command The command to run and its arguments listed as separate entries
     * @param string|null    $cwd     The working directory or null to use the working dir of the current PHP process
     * @param array|null     $env     The environment variables or null to use the same environment as the current PHP process
     * @param mixed|null     $input   The input as stream resource, scalar or \Traversable, or null for no input
     * @param int|float|null $timeout The timeout in seconds or null to disable
     *
     * @throws LogicException When proc_open is not installed
     */
    public function __construct(
        array $command,
        string $cwd = null,
        array $env = null,
        $input = null,
        ?float $timeout = 60
    ) {
        $this->command = $command;
        $this->cwd     = $cwd;
        $this->env     = $env;
        $this->input   = $input;
        $this->timeout = $timeout;
    }

    public function run(OutputInterface $output): void
    {
        $process = new Process($this->command, $this->cwd, $this->env, $this->input, $this->timeout);

        // FIXME: we need an own output abstraction to allow buffering error and stdout differently for concurrent tasks.
        $errorOutput = ($output instanceof ConsoleOutput) ? $output->getErrorOutput() : $output;
        $process->mustRun(function ($type, $data) use ($output, $errorOutput) {
            switch ($type) {
                case Process::ERR:
                    $errorOutput->write($data);
                    return;
                case Process::OUT:
                    $output->write($data);
                    return;
            }
        });
    }
}