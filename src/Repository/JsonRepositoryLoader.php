<?php

declare(strict_types=1);

namespace Phpcq\Repository;

use Phpcq\Exception\RuntimeException;
use Phpcq\FileDownloader;
use Phpcq\Platform\PlatformRequirementCheckerInterface;

/**
 * Load a json file.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class JsonRepositoryLoader
{
    /**
     * @var FileDownloader
     */
    private $downloader;

    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var bool
     */
    private $bypassCache;

    /**
     * @var PlatformRequirementCheckerInterface|null
     */
    private $requirementChecker;

    /**
     * Create a new instance.
     *
     * @param PlatformRequirementCheckerInterface|null $requirementChecker
     * @param FileDownloader                           $downloader
     * @param bool                                     $bypassCache
     */
    public function __construct(?PlatformRequirementCheckerInterface $requirementChecker, FileDownloader $downloader, bool $bypassCache = false)
    {
        $this->requirementChecker = $requirementChecker;
        $this->downloader = $downloader;
        $this->bypassCache = $bypassCache;
    }

    public function loadFile(string $filePath, ?array $hash = null, ?string $baseDir = null): RepositoryInterface
    {
        $this->repository = new Repository($this->requirementChecker);
        $baseDir          = $baseDir ?? dirname($filePath);
        $data             = $this->downloader->downloadJsonFile($filePath, $baseDir, $this->bypassCache, $hash);
        $bootstrapLookup  = $data['bootstraps'] ?? [];
        foreach ($data['phars'] as $toolName => $versions) {
            if (!is_array($versions)) {
                throw new RuntimeException('Invalid version list');
            }
            // Include? - load it!
            if (['url', 'checksum'] === array_keys($versions)) {
                /** @psalm-suppress PossiblyInvalidArgument */
                $this->loadFile($versions['url'], $versions['checksum'], $baseDir);
                continue;
            }

            /** @psalm-suppress InvalidArgument */
            $this->handleVersionList($toolName, $versions, $bootstrapLookup, $baseDir);
        }

        return $this->repository;
    }

    private function handleVersionList(
        string $toolName,
        array $versionList,
        array $bootstrapLookup,
        string $baseDir
    ) : void {
        foreach ($versionList as $version) {
            if (is_string($bootstrap = $version['bootstrap'])) {
                if (!isset($bootstrapLookup[$bootstrap])) {
                    throw new RuntimeException('Bootstrap not in lookup map: ' . $bootstrap);
                }
                $version['bootstrap'] = $bootstrapLookup[$bootstrap];
            }
            $this->repository->addVersion(new ToolInformation(
                $toolName,
                $version['version'],
                $version['phar-url'],
                $version['requirements'],
                $this->makeBootstrap($version['bootstrap'], $bootstrapLookup, $baseDir),
                isset($version['hash']) ? new ToolHash($version['hash']['type'], $version['hash']['value']) : null,
                $version['signature'] ?? null
            ));
        }
    }

    /** @param string|array $bootstrap */
    private function makeBootstrap($bootstrap, array $bootstrapLookup, string $baseDir): BootstrapInterface
    {
        if (is_string($bootstrap)) {
            if (!isset($bootstrapLookup[$bootstrap])) {
                throw new RuntimeException('Bootstrap not in lookup map: ' . $bootstrap);
            }
            $bootstrap = $bootstrapLookup[$bootstrap];
        }
        if (!is_array($bootstrap)) {
            throw new RuntimeException('Invalid bootstrap definition: ' . json_encode($bootstrap));
        }

        switch ($bootstrap['type']) {
            case 'inline':
                // Static bootstrapper.
                return new InlineBootstrap($bootstrap['plugin-version'], $bootstrap['code']);
            case 'file':
                return new RemoteBootstrap($bootstrap['plugin-version'], $bootstrap['url'], $this->downloader, $baseDir);
        }
        throw new RuntimeException('Invalid bootstrap definition: ' . json_encode($bootstrap));
    }
}
