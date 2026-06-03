<?php

use App\Blueprint\Generators\CustomControllerGenerator;
use App\Blueprint\Generators\CustomInertiaPageGenerator;
use App\Blueprint\Generators\CustomModelGenerator;

function makeGeneratorWithoutConstructor(string $class, array $properties = []): object
{
    $reflection = new ReflectionClass($class);
    $instance = $reflection->newInstanceWithoutConstructor();

    foreach ($properties as $name => $value) {
        $className = $class;

        while ($className !== false && ! property_exists($className, $name)) {
            $className = get_parent_class($className);
        }

        if ($className === false) {
            throw new RuntimeException("Property [{$name}] does not exist in [{$class}] hierarchy.");
        }

        $setter = Closure::bind(function (string $propertyName, mixed $propertyValue): void {
            $this->{$propertyName} = $propertyValue;
        }, $instance, $className);

        $setter($name, $value);
    }

    return $instance;
}

function callProtected(object $instance, string $method, array $arguments = []): mixed
{
    $className = get_class($instance);

    while ($className !== false && ! method_exists($className, $method)) {
        $className = get_parent_class($className);
    }

    if ($className === false) {
        throw new RuntimeException("Method [{$method}] does not exist in [".get_class($instance).'] hierarchy.');
    }

    $caller = Closure::bind(function (string $methodName, array $methodArguments): mixed {
        return $this->{$methodName}(...$methodArguments);
    }, $instance, $className);

    return $caller($method, $arguments);
}

it('normalizes legacy notification namespace imports', function () {
    config(['blueprint.namespace' => 'App']);

    $generator = makeGeneratorWithoutConstructor(CustomControllerGenerator::class);

    $stub = <<<'PHP'
<?php

use App\Notification\PostPublished;
use App\Models\Post;
PHP;

    $normalized = callProtected($generator, 'normalizeNotificationNamespaces', [$stub]);

    expect($normalized)->toContain('use App\\Notifications\\PostPublished;')
        ->not->toContain('use App\\Notification\\PostPublished;');
});

it('replaces static model collection calls with query builder chains', function () {
    $generator = makeGeneratorWithoutConstructor(CustomControllerGenerator::class);

    $stub = <<<'PHP'
public function index(): Response
{
    $posts = Post::all();
    $comments = Comment::all();
}
PHP;

    $result = callProtected($generator, 'replaceModelCollectionQueries', [$stub, 'Post']);

    expect($result)->toContain('$posts = QueryBuilder::for(Post::class)')
        ->toContain('->allowedFilters(...[])')
        ->toContain('->allowedSorts(...[])')
        ->toContain('->get();')
        ->toContain('$comments = Comment::all();');
});

it('preserves inertia page path exactly as defined in draft view', function () {
    $generator = makeGeneratorWithoutConstructor(CustomInertiaPageGenerator::class, [
        'adapter' => ['extension' => '.tsx', 'framework' => 'react'],
    ]);

    $path = callProtected($generator, 'getStatementPath', ['Blog/Post.index']);

    expect($path)->toBe('resources/js/pages/Blog/Post/index.tsx');
});

it('builds stable route placeholders for inertia page stubs', function () {
    $generator = makeGeneratorWithoutConstructor(CustomInertiaPageGenerator::class);

    [$routeImport, $routeHref] = callProtected($generator, 'buildRoutePlaceholders', ['blog-posts/index']);

    expect($routeImport)->toBe("import blogPosts from '@/routes/blog-posts';")
        ->and($routeHref)->toBe('blogPosts.index().url');
});

it('inserts model imports only once and appends methods once', function () {
    $generator = makeGeneratorWithoutConstructor(CustomModelGenerator::class);

    $stub = <<<'PHP'
<?php

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
}
PHP;

    $withImports = callProtected($generator, 'insertUses', [$stub, [
        'Laravel\\Scout\\Searchable',
        'Laravel\\Scout\\Searchable',
    ]]);

    $method = <<<'PHP'

    public function toSearchableArray(): array
    {
        return [];
    }
PHP;

    $withMethod = callProtected($generator, 'appendMethodBeforeClassEnd', [$withImports, $method]);
    $withMethodAgain = callProtected($generator, 'appendMethodBeforeClassEnd', [$withMethod, $method]);

    expect(substr_count($withMethodAgain, 'use Laravel\\Scout\\Searchable;'))->toBe(1)
        ->and(substr_count($withMethodAgain, 'public function toSearchableArray(): array'))->toBe(1);
});
