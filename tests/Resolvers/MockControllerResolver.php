<?php

namespace Tests\Resolvers;

use Mitsuki\Contracts\Controllers\ControllerResolverInterface;

class MockControllerResolver implements ControllerResolverInterface
{

    public function resolve(): array
    {
        return [
            'Tests\Controllers\MockPostController'
        ];
    }
}