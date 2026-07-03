<?php

namespace App\Modules;

use App\Http\Services\auth\Node;
use Doctrine\Common\Annotations\AnnotationException;
use ReflectionException;

final class ModuleNodeScanner
{
    public function __construct(private readonly ModuleManager $modules)
    {
    }

    public function getNodeList(): array
    {
        $nodes = [];

        foreach ($this->modules->enabled() as $manifest) {
            $controllersPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $manifest->controllersPath());

            if (! is_dir($controllersPath)) {
                continue;
            }

            try {
                $moduleNodes = (new Node($controllersPath, $manifest->namespace()))->getNodeList();
            } catch (AnnotationException | ReflectionException) {
                $moduleNodes = [];
            }

            foreach ($moduleNodes as $node) {
                if (isset($node['node'])) {
                    $rawNode = preg_replace('#^Controllers/#', '', ltrim((string) $node['node'], '/'));
                    $rawNode = preg_replace('#^s/#', '', (string) $rawNode);
                    $node['node'] = $manifest->adminPrefix().'/'.$rawNode;
                }

                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
