<?php

namespace App\Modules;

use RuntimeException;

final class ModuleArtifactStore
{
    public function __construct(
        private readonly ModuleFileStore $files,
        private readonly ModuleArtifactHasher $hasher,
    ) {}

    public function stage(ModuleManifest $manifest, ?string $expectedHash = null): string
    {
        $hash = $this->hasher->hashDirectory($manifest->path());
        if ($expectedHash !== null && ! hash_equals($expectedHash, $hash)) {
            throw new RuntimeException('模块制品哈希在暂存前发生变化。');
        }

        $target = storage_path(
            'modules/releases/'
            .$this->safeSegment($manifest->name()).'/'
            .$this->safeSegment($manifest->version()).'-'.$hash
        );

        if (is_dir($target)) {
            $storedHash = $this->hasher->hashDirectory($target);
            if (! hash_equals($hash, $storedHash)) {
                throw new RuntimeException('已存在的模块制品哈希不一致。');
            }

            return $target;
        }

        $this->files->copyImmutable($manifest->path(), $target);
        $storedHash = $this->hasher->hashDirectory($target);
        if (! hash_equals($hash, $storedHash)) {
            $this->files->deleteDirectory($target);
            throw new RuntimeException('模块制品复制后哈希不一致。');
        }

        return $target;
    }

    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '_';

        return in_array($safe, ['', '.', '..'], true) ? '_' : $safe;
    }
}
