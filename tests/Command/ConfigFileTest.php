<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Command\ConfigFile;
use Rustine\Command\InvalidOptionException;

final class ConfigFileTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        putenv(ConfigFile::PATH_VARIABLE);

        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/{,.}*.yaml', \GLOB_BRACE) ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'unknown key' => ["reviewers: [a]\n", 'unknown key "reviewers"'];
        yield 'a secret key' => ["token: abc\n", 'unknown key "token"'];
        yield 'not a mapping' => ["- a\n- b\n", 'must be a mapping'];
        yield 'scalar' => ["hello\n", 'must be a mapping'];
        yield 'list given as string' => ["reviewer: alice\n", '"reviewer" must be a list of strings'];
        yield 'list of numbers' => ["labels: [1]\n", '"labels" must be a list of strings'];
        yield 'string given as number' => ["locale: 1\n", '"locale" must be a string'];
        yield 'negative integer' => ["closed_grace_days: -1\n", 'must be a non-negative integer'];
        yield 'integer given as string' => ["max_open_merge_requests: '3'\n", 'must be a non-negative integer'];
        yield 'boolean given as string' => ["include_dev: 'yes'\n", '"include_dev" must be a boolean'];
        yield 'broken yaml' => ["locale: [fr\n", 'Invalid configuration file'];
    }

    #[Test]
    public function readsEveryTypeOfSetting(): void
    {
        $dir = $this->dirWith(
            ConfigFile::DEFAULT_NAME,
            <<<'YAML'
                reviewer: [alice, bob]
                branch: release/1.x
                locale: fr
                closed_grace_days: 0
                include_dev: false
                YAML,
        );

        $config = ConfigFile::load(null, $dir);

        $this->assertSame($dir . '/' . ConfigFile::DEFAULT_NAME, $config->path);
        $this->assertSame(['alice', 'bob'], $config->list('reviewer'));
        $this->assertSame('fr', $config->string('locale'));
        $this->assertSame('release/1.x', $config->string('branch'));
        $this->assertSame(0, $config->int('closed_grace_days'));
        $this->assertFalse($config->bool('include_dev'));
        $this->assertNull($config->list('notify'));
        $this->assertNull($config->string('verify'));
        $this->assertNull($config->int('max_open_merge_requests'));
        $this->assertNull($config->bool('missing'));
    }

    #[Test]
    public function aMissingDefaultFileIsNotAnError(): void
    {
        $config = ConfigFile::load(null, $this->dirWith('other.yaml', ''));

        $this->assertNull($config->path);
        $this->assertNull($config->string('locale'));
    }

    #[Test]
    public function anEmptyFileOrNullValuesMeanNothingIsSet(): void
    {
        $dir = $this->dirWith(ConfigFile::DEFAULT_NAME, "reviewer:\nlocale: ~\n");

        $config = ConfigFile::load(null, $dir);

        $this->assertNull($config->list('reviewer'));
        $this->assertNull($config->string('locale'));
        $this->assertNull(ConfigFile::load($this->dirWith('empty.yaml', '') . '/empty.yaml', '.')->string('locale'));
    }

    #[Test]
    public function anExplicitPathIsUsedAsIs(): void
    {
        $dir = $this->dirWith('custom.yaml', "locale: fr\n");

        $this->assertSame('fr', ConfigFile::load($dir . '/custom.yaml', '/nonexistent')->string('locale'));
    }

    #[Test]
    public function theVariableGivesThePathWhenThereIsNoOption(): void
    {
        $dir = $this->dirWith('custom.yaml', "locale: fr\n");
        putenv(ConfigFile::PATH_VARIABLE . '=' . $dir . '/custom.yaml');

        $this->assertSame('fr', ConfigFile::load(null, '/nonexistent')->string('locale'));
        $option = $this->dirWith('o.yaml', "locale: en\n") . '/o.yaml';
        $this->assertSame('en', ConfigFile::load($option, '.')->string('locale'));
    }

    #[Test]
    public function anExplicitMissingFileIsAnError(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('does not exist');

        ConfigFile::load('/nonexistent/rustine.yaml', '.');
    }

    #[Test]
    #[DataProvider('invalidFiles')]
    public function rejectsAnInvalidFile(string $yaml, string $message): void
    {
        $dir = $this->dirWith(ConfigFile::DEFAULT_NAME, $yaml);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage($message);

        ConfigFile::load(null, $dir);
    }

    private function dirWith(string $name, string $content): string
    {
        $dir = sys_get_temp_dir() . '/rustine-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/' . $name, $content);
        $this->dirs[] = $dir;

        return $dir;
    }
}
