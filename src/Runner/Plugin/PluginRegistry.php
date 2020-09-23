<?php

namespace Phpcq\Runner\Plugin;

use Generator;
use IteratorAggregate;
use Phpcq\Exception\RuntimeException;
use Phpcq\PluginApi\Version10\PluginInterface;
use Phpcq\RepositoryDefinition\Plugin\PhpFilePluginVersionInterface;
use Phpcq\Runner\Repository\InstalledRepository;

use function assert;
use function get_class;

/**
 * @psalm-import-type TInstalledRepository from \Phpcq\Runner\Repository\InstalledRepositoryLoader
 */
final class PluginRegistry implements IteratorAggregate
{
    /** @var array<string, PluginInterface> */
    private $plugins = [];

    public static function buildFromInstalledRepository(InstalledRepository $repository, string $phpcqPath): self
    {
        $instance = new self();

        foreach ($repository->iteratePlugins() as $plugin) {
            $pluginVersion = $plugin->getPluginVersion();
            assert($pluginVersion instanceof PhpFilePluginVersionInterface);
            $instance->loadPluginFile($phpcqPath . '/' . $pluginVersion->getFilePath());
        }

        return $instance;
    }

    /** @SuppressWarnings(PHPMD.UnusedPrivateMethod) */
    private function loadPluginFile(string $filePath): void
    {
        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var PluginInterface
         */
        $plugin = require_once $filePath;
        if (!$plugin instanceof PluginInterface) {
            throw new RuntimeException('Not a valid plugin: ' . get_class($plugin));
        }

        /** @var string */
        $name = $plugin->getName();
        if (isset($this->plugins[$name])) {
            throw new RuntimeException('Plugin already registered: ' . $name);
        }
        $this->plugins[$name] = $plugin;
    }

    public function getPluginByName(string $name): PluginInterface
    {
        if (!isset($this->plugins[$name])) {
            throw new RuntimeException('Plugin not registered: ' . $name);
        }
        return $this->plugins[$name];
    }

    /**
     * @return PluginInterface[]|Generator|iterable
     *
     * @psalm-return Generator<string, PluginInterface, mixed, void>
     */
    public function getIterator(): iterable
    {
        yield from $this->plugins;
    }
}