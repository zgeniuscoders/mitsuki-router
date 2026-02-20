# Mitsuki Router

Mitsuki is a **modern HTTP routing engine** for PHP, powered by Symfony Routing/HttpKernel and **PHP 8
attributes**. It provides:

- Automatic controller discovery
- Attribute-based routing (`#[Controller]`, `#[Route]`)
- PSR-11 container integration
- HttpKernel-compatible controller resolution
- Compiled routes caching

***

## Installation

```bash
composer require mitsuki/router
```

> The router relies on Symfony Routing, HttpFoundation, HttpKernel, Filesystem, and a PSR-11 container.

***

## Core Concepts

### Attributes

- `#[Controller('prefix')]` on classes
- `#[Route('name', 'path', methods)]` on methods

```php
use Mitsuki\Attributes\Controller;
use Mitsuki\Attributes\Route;
use Mitsuki\Controller\BaseController;
use Mitsuki\Http\Responses\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

#[Controller('posts')]
class PostController extends BaseController
{
    #[Route('posts.index', '', ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(['data' => ['post 1', 'post 2', 'post 3']]);
    }

    #[Route('posts.store', '', ['POST'])]
    public function store(Request $request): JsonResponse
    {
        return $this->json([
            'title' => $request->request->get('title'),
        ]);
    }

    #[Route('posts.show', '{id}', ['GET'])]
    public function show(int $id): JsonResponse
    {
        return $this->json(['data' => 'post 1']);
    }

    #[Route('posts.update', '{id}', ['PUT'])]
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->json(['id' => $id]);
    }

    #[Route('posts.destroy', '{id}', ['DELETE'])]
    public function destroy(int $id): JsonResponse
    {
        return $this->json([], status: 204);
    }
}
```

***

## Architecture

### `Router` Class

Namespace: `Mitsuki\Hermite\Router`

**Responsibilities:**

- Load routes from **compiled cache** or **controller scanning**
- Build Symfony `RouteCollection`
- Delegate matching to `UrlMatcher`
- Resolve **HttpKernel-compatible controller callable**

```php
public function __construct(
    private RouteCollection    $routeCollection,
    private RequestContext     $requestContext,
    private ContainerInterface $container,
    private ControllerResolver $controllerResolver,
    string                     $cacheDir
)
```

### Workflow

1. **`load()`**
    - If `cache_routes.php` exists → load routes from cache
    - Otherwise → use `ControllerResolver` to discover controllers
    - Analyze attributes, build routes, write cache

2. **`getCallable(Request $request)`**
    - Updates `RequestContext::setMethod()`
    - Uses `UrlMatcher` to match URL + method
    - Binds route parameters to request attributes
    - Fetches controller instance via container
    - Returns `[instance, 'method']` for HttpKernel

### Path Concatenation

In `getRoutesFromControllers()`:

- Class prefix: `#[Controller('posts')]`
- Method path: `#[Route('posts.show', '{id}', ['GET'])]`
- Normalized result: `/posts/{id}`

**Normalization:**

- Removes multiple `/`
- Strips trailing `/`
- Falls back to `/` if empty

***

## Container Integration

Example with **PHP-DI**:

```php
use Mitsuki\Hermite\Router;
use Mitsuki\Controller\Resolvers\ControllerResolver;
use Psr\Container\ContainerInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\DefaultValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestAttributeValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestValueResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\EventDispatcher;

return [
    'project.root' => dirname(__DIR__) . '/src',
    'cache.dir'    => dirname(__DIR__) . '/var/caches',

    ControllerResolver::class => fn($c) => 
        new ControllerResolver($c->get('project.root')),

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

    ControllerResolverInterface::class => fn($c) => new class($c->get(Router::class)) implements ControllerResolverInterface {
        public function __construct(private Router $router) {}
        public function getController(Request $request): callable|false
        {
            return $this->router->getCallable($request);
        }
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
            new EventDispatcher(),
            $c->get(ControllerResolverInterface::class),
            new RequestStack(),
            $argumentResolver
        );
    },

    RequestContext::class => \DI\create(RequestContext::class),
    RouteCollection::class => \DI\create(RouteCollection::class),
];
```

***

## Request Lifecycle

```text
HTTP Request
   ↓
HttpKernel::handle()
   ↓
Router::getCallable()
   ↓
[ControllerInstance, method]
   ↓
ArgumentResolver (Request, attributes, defaults)
   ↓
Controller executed
   ↓
JsonResponse / Response
```

***

## Usage Example

```php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$kernel = $container->get(HttpKernelInterface::class);

$request  = Request::create('/posts', 'GET');
$response = $kernel->handle($request);

echo $response->getStatusCode();  // 200
echo $response->getContent();     // JSON data
```

***

## Testing

Tested with **PestPHP** (95%+ coverage).

**Integration tests:**

```php
test('GET /posts returns index with paginated data - E2E', function () {
    $request  = Request::create('/posts', 'GET');
    $response = $this->app->get(HttpKernelInterface::class)->handle($request);
    
    $data = json_decode($response->getContent(), true);
    
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data'])->toHaveCount(3);
});

test('returns 404 Not Found for unknown routes', function () {
    $request = Request::create('/unknown', 'GET');
    
    expect(fn() => $this->app->get(HttpKernelInterface::class)->handle($request))
        ->toThrow(NotFoundHttpException::class);
});
```

**Run tests with coverage:**

```bash
XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --coverage-html coverage-report
```

***

## Best Practices

- Always define **unique route names** in `#[Route]`
- Use **consistent prefixes** via `#[Controller('prefix')]`
- Write tests for each CRUD endpoint
- Enable route caching in production for optimal performance

***

## Roadmap

- Route-specific middleware support
- Configurable route groups (auth, API, etc.)
- URL generation from route names
- Request validation & DTO integration

***

---

## 📄 License

This project is licensed under the MIT License.

---

**Maintained by Zgenius Matondo**
GitHub: [https://github.com/zgeniuscoders](https://github.com/zgeniuscoders)