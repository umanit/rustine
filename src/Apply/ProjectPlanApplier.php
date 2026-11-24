<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Pinning\PinningDetector;
use Rustine\Pinning\PinningException;
use Rustine\Pinning\PinningSource;
use Rustine\Pinning\YarnFlavor;
use Rustine\Plan\GroupPlan;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\TrivyReportParser;
use Rustine\Trivy\TrivyScanner;

/**
 * Applies a plan to a real project: picks the strategy of the ecosystem from the pinned tools, then runs
 * {@see FixApplier}.
 */
final readonly class ProjectPlanApplier implements PlanApplier
{
    /**
     * @param list<string> $trivyArgs extra Trivy arguments for the re-scans
     */
    public function __construct(
        private CommandRunner $runner,
        private string $projectDir,
        private string $trivyBin = 'trivy',
        private array $trivyArgs = [],
    ) {
    }

    /**
     * @throws PinningException when the tools the ecosystem needs are not pinned by the project
     */
    public function setup(Ecosystem $ecosystem): ApplierSetup
    {
        if (Ecosystem::Composer === $ecosystem) {
            // PHP and Composer come from the CI image unless the project pins them with mise.
            return new ApplierSetup(new ComposerStrategy(), $this->optionalMisePinning());
        }

        $pinning = (new PinningDetector())->detect($this->projectDir);
        $flavor = $pinning->yarnFlavor();
        if (null === $flavor) {
            throw new PinningException('Yarn is not pinned by the project: cannot choose a strategy.');
        }

        return new ApplierSetup(YarnFlavor::Berry === $flavor ? new YarnBerryStrategy() : new YarnClassicStrategy(), $pinning);
    }

    public function apply(GroupPlan $plan, TrivyReport $report): AppliedPlan
    {
        $setup = $this->setup($plan->ecosystem);

        $applier = new FixApplier(
            $this->runner,
            new ToolEnvironment($setup->pinning, $this->projectDir),
            new WorkingTree($this->runner, $this->projectDir),
            new TrivyRescanner(new TrivyScanner($this->trivyBin), new TrivyReportParser(), $this->projectDir, $this->trivyArgs),
            $setup->strategy,
            new PackageJsonEditor(),
            $this->projectDir,
        );

        return new AppliedPlan($applier->apply($plan, $report), $setup->pinning);
    }

    /**
     * A `mise.toml` is honoured for Composer projects (PHP may be pinned); the `volta` key never concerns them.
     */
    private function optionalMisePinning(): ?\Rustine\Pinning\ToolPinning
    {
        try {
            $pinning = (new PinningDetector())->detect($this->projectDir);
        } catch (PinningException) {
            return null;
        }

        return PinningSource::Mise === $pinning->source ? $pinning : null;
    }
}
