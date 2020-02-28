<?php

namespace Phpcq\Repository;

/**
 * Describes a build tool.
 */
interface ToolInformationInterface
{
    /**
     * Obtain the name of this tool.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Obtain the version string of this tool.
     *
     * @return string
     */
    public function getVersion(): string;

    /**
     * Obtain the phar download URL.
     *
     * @return string
     */
    public function getPharUrl(): string;

    /**
     * Obtain the bootstrap information.
     *
     * @return BootstrapInterface
     */
    public function getBootstrap(): BootstrapInterface;
}