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

use DI\ContainerBuilder;
use Mitsuki\Controller\Resolvers\ControllerResolver;
use Mitsuki\Hermite\Router;
use Psr\Container\ContainerInterface;
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
use Tests\Controllers\MockPostController;
use function DI\create;

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

uses()->beforeEach(function () {

    $projectRoot = realpath(__DIR__ . '/..');
    $app = new ContainerBuilder();

    $app->addDefinitions([
        'project.root' => $projectRoot,
        'cache.dir' => __DIR__ . '/temp_cache',
        'controllers' => [MockPostController::class],

        Router::class => function (ContainerInterface $c) {
            $router = new Router(
                $c->get(RouteCollection::class),
                $c->get(RequestContext::class),
                $c,
                $c->get(ControllerResolver::class),
                $c->get('cache.dir')
            );

            $controllers = $c->has('controllers') ? $c->get('controllers') : [];
            $router->load($controllers);

            return $router;
        },
        ControllerResolverInterface::class => function (ContainerInterface $c) {
            return new class($c->get(Router::class)) implements ControllerResolverInterface {
                public function __construct(private Router $router)
                {
                }

                public function getController(Request $request): callable|false
                {
                    return $this->router->getCallable($request);
                }
            };
        },
        HttpKernelInterface::class => function (ContainerInterface $c) {

            $argumentResolver = new ArgumentResolver(
                null,
                [
                    new RequestAttributeValueResolver(),
                    new RequestValueResolver(),
                    new DefaultValueResolver(),
                ]
            );

            return new HttpKernel(
                $c->get(EventDispatcher::class),
                $c->get(ControllerResolverInterface::class),
                new RequestStack(),
                $argumentResolver
            );
        },
        RequestContext::class => create(RequestContext::class),
        RouteCollection::class => create(RouteCollection::class),
        ControllerResolver::class => function () use ($projectRoot) {
            return new ControllerResolver($projectRoot);
        }
    ]);

    $this->app = $app->build();
})->in(__DIR__);
