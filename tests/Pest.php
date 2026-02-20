<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

use Mitsuki\Controller\Resolvers\ControllerResolver;
use Mitsuki\Hermite\Router;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\DefaultValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestAttributeValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestValueResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Tests\Container\MockContainer;
use Tests\Controllers\MockPostController;

pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createMockContainer(): MockContainer
{
    $projectRoot = realpath(__DIR__ . '/..');
    $app = new MockContainer();

    $app->setParameter('project.root', $projectRoot);
    $app->setParameter('cache.dir', __DIR__ . '/temp_cache');

    $app->addDefinition('project.root', fn() => $projectRoot);
    $app->addDefinition('cache.dir', fn($c) => $c->getParameter('cache.dir'));
    $app->addDefinition('Tests\Controllers\MockPostController', fn($c) => new MockPostController());
    $app->addDefinition('controllers', fn() => [MockPostController::class]);

    $app->addDefinition(RouteCollection::class, fn() => new RouteCollection());
    $app->addDefinition(RequestContext::class, fn() => new RequestContext());
    $app->addDefinition('ControllerResolver', fn($c) => new ControllerResolver($projectRoot));

    $app->addDefinition(Router::class, function ($c) {
        $router = new Router(
            $c->get(RouteCollection::class),
            $c->get(RequestContext::class),
            $c,
            $c->get('ControllerResolver'),
            $c->get('cache.dir')
        );

        $controllers = $c->has('controllers') ? $c->get('controllers') : [];
        $router->load($controllers);

        return $router;
    });

    $app->addDefinition(ControllerResolverInterface::class, function ($c) {
        return new class($c->get(Router::class)) implements ControllerResolverInterface {
            public function __construct(private Router $router)
            {
            }

            public function getController(Request $request): callable|false
            {
                return $this->router->getCallable($request);
            }
        };
    });

    $app->addDefinition(HttpKernelInterface::class, function ($c) {
        $argumentResolver = new ArgumentResolver(
            null,
            [
                new RequestAttributeValueResolver(),
                new RequestValueResolver(),
                new DefaultValueResolver(),
            ]
        );

        return new HttpKernel(
            new EventDispatcher(),
            $c->get(ControllerResolverInterface::class),
            new RequestStack(),
            $argumentResolver
        );
    });

    return $app;
}

uses()->beforeEach(function () {

    $this->app = createMockContainer();

})->in(__DIR__);
