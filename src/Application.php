<?php

declare(strict_types=1);

namespace Rustine;

use Rustine\Command\ApplyCommand;
use Rustine\Command\PlanCommand;
use Rustine\Command\RunCommand;
use Rustine\Command\ScanCommand;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Application extends BaseApplication
{
    public const VERSION = '0.1.0-dev';

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        parent::__construct('Rustine', self::VERSION);

        $this->addCommand(new ScanCommand());
        $this->addCommand(new PlanCommand());
        $this->addCommand(new ApplyCommand());
        $this->addCommand(new RunCommand($httpClient));
    }
}
