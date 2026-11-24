<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ProcessCommandRunner;

final class ProcessCommandRunnerTest extends TestCase
{
    private const VARIABLES = ['RUSTINE_GITLAB_TOKEN' => 'secret-1', 'GITLAB_TOKEN' => 'secret-2', 'RUSTINE_TEST_INHERITED' => 'kept'];

    protected function setUp(): void
    {
        // Symfony Process inherits the variables known to both getenv() and $_SERVER, as for a real CI variable.
        foreach (self::VARIABLES as $name => $value) {
            putenv($name . '=' . $value);
            $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::VARIABLES) as $name) {
            putenv($name);
            unset($_SERVER[$name]);
        }
    }

    #[Test]
    public function childProcessesDoNotInheritTheGitLabToken(): void
    {
        $result = (new ProcessCommandRunner())->run(
            ['sh', '-c', 'echo "${RUSTINE_GITLAB_TOKEN:-unset} ${GITLAB_TOKEN:-unset} ${RUSTINE_TEST_INHERITED:-unset} ${EXTRA:-unset}"'],
            sys_get_temp_dir(),
            ['EXTRA' => 'given'],
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame("unset unset kept given\n", $result->output);
    }
}
