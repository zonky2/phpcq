<?php

declare(strict_types=1);

namespace Phpcq\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ValidateCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('validate')->setDescription('Validate the phpcq installation');
        parent::configure();
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO: Change the autogenerated stub

        return 0;
    }
}
