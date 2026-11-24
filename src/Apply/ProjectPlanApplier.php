<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\GroupPlan;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\TrivyReportParser;
use Rustine\Trivy\TrivyScanner;

/**
 * Applies a plan to a real project: picks the strategy of the ecosystem, checks that the environment provides the
 * tools it needs (Rustine installs none), then runs {@see FixApplier}.
 */
final readonly class ProjectPlanApplier implements PlanApplier
{
    /**
     * @param list<string> $trivyArgs       extra Trivy arguments for the re-scans
     * @param string       $composerCommand command that runs Composer (see {@see ComposerStrategy::fromCommandLine()})
     * @param string       $yarnCommand     command that runs Yarn (see {@see YarnRuntime})
     * @param string       $nodeCommand     command that runs Node (see {@see YarnRuntime})
     */
    public function __construct(
        private CommandRunner $runner,
        private string $projectDir,
        private string $trivyBin = 'trivy',
        private array $trivyArgs = [],
        private string $composerCommand = 'composer',
        private string $yarnCommand = 'yarn',
        private string $nodeCommand = 'node',
    ) {
    }

    /**
     * Strategy of the ecosystem. Runs nothing: the Yarn flavor comes from the format of `yarn.lock`.
     *
     * @throws UnsupportedProjectException when a Yarn project has no `yarn.lock`
     * @throws \InvalidArgumentException  when a tool command is empty
     */
    public function strategy(Ecosystem $ecosystem): FixStrategy
    {
        if (Ecosystem::Composer === $ecosystem) {
            return ComposerStrategy::fromCommandLine($this->composerCommand);
        }

        $runtime = YarnRuntime::fromCommandLines($this->yarnCommand, $this->nodeCommand);

        return YarnFlavor::Berry === $this->yarnLockfileFlavor() ? new YarnBerryStrategy($runtime) : new YarnClassicStrategy($runtime);
    }

    /**
     * Checks the tools of the environment and reads their versions.
     *
     * @throws ToolingException            when a tool is missing or does not match the project
     * @throws UnsupportedProjectException when a Yarn project has no `yarn.lock`
     * @throws \InvalidArgumentException  when a tool command is empty
     */
    public function tools(Ecosystem $ecosystem): ToolVersions
    {
        $probe = new ToolProbe($this->runner, $this->projectDir);

        return Ecosystem::Composer === $ecosystem
            ? $probe->composer(ComposerStrategy::fromCommandLine($this->composerCommand)->command())
            : $probe->yarn($this->yarnLockfileFlavor(), YarnRuntime::fromCommandLines($this->yarnCommand, $this->nodeCommand));
    }

    public function apply(GroupPlan $plan, TrivyReport $report): AppliedPlan
    {
        $strategy = $this->strategy($plan->ecosystem);
        $tools = $this->tools($plan->ecosystem);

        $applier = new FixApplier(
            $this->runner,
            new WorkingTree($this->runner, $this->projectDir),
            new TrivyRescanner(new TrivyScanner($this->trivyBin), new TrivyReportParser(), $this->projectDir, $this->trivyArgs),
            $strategy,
            new PackageJsonEditor(),
            $this->projectDir,
        );

        return new AppliedPlan($applier->apply($plan, $report), $tools);
    }

    /**
     * @throws UnsupportedProjectException
     */
    private function yarnLockfileFlavor(): YarnFlavor
    {
        $lockfile = $this->projectDir . '/yarn.lock';
        $content = is_file($lockfile) ? file_get_contents($lockfile) : false;
        if (false === $content) {
            throw new UnsupportedProjectException(sprintf('No readable yarn.lock in "%s".', $this->projectDir));
        }

        return YarnFlavor::ofLockfile($content);
    }
}
