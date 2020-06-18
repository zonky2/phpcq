<?php

declare(strict_types=1);

namespace Phpcq\Command;

use Phpcq\Config\BuildConfiguration;
use Phpcq\Config\ProjectConfiguration;
use Phpcq\Exception\RuntimeException;
use Phpcq\Plugin\Config\PhpcqConfigurationOptionsBuilder;
use Phpcq\Plugin\PluginRegistry;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;
use Phpcq\Report\Writer\CheckstyleReportWriter;
use Phpcq\Report\Buffer\ReportBuffer;
use Phpcq\Report\Report;
use Phpcq\Report\Writer\ConsoleWriter;
use Phpcq\Report\Writer\FileReportWriter;
use Phpcq\Report\Writer\GithubActionConsoleWriter;
use Phpcq\Report\Writer\ToolReportWriter;
use Phpcq\Task\TaskFactory;
use Phpcq\Task\Tasklist;
use Phpcq\Task\TaskScheduler;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

use function assert;
use function getcwd;
use function in_array;
use function is_string;

final class RunCommand extends AbstractCommand
{
    use InstalledRepositoryLoadingCommandTrait;

    protected function configure(): void
    {
        $this->setName('run')->setDescription('Run configured build tasks');

        $this->addArgument(
            'chain',
            InputArgument::OPTIONAL,
            'Define the tool chain. Using default chain if none passed',
            'default'
        );

        $this->addArgument(
            'tool',
            InputArgument::OPTIONAL,
            'Define a specific tool which should be run'
        );
        $this->addOption(
            'fast-finish',
            'ff',
            InputOption::VALUE_NONE,
            'Do not keep going and execute all tasks but break on first error',
        );

        $this->addOption(
            'report',
            'r',
            InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
            'Set the report formats which should be created. Available options are <info>file-report</info>, '
            . '<info>tool-report</info> and <info>checkstyle</info>".',
            ['file-report']
        );

        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Set a specific console output format. Available options are <info>default</info> and '
            . '<info>github-action</info>',
            'default'
        );

        $this->addOption(
            'threshold',
            null,
            InputOption::VALUE_REQUIRED,
            'Set the minimum threshold for diagnostics to be reported, Available options are (in ascending order): "' .
            implode('", "', [
                ToolReportInterface::SEVERITY_INFO,
                ToolReportInterface::SEVERITY_NOTICE,
                ToolReportInterface::SEVERITY_WARNING,
                ToolReportInterface::SEVERITY_ERROR,
            ]) . '"',
            ToolReportInterface::SEVERITY_INFO
        );

        $numCores = $this->getCores();
        $this->addOption(
            'threads',
            'j',
            InputOption::VALUE_REQUIRED,
            sprintf('Set the amount of threads to run in parallel. <info>1</info>-<info>%1$d</info>', $numCores),
            $numCores
        );

        parent::configure();
    }

    protected function doExecute(): int
    {
        $fileSystem = new Filesystem();
        $fileSystem->remove(getcwd() . '/' . $this->config['artifact']);
        $fileSystem->mkdir(getcwd() . '/' . $this->config['artifact']);

        $projectConfig = new ProjectConfiguration(getcwd(), $this->config['directories'], $this->config['artifact']);
        $tempDirectory = sys_get_temp_dir();
        $taskList = new Tasklist();
        /** @psalm-suppress PossiblyInvalidArgument */
        $taskFactory = new TaskFactory(
            $this->phpcqPath,
            $installed = $this->getInstalledRepository(true),
            ...$this->findPhpCli()
        );
        $reportBuffer = new ReportBuffer();
        $report = new Report($reportBuffer, $installed, $tempDirectory);
        // Create build configuration
        $buildConfig = new BuildConfiguration($projectConfig, $taskFactory, $tempDirectory);
        // Load bootstraps
        $plugins = PluginRegistry::buildFromInstalledRepository($installed);

        $chain = $this->input->getArgument('chain');
        assert(is_string($chain));

        if (!isset($this->config['chains'][$chain])) {
            throw new RuntimeException(sprintf('Unknown chain "%s"', $chain));
        }

        if ($toolName = $this->input->getArgument('tool')) {
            assert(is_string($toolName));
            $this->handlePlugin($plugins, $chain, $toolName, $buildConfig, $taskList);
        } else {
            foreach (array_keys($this->config['chains'][$chain]) as $toolName) {
                $this->handlePlugin($plugins, $chain, $toolName, $buildConfig, $taskList);
            }
        }

        $consoleOutput = $this->getWrappedOutput();
        $exitCode = $this->runTasks($taskList, $report, $consoleOutput);

        $reportBuffer->complete($exitCode === 0 ? Report::STATUS_PASSED : Report::STATUS_FAILED);
        $this->writeReports($reportBuffer, $projectConfig);

        $consoleOutput->writeln('Finished.', OutputInterface::VERBOSITY_VERBOSE, OutputInterface::CHANNEL_STDERR);
        return $exitCode;
    }

    private function runTasks(Tasklist $taskList, Report $report, OutputInterface $output): int
    {
        $fastFinish = (bool) $this->input->getOption('fast-finish');
        $threads    = (int) $this->input->getOption('threads');
        $scheduler  = new TaskScheduler($taskList, $threads, $report, $output, $fastFinish);

        return $scheduler->run() ? 0 : 1;
    }

    /** @psalm-return array{0: string, 1: array} */
    private function findPhpCli(): array
    {
        $finder     = new PhpExecutableFinder();
        $executable = $finder->find();

        if (!is_string($executable)) {
            throw new RuntimeException('PHP executable not found');
        }

        return [$executable, $finder->findArguments()];
    }

    /**
     * @param PluginRegistry     $plugins
     * @param string             $chain
     * @param string             $toolName
     * @param BuildConfiguration $buildConfig
     * @param Tasklist           $taskList
     *
     * @return void
     */
    protected function handlePlugin(
        PluginRegistry $plugins,
        string $chain,
        string $toolName,
        BuildConfiguration $buildConfig,
        Tasklist $taskList
    ): void {
        $plugin = $plugins->getPluginByName($toolName);
        $name   = $plugin->getName();

        // Initialize phar files
        if ($plugin instanceof ConfigurationPluginInterface) {
            $configOptionsBuilder = new PhpcqConfigurationOptionsBuilder();
            $configuration       = $this->config['chains'][$chain][$name]
                ?? ($this->config['tool-config'][$name] ?: []);

            $plugin->describeOptions($configOptionsBuilder);
            $options = $configOptionsBuilder->getOptions();
            $options->validateConfig($configuration);

            foreach ($plugin->processConfig($configuration, $buildConfig) as $task) {
                $taskList->add($task);
            }
        }
    }

    private function writeReports(ReportBuffer $report, ProjectConfiguration $projectConfig): void
    {
        /** @psalm-suppress PossiblyInvalidCast - We know it is a string */
        $threshold  = (string) $this->input->getOption('threshold');

        if ($this->input->getOption('output') === 'github-action') {
            GithubActionConsoleWriter::writeReport($this->output, $report);
        } else {
            ConsoleWriter::writeReport(
                $this->output,
                new SymfonyStyle($this->input, $this->output),
                $report,
                $threshold,
                $this->getWrapWidth()
            );
        }

        $reports = (array) $this->input->getOption('report');
        $targetPath = getcwd() . '/' . $projectConfig->getArtifactOutputPath();

        if (in_array('tool-report', $reports, true)) {
            ToolReportWriter::writeReport($targetPath, $report, $threshold);
        }

        if (in_array('file-report', $reports, true)) {
            FileReportWriter::writeReport($targetPath, $report, $threshold);
        }

        if (in_array('checkstyle', $reports, true)) {
            CheckstyleReportWriter::writeReport($targetPath, $report, $threshold);
        }

        // Clean up attachments.
        $fileSystem = new Filesystem();
        foreach ($report->getToolReports() as $toolReport) {
            foreach ($toolReport->getAttachments() as $attachment) {
                $fileSystem->remove($attachment->getAbsolutePath());
            }
        }
    }

    private function getCores(): int
    {
        if ('/' === DIRECTORY_SEPARATOR) {
            $process = new Process(['nproc']);
            try {
                $process->mustRun();
                return (int) trim($process->getOutput());
            } catch (Throwable $ignored) {
                // Fallback to grep.
                $process = new Process(['grep', '-c', '^processor', '/proc/cpuinfo']);
                try {
                    $process->mustRun();
                    return (int) trim($process->getOutput());
                } catch (Throwable $ignored) {
                    // Ignore exception and return the 1 default below.
                }
            }
        }
        // Unsupported OS.
        return 1;
    }
}
