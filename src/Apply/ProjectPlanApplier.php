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
     * @param VerifyMode   $verify          when Trivy confirms the fixes
     */
    public function __construct(
        private CommandRunner $runner,
        private string $projectDir,
        private string $trivyBin = 'trivy',
        private array $trivyArgs = [],
        private string $composerCommand = 'composer',
        private string $yarnCommand = 'yarn',
        private string $nodeCommand = 'node',
        private VerifyMode $verify = VerifyMode::Final,
    ) {
    }

    /**
     * Strategy of the ecosystem. Runs nothing: the Yarn flavor comes from the format of `yarn.lock`.
     *
     * @param ToolVersions|null $tools what {@see tools()} found; without it, the commands are the conservative ones
     *                                 (Yarn Berry runs the link step after every attempt)
     *
     * @throws UnsupportedProjectException when a Yarn project has no `yarn.lock`
     * @throws \InvalidArgumentException  when a tool command is empty
     */
    public function strategy(Ecosystem $ecosystem, ?ToolVersions $tools = null): FixStrategy
    {
        if (Ecosystem::Composer === $ecosystem) {
            return ComposerStrategy::fromCommandLine($this->composerCommand);
        }

        $runtime = YarnRuntime::fromCommandLines($this->yarnCommand, $this->nodeCommand);

        return YarnFlavor::Berry === $this->yarnLockfileFlavor()
            ? new YarnBerryStrategy($runtime, lockfileOnly: YarnBerryStrategy::supportsLockfileOnly($tools?->version('yarn')))
            : new YarnClassicStrategy($runtime);
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

        if (Ecosystem::Composer === $ecosystem) {
            $versions = $probe->composer(ComposerStrategy::fromCommandLine($this->composerCommand)->command());
            $this->assertFlexIsLoadedWhenRequired();

            return $versions;
        }

        return $probe->yarn($this->yarnLockfileFlavor(), YarnRuntime::fromCommandLines($this->yarnCommand, $this->nodeCommand));
    }

    public function apply(GroupPlan $plan, TrivyReport $report): AppliedPlan
    {
        $tools = $this->tools($plan->ecosystem);
        $strategy = $this->strategy($plan->ecosystem, $tools);

        $applier = new FixApplier(
            $this->runner,
            new WorkingTree($this->runner, $this->projectDir),
            new TrivyRescanner(new TrivyScanner($this->trivyBin), new TrivyReportParser(), $this->projectDir, $this->trivyArgs),
            $strategy,
            new PackageJsonEditor(),
            $this->projectDir,
            $this->verify,
        );

        return new AppliedPlan($applier->apply($plan, $report), $tools);
    }

    /**
     * `extra.symfony.require` is only enforced by Symfony Flex, which Composer loads from `vendor/`: without it, an update
     * silently moves the `symfony/*` components of the project to another minor.
     *
     * @throws ToolingException
     */
    private function assertFlexIsLoadedWhenRequired(): void
    {
        $manifest = ComposerManifest::read($this->projectDir . '/composer.json');
        $require = $manifest->symfonyRequire();
        if (null === $require) {
            return;
        }

        $vendor = $manifest->vendorDir();
        $flex = (str_starts_with($vendor, '/') ? $vendor : $this->projectDir . '/' . $vendor) . '/symfony/flex';
        if (!is_dir($flex)) {
            throw new ToolingException(sprintf(
                'composer.json sets extra.symfony.require to "%s", which only Symfony Flex enforces, and Flex is not installed '
                . '(no %s/symfony/flex): the update would move Symfony components to another minor version. Rustine installs no '
                . 'tool: run `composer install --no-scripts` before it.',
                $require,
                $vendor,
            ));
        }
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
