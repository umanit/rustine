<?php

declare(strict_types=1);

namespace Rustine\Command;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The YAML file that holds the non-sensitive settings of a project, committed with it. It sits between the options
 * (which win) and the environment variables (which it overrides): option, then file, then variable, then default.
 *
 * Nothing sensitive is read from it (the token has no key), nor anything that depends on the machine that runs Rustine
 * (binaries, remote, GitLab address and project): those stay in the options and the environment of the job.
 */
final readonly class ConfigFile
{
    public const string DEFAULT_NAME = '.rustine.yaml';
    public const string PATH_VARIABLE = 'RUSTINE_CONFIG';
    public const string OPTION_DESCRIPTION = 'YAML file of the non-sensitive settings of the project (default:'
    . ' $RUSTINE_CONFIG, then .rustine.yaml in the project directory, if any)';

    private const string STRING = 'a string';
    private const string LIST = 'a list of strings';
    private const string INT = 'a non-negative integer';
    private const string BOOL = 'a boolean';

    /**
     * Every accepted key and the type of its value.
     */
    private const array KEYS = [
        'assignee'                => self::LIST,
        'reviewer'                => self::LIST,
        'notify'                  => self::LIST,
        'labels'                  => self::LIST,
        'severity_label_format'   => self::STRING,
        'locale'                  => self::STRING,
        'emoji'                   => self::BOOL,
        'branch'                  => self::STRING,
        'ecosystem'               => self::STRING,
        'group'                   => self::LIST,
        'max_open_merge_requests' => self::INT,
        'closed_grace_days'       => self::INT,
        'include_dev'             => self::BOOL,
        'verify'                  => self::STRING,
        'trivy_args'              => self::LIST,
        'git_author_name'         => self::STRING,
        'git_author_email'        => self::STRING,
    ];

    /**
     * @param array<string, int|bool|string|list<string>> $values the validated settings, without the null ones
     */
    private function __construct(
        public ?string $path,
        private array $values,
    ) {
    }

    public static function none(): self
    {
        return new self(null, []);
    }

    /**
     * @param string|null $explicitPath the `--config` option, else the variable {@see PATH_VARIABLE}; when neither is
     *                                  set, `.rustine.yaml` of the project directory, if there is one
     *
     * @throws InvalidOptionException when the file is asked for but missing, or is not valid
     */
    public static function load(?string $explicitPath, string $projectDir): self
    {
        $explicitPath = Environment::optionalString($explicitPath, self::PATH_VARIABLE);
        $path = $explicitPath ?? rtrim($projectDir, '/') . '/' . self::DEFAULT_NAME;

        if (!is_file($path)) {
            if (null !== $explicitPath) {
                throw new InvalidOptionException(sprintf('Configuration file "%s" does not exist.', $path));
            }

            return self::none();
        }

        $content = file_get_contents($path);
        if (false === $content) {
            throw new InvalidOptionException(sprintf('Cannot read the configuration file "%s".', $path));
        }

        try {
            $data = Yaml::parse($content);
        } catch (ParseException $parseException) {
            throw new InvalidOptionException(
                sprintf('Invalid configuration file "%s": %s', $path, $parseException->getMessage()),
                0,
                $parseException,
            );
        }

        return new self($path, self::validate($path, $data));
    }

    /**
     * @return array<string, int|bool|string|list<string>>
     */
    private static function validate(string $path, mixed $data): array
    {
        $fail = static fn(string $reason): InvalidOptionException => new InvalidOptionException(
            sprintf('Invalid configuration file "%s": %s', $path, $reason),
        );

        if (null === $data || [] === $data) {
            return [];
        }

        if (!\is_array($data) || array_is_list($data)) {
            throw $fail('the top level must be a mapping of settings.');
        }

        $values = [];

        foreach ($data as $key => $value) {
            $type = self::KEYS[$key] ?? throw $fail(
                sprintf('unknown key "%s" (expected: %s).', $key, implode(', ', array_keys(self::KEYS))),
            );

            if (null === $value) {
                continue;
            }

            $valid = match ($type) {
                self::STRING => \is_string($value),
                self::LIST => \is_array($value) && array_is_list($value) && self::onlyStrings($value),
                self::INT => \is_int($value) && $value >= 0,
                default => \is_bool($value),
            };

            if (!$valid) {
                throw $fail(sprintf('"%s" must be %s.', $key, $type));
            }

            /** @var int|bool|string|list<string> $value */
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param array<mixed> $items
     */
    private static function onlyStrings(array $items): bool
    {
        return array_all($items, static fn(mixed $item): bool => \is_string($item));
    }

    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @return list<string>|null null when the key is absent: an empty list is a choice, that overrides the variable
     */
    public function list(string $key): ?array
    {
        $value = $this->values[$key] ?? null;

        return \is_array($value) ? $value : null;
    }

    public function int(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    public function bool(string $key): ?bool
    {
        $value = $this->values[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }
}
