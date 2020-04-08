<?php

declare(strict_types=1);

namespace Phpcq\Plugin\Config;

use function is_int;

final class IntConfigOption extends AbstractConfigurationOption
{
    public function __construct(string $name, string $description, ?int $defaultValue, bool $required)
    {
        parent::__construct($name, $description, $defaultValue, $required);
    }

    public function getType() : string
    {
        return 'int';
    }

    public function validateValue($value) : void
    {
        if (is_int($value)) {
            return;
        }

        if ($value === null && !$this->isRequired()) {
            return;
        }

        $this->throwException($value);
    }
}