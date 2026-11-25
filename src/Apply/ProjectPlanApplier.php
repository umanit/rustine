<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\GroupPlan;
use Rustine\Progress\NullProgress;
use Rustine\Progress\Progress;
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
     * @param Progress     $progress        told what the run is doing
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
        private Progress $progress = new NullProgress(),
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
            ? new YarnBerryStrategy(
                $runtime,
                lockfileOnly: YarnBerryStrategy::supportsLockfileOnly(
                    $tools?->version('yarn'),
                ),
            )
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
            $composer = ComposerStrategy::fromCommandLine($this->composerCommand)->command();
            $versions = $probe->composer($composer);
            $this->assertFlexIsLoadedWhenRequired($composer);

            return $versions;
        }

        return $probe->yarn(
            $this->yarnLockfileFlavor(),
            YarnRuntime::fromCommandLines($this->yarnCommand, $this->nodeCommand),
        );
    }

    public function apply(GroupPlan $plan, TrivyReport $report): AppliedPlan
    {
        $this->progress->step('Checking the tools of the environment');
        $tools = $this->tools($plan->ecosystem);
        $strategy = $this->strategy($plan->ecosystem, $tools);

        $applier = new FixApplier(
            $this->runner,
            new WorkingTree($this->runner, $this->projectDir),
            new TrivyRescanner(
                new TrivyScanner($this->trivyBin),
                new TrivyReportParser(),
                $this->projectDir,
                $this->trivyArgs,
            ),
            $strategy,
            new PackageJsonEditor(),
            $this->projectDir,
            $this->verify,
            $this->progress,
        );

        return new AppliedPlan($applier->apply($plan, $report), $tools);
    }

    /**
     * `extra.symfony.require` is only enforced by Symfony Flex: without it, an update silently moves the `symfony/*`
     * components of the project to another minor. Flex is loaded from the `vendor/` of the project, or from the global
     * Composer home, which is preferable as the plugins of the project are then not loaded.
     *
     * @param non-empty-list<string> $composer
     *
     * @throws ToolingException
     */
    private function assertFlexIsLoadedWhenRequired(array $composer): void
    {
        $manifest = ComposerManifest::read($this->projectDir . '/composer.json');
        $require = $manifest->symfonyRequire();
        if (null === $require) {
            return;
        }

        $vendor = $manifest->vendorDir();
        if (is_dir((str_starts_with($vendor, '/') ? $vendor : $this->projectDir . '/' . $vendor) . '/symfony/flex')) {
            return;
        }

        // Exits with 0 only when Flex is installed in the global Composer home.
        if (
            $this->runner->run(
                [...$composer, 'global', 'show', 'symfony/flex', '--no-ansi'],
                $this->projectDir,
                ['COMPOSER_NO_INTERACTION' => '1'],
            )->isSuccessful()
        ) {
            return;
        }

        throw new ToolingException(
            sprintf(
                'composer.json sets extra.symfony.require to "%s", which only Symfony Flex enforces, and Flex'
                . ' is installed neither in %s/ nor globally: the update would move Symfony components to another'
                . ' minor version. Rustine installs no tool: have the environment provide it, preferably globally so'
                . ' that the plugins of the project are not loaded (`composer global config allow-plugins.symfony/flex'
                . ' true && composer global require symfony/flex --no-scripts`), or run `composer install --no-scripts`'
                . ' first.',
                $require,
                $vendor,
            ),
        );
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
