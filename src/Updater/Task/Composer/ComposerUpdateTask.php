<?php

declare(strict_types=1);

namespace Phpcq\Runner\Updater\Task\Composer;

use Phpcq\Runner\Updater\UpdateContext;

use function sprintf;

final class ComposerUpdateTask extends AbstractComposerTask
{
    public function getPurposeDescription(): string
    {
        return sprintf('Will update composer dependencies of plugin %s', $this->getPluginName());
    }

    public function getExecutionDescription(): string
    {
        return 'Updating composer dependencies of plugin ' . $this->getPluginName();
    }

    public function execute(UpdateContext $context): void
    {
        $context->composer->update($this->pluginVersion);
        $this->updateComposerLock($context);
    }
}
