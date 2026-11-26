<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\VerifyMode;
use Rustine\Command\ConfigFile;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;

/**
 * The JSON Schema offered to the IDEs must describe exactly the keys that {@see ConfigFile} accepts.
 */
final class ConfigSchemaTest extends TestCase
{
    private const string SCHEMA = __DIR__ . '/../../schema/rustine.schema.json';

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function properties(): array
    {
        $properties = self::schema()['properties'];
        self::assertIsArray($properties);

        /** @var array<string, array<string, mixed>> $properties */
        return $properties;
    }

    /**
     * @return list<int|string>
     */
    private static function sortedChoices(mixed $choices): array
    {
        self::assertIsArray($choices);

        /** @var list<int|string> $choices */
        sort($choices);

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private static function keys(): array
    {
        $keys = new \ReflectionClassConstant(ConfigFile::class, 'KEYS')->getValue();
        self::assertIsArray($keys);

        /** @var array<string, string> $keys */
        return $keys;
    }

    #[Test]
    public function describesExactlyTheAcceptedKeys(): void
    {
        $properties = self::schema()['properties'];
        $this->assertIsArray($properties);

        $expected = array_keys(self::keys());
        $actual = array_keys($properties);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertFalse(self::schema()['additionalProperties']);
    }

    #[Test]
    public function describesTheTypeOfEachKey(): void
    {
        $properties = self::schema()['properties'];
        $this->assertIsArray($properties);

        foreach (self::keys() as $key => $type) {
            $definition = $properties[$key];
            $this->assertIsArray($definition);
            $this->assertNotEmpty($definition['description'] ?? null, sprintf('"%s" has no description.', $key));

            switch ($type) {
                case 'a list of strings':
                    $this->assertSame('array', $definition['type'], $key);
                    $items = $definition['items'];
                    $this->assertIsArray($items);
                    $this->assertSame('string', $items['type'], $key);

                    break;
                case 'a non-negative integer':
                    $this->assertSame('integer', $definition['type'], $key);
                    $this->assertSame(0, $definition['minimum'], $key);

                    break;
                case 'a boolean':
                    $this->assertSame('boolean', $definition['type'], $key);

                    break;
                default:
                    $this->assertSame('string', $definition['type'], $key);
            }
        }
    }

    #[Test]
    public function listsTheSameChoicesAsTheEnums(): void
    {
        $properties = self::properties();
        $group = $properties['group']['items'];
        $this->assertIsArray($group);

        $choices = [
            'ecosystem' => [$properties['ecosystem']['enum'], Ecosystem::cases()],
            'group'     => [$group['enum'], SeverityGroup::cases()],
            'verify'    => [$properties['verify']['enum'], VerifyMode::cases()],
        ];

        foreach ($choices as $key => [$listed, $cases]) {
            $expected = array_map(static fn(\BackedEnum $case): string => $case->value, $cases);
            sort($expected);

            $this->assertSame($expected, self::sortedChoices($listed), $key);
        }
    }
}
