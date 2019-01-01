<?php

namespace Tobscure\JsonApiServer;

use Closure;
use JsonApiPhp\JsonApi;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Tobscure\JsonApiServer\Exception\MethodNotAllowedException;
use Tobscure\JsonApiServer\Exception\ResourceNotFoundException;
use Tobscure\JsonApiServer\Handler\Concerns\FindsResources;

class Api implements RequestHandlerInterface
{
    use FindsResources;

    protected $resources = [];
    protected $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function resource(string $type, $adapter, Closure $buildSchema = null): void
    {
        $this->resources[$type] = new ResourceType($type, $adapter, $buildSchema);
    }

    public function getResource(string $type): ResourceType
    {
        if (! isset($this->resources[$type])) {
            throw new ResourceNotFoundException($type);
        }

        return $this->resources[$type];
    }

    public function handle(Request $request): Response
    {
        $path = $this->stripBasePath(
            $request->getUri()->getPath()
        );

        $segments = explode('/', trim($path, '/'));

        switch (count($segments)) {
            case 1:
                return $this->getCollectionHandler($request, $segments)
                    ->handle($request);

            case 2:
                return $this->getMemberHandler($request, $segments)
                    ->handle($request);

            // case 3:
            //     return $this->handleRelated($request, $resource, $model, $segments[2]);

            // case 4:
            //     return $this->handleRelationship($request, $resource, $model, $segments[3]);
        }

        throw new \RuntimeException;
    }

    private function stripBasePath(string $path): string
    {
        $basePath = parse_url($this->baseUrl, PHP_URL_PATH);

        $len = strlen($basePath);

        if (substr($path, 0, $len) === $basePath) {
            $path = substr($path, $len + 1);
        }

        return $path;
    }

    private function getCollectionHandler(Request $request, array $segments): RequestHandlerInterface
    {
        $resource = $this->getResource($segments[0]);

        switch ($request->getMethod()) {
            case 'GET':
                return new Handler\Index($this, $resource);

            case 'POST':
                return new Handler\Create($this, $resource);

            default:
                throw new MethodNotAllowedException;
        }
    }

    private function getMemberHandler(Request $request, array $segments): RequestHandlerInterface
    {
        $resource = $this->getResource($segments[0]);
        $model = $this->findResource($request, $resource, $segments[1]);

        switch ($request->getMethod()) {
            case 'PATCH':
                return new Handler\Update($this, $resource, $model);

            case 'GET':
                return new Handler\Show($this, $resource, $model);

            case 'DELETE':
                return new Handler\Delete($resource, $model);

            default:
                throw new MethodNotAllowedException;
        }
    }

    public function error(\Throwable $e)
    {
        $data = new JsonApi\ErrorDocument(
            new JsonApi\Error(
                new JsonApi\Error\Title($e->getMessage()),
                new JsonApi\Error\Detail((string) $e)
            )
        );

        return new JsonApiResponse($data);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
