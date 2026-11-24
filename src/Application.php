<?php

declare(strict_types=1);

namespace Rustine;

use Rustine\Command\PlanCommand;
use Rustine\Command\ScanCommand;
use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    public const VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct('Rustine', self::VERSION);

        $this->addCommand(new ScanCommand());
        $this->addCommand(new PlanCommand());
    }
}
