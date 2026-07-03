<?php

namespace App\Modules;

use InvalidArgumentException;
use JsonException;

final class ModuleManifest
{
    private const REQUIRED = [
        'schema_version',
        'name',
        'title',
        'vendor',
        'version',
        'type',
        'core_version',
        'namespace',
        'admin_prefix',
    ];

    private const SLUG_FIELDS = [
        'name',
        'type',
        'admin_prefix',
    ];

    private const PATH_FIELDS = [
        'entry' => null,
        'controllers' => 'src/Controllers',
        'views' => 'resources/views',
        'assets' => 'assets',
        'migrations' => 'database/migrations',
        'seeders' => 'database/seeders',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly string $path,
        private readonly array $data,
    ) {}

    public static function fromFile(string $path): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('module.json must decode to an object');
        }

        foreach (self::REQUIRED as $field) {
            if (! array_key_exists($field, $decoded) || self::isEmpty($decoded[$field])) {
                throw new InvalidArgumentException("module.json missing required field: {$field}");
            }
        }

        foreach (self::SLUG_FIELDS as $field) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $decoded[$field])) {
                throw new InvalidArgumentException("module.json invalid field: {$field}");
            }
        }

        $basePath = self::normalizePath(dirname($path));
        $decoded['path'] = $basePath;

        foreach (self::PATH_FIELDS as $field => $defaultPath) {
            if (! array_key_exists($field, $decoded) && ! in_array($field, ['controllers', 'views', 'assets'], true)) {
                continue;
            }

            $decoded[$field] = self::resolveModulePath($basePath, $field, (string) ($decoded[$field] ?? $defaultPath));
        }

        $decoded['menus'] = is_array($decoded['menus'] ?? null) ? $decoded['menus'] : [];
        $decoded['permissions'] = is_array($decoded['permissions'] ?? null) ? $decoded['permissions'] : [];

        return new self($basePath, $decoded);
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function title(): string
    {
        return (string) $this->data['title'];
    }

    public function vendor(): string
    {
        return (string) $this->data['vendor'];
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function type(): string
    {
        return (string) $this->data['type'];
    }

    public function namespace(): string
    {
        return (string) $this->data['namespace'];
    }

    public function adminPrefix(): string
    {
        return (string) $this->data['admin_prefix'];
    }

    public function controllersPath(): string
    {
        return (string) $this->data['controllers'];
    }

    public function viewsPath(): string
    {
        return (string) $this->data['views'];
    }

    public function assetsPath(): string
    {
        return (string) $this->data['assets'];
    }

    public function migrationsPath(): ?string
    {
        return isset($this->data['migrations']) ? (string) $this->data['migrations'] : null;
    }

    public function seedersPath(): ?string
    {
        return isset($this->data['seeders']) ? (string) $this->data['seeders'] : null;
    }

    public function entryPath(): ?string
    {
        return isset($this->data['entry']) ? (string) $this->data['entry'] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function menus(): array
    {
        /** @var array<int, array<string, mixed>> $menus */
        $menus = $this->data['menus'];

        return $menus;
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        /** @var array<int, string> $permissions */
        $permissions = $this->data['permissions'];

        return $permissions;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function resolveModulePath(string $basePath, string $field, string $path): string
    {
        $rootPath = self::normalizePath($basePath);

        if ($path === '') {
            return $rootPath;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            $resolvedPath = self::normalizePath($path);
        } else {
            $resolvedPath = self::normalizePath($rootPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        }

        if (! self::isWithinRoot($rootPath, $resolvedPath)) {
            throw new InvalidArgumentException("module.json path escapes module root: {$field}");
        }

        return $resolvedPath;
    }

    private static function isWithinRoot(string $rootPath, string $resolvedPath): bool
    {
        $rootComparison = self::comparisonPath($rootPath);
        $resolvedComparison = self::comparisonPath($resolvedPath);

        return $resolvedComparison === $rootComparison
            || str_starts_with($resolvedComparison, rtrim($rootComparison, '/').'/');
    }

    private static function comparisonPath(string $path): string
    {
        return preg_match('/^[A-Za-z]:/', $path) === 1 ? strtolower($path) : $path;
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '//')) {
            $prefix = '//';
            $path = substr($path, 2);
        }

        $isAbsolute = str_starts_with($path, '/');
        $segments = preg_split('#/+#', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($normalized !== [] && end($normalized) !== '..') {
                    array_pop($normalized);
                    continue;
                }

                if (! $isAbsolute) {
                    $normalized[] = $segment;
                }

                continue;
            }

            $normalized[] = $segment;
        }

        $normalizedPath = implode('/', $normalized);

        if ($isAbsolute) {
            $normalizedPath = '/'.$normalizedPath;
        }

        if ($normalizedPath === '' && $isAbsolute) {
            $normalizedPath = '/';
        }

        return $prefix.$normalizedPath;
    }
}
