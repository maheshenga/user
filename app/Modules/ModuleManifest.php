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

        $basePath = dirname($path);
        $decoded['path'] = $basePath;
        $decoded['controllers'] = self::resolvePath($basePath, (string) ($decoded['controllers'] ?? 'src/Controllers'));
        $decoded['views'] = self::resolvePath($basePath, (string) ($decoded['views'] ?? 'resources/views'));
        $decoded['assets'] = self::resolvePath($basePath, (string) ($decoded['assets'] ?? 'assets'));
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

    private static function resolvePath(string $basePath, string $path): string
    {
        if ($path === '') {
            return self::normalizePath($basePath);
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return self::normalizePath($path);
        }

        return self::normalizePath($basePath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
