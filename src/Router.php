<?php

namespace Mitsuki\Hermite;

use Mitsuki\Attributes\Controller;
use Mitsuki\Attributes\Route;
use Mitsuki\Controller\Resolvers\ControllerResolver;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\NoConfigurationException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

/**
 * Class Router
 *
 * Central routing engine for the Mitsuki framework.
 * Orchestrates controller discovery, attribute-based route extraction,
 * URL prefixing (grouping), and route compilation for caching.
 *
 * @author Zgenius Matondo <zgeniuscoders@gmail.com>
 */
class Router
{
    /** @var Filesystem Utility for cache file management. */
    private Filesystem $filesystem;

    /** @var string Absolute path to the compiled routes cache file. */
    private string $cacheFile;

    /**
     * Router Constructor.
     *
     * @param RouteCollection $routeCollection Symfony collection to store generated Route objects.
     * @param RequestContext $requestContext Information about the current request (method, host, etc.).
     * @param ContainerInterface $container PSR-11 container to resolve and instantiate controllers.
     * @param ControllerResolver $controllerResolver Service to automatically discover controllers in the project.
     * @param string $cacheDir Directory where the compiled route cache will be persisted.
     */
    public function __construct(
        private RouteCollection    $routeCollection,
        private RequestContext     $requestContext,
        private ContainerInterface $container,
        private ControllerResolver $controllerResolver,
        string                     $cacheDir
    )
    {
        $this->cacheFile = $cacheDir . '/cache_routes.php';
        $this->filesystem = new Filesystem();
    }

    /**
     * Registers a single route into the Symfony RouteCollection.
     *
     * @param string|array $method HTTP method(s) allowed (e.g., GET, POST).
     * @param string $name Unique identifier for the route.
     * @param string $path The final URL pattern (including prefixes).
     * @param array $controller Controller definition in the format [ClassName, MethodName].
     * @return void
     */
    private function addRoute(string|array $method, string $name, string $path, array $controller): void
    {
        $this->routeCollection->add($name, new SymfonyRoute($path, [
            '_controller' => $controller,
        ], methods: $method));
    }

    /**
     * Loads application routes by reading from cache or scanning discovered controllers.
     *
     * @param array $controllers List of FQCNs to load manually (merged with auto-discovered ones).
     * @return void
     * @throws \ReflectionException If a controller class is invalid or missing.
     */
    public function load(array $controllers = []): void
    {
        // 1. Priority: Load from compiled cache for performance
        if ($this->filesystem->exists($this->cacheFile)) {
            $routesData = require $this->cacheFile;
            foreach ($routesData as $name => $data) {
                $this->addRoute($data['method'], $name, $data['path'], $data['controller']);
            }
            return;
        }

        // 2. Secondary: Auto-discovery via ControllerResolver
        $controllers = array_merge(
            $controllers,
            $this->controllerResolver->resolve()
        );

        // 3. Compile attributes and persist to cache
        $cachedData = $this->getRoutesFromControllers($controllers);

        $content = "<?php\nreturn " . var_export($cachedData, true) . ";";
        $this->filesystem->dumpFile($this->cacheFile, $content);
    }

    /**
     * Resolves the current HTTP request into an executable controller callable.
     *
     * @param Request $request The incoming Symfony Request object.
     * @return callable The resolved controller: [$instance, $methodName].
     * @throws NotFoundHttpException If no route matches or the controller is not found.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface If the DI container fails to resolve the class.
     */
    public function getCallable(Request $request): callable
    {
        try {

            $this->requestContext->setMethod($request->getMethod());

            $matcher = new UrlMatcher($this->routeCollection, $this->requestContext);
            $parameters = $matcher->match($request->getPathInfo());

            // Bind route parameters (e.g., {id}) to request attributes
            $request->attributes->add($parameters);

            [$controllerClass, $method] = $parameters['_controller'];

            if (!$this->container->has($controllerClass) && !class_exists($controllerClass)) {
                throw new \RuntimeException("Controller class '$controllerClass' not found.");
            }

            $instance = $this->container->get($controllerClass);

            return [$instance, $method];

        } catch (ResourceNotFoundException|NoConfigurationException $e) {
            throw new NotFoundHttpException("No route found for " . $request->getPathInfo(), $e);
        }
    }

    /**
     * Analyzes #[Controller] and #[Route] attributes within the provided classes.
     * * Handles smart concatenation between class-level prefixes and method-level paths,
     * ensuring URL normalization (slash cleanup).
     *
     * @param array $controllers List of controller FQCNs to inspect.
     * @return array Prepared route data for persistent cache.
     * @throws NotFoundHttpException If reflection fails during analysis.
     */
    private function getRoutesFromControllers(array $controllers): array
    {
        try {
            $cachedData = [];
            foreach ($controllers as $controllerClass) {
                $reflection = new ReflectionClass($controllerClass);

                // Class-level prefix via #[Controller]
                $cAttr = $reflection->getAttributes(Controller::class);
                $basePath = !empty($cAttr) ? ($cAttr[0]->newInstance()->getBaseUri() ?? '') : '';

                foreach ($reflection->getMethods() as $method) {
                    $attributes = $method->getAttributes(Route::class);

                    foreach ($attributes as $attribute) {
                        /** @var Route $routeInstance */
                        $routeInstance = $attribute->newInstance();

                        // Smart path concatenation
                        $path = '/' . trim($basePath, '/') . '/' . trim($routeInstance->getPath(), '/');
                        $path = preg_replace('#/+#', '/', $path);
                        $path = rtrim($path, '/');
                        if (empty($path)) $path = '/';

                        $this->addRoute(
                            $routeInstance->getMethods(),
                            $routeInstance->getName(),
                            $path,
                            [$controllerClass, $method->getName()]
                        );

                        $cachedData[$routeInstance->getName()] = [
                            'method' => $routeInstance->getMethods(),
                            'path' => $path,
                            'controller' => [$controllerClass, $method->getName()]
                        ];
                    }
                }
            }
            return $cachedData;
        } catch (\Exception $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }
    }
}