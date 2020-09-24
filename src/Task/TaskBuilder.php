<?php

declare(strict_types=1);

namespace Phpcq\Runner\Task;

use Phpcq\Runner\OutputTransformer\ConsoleOutputTransformerFactory;
use Phpcq\PluginApi\Version10\Exception\RuntimeException;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;
use Phpcq\RepositoryDefinition\Tool\ToolVersionInterface;
use Traversable;

final class TaskBuilder implements TaskBuilderInterface
{
    /**
     * @var ToolVersionInterface
     */
    private $tool;

    /**
     * @var string[]
     */
    private $command;

    /**
     * @var string|null
     */
    private $cwd = null;

    /**
     * @var string[]|null
     */
    private $env = null;

    /**
     * @var resource|string|Traversable|null
     */
    private $input = null;

    /**
     * @var int|float|null
     */
    private $timeout = null;

    /**
     * @var OutputTransformerFactoryInterface|null
     */
    private $transformerFactory;

    /** @var bool */
    private $parallel = true;

    /** @var int */
    private $cost = 1;

    /**
     * Create a new instance.
     *
     * @param string[] $command
     */
    public function __construct(ToolVersionInterface $tool, array $command)
    {
        $this->tool    = $tool;
        $this->command = $command;
    }

    /**
     * @return self
     */
    public function withWorkingDirectory(string $cwd): TaskBuilderInterface
    {
        $this->cwd = $cwd;

        return $this;
    }

    /**
     * @param string[] $env
     *
     * @return self
     */
    public function withEnv(array $env): TaskBuilderInterface
    {
        $this->env = $env;

        return $this;
    }

    /**
     * @param resource|string|Traversable $input The input as stream resource, scalar or \Traversable, or null for no
     *                                           input
     *
     * @return self
     */
    public function withInput($input): TaskBuilderInterface
    {
        $this->input = $input;

        return $this;
    }

    /**
     * @param int|float $timeout
     *
     * @return self
     */
    public function withTimeout($timeout): TaskBuilderInterface
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @return self
     */
    public function withOutputTransformer(OutputTransformerFactoryInterface $factory): TaskBuilderInterface
    {
        $this->transformerFactory = $factory;

        return $this;
    }

    public function forceSingleProcess(): TaskBuilderInterface
    {
        if (1 !== $this->cost) {
            throw new RuntimeException('Can not force task with cost > 1 to run as single process');
        }

        $this->parallel = false;

        return $this;
    }

    public function withCosts(int $cost): TaskBuilderInterface
    {
        if (!$this->parallel && $cost !== 1) {
            throw new RuntimeException('Can not set cost for single process forced task.');
        }
        if (0 > $cost) {
            throw new RuntimeException('Cost must be greater than zero.');
        }

        $this->cost = $cost;

        return $this;
    }

    public function build(): TaskInterface
    {
        $transformerFactory = $this->transformerFactory;
        if (null === $transformerFactory) {
            $transformerFactory = new ConsoleOutputTransformerFactory($this->tool->getName());
        }

        if ($this->parallel) {
            return new ParallelizableProcessTask(
                $this->tool,
                $this->command,
                $transformerFactory,
                $this->cost,
                $this->cwd,
                $this->env,
                $this->input,
                $this->timeout
            );
        }

        return new ProcessTask(
            $this->tool,
            $this->command,
            $transformerFactory,
            $this->cwd,
            $this->env,
            $this->input,
            $this->timeout
        );
    }
}
