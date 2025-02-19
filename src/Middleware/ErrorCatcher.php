<?php
namespace Yiisoft\Yii\Web\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Yii\Web\ErrorHandler\ErrorHandler;
use Yiisoft\Yii\Web\ErrorHandler\ThrowableRendererInterface;
use Yiisoft\Yii\Web\ErrorHandler\HtmlRenderer;
use Yiisoft\Yii\Web\ErrorHandler\JsonRenderer;
use Yiisoft\Yii\Web\ErrorHandler\PlainTextRenderer;
use Yiisoft\Yii\Web\ErrorHandler\XmlRenderer;

/**
 * ErrorCatcher catches all throwables from the next middlewares and renders it
 * accoring to the content type passed by the client.
 */
final class ErrorCatcher implements MiddlewareInterface
{
    private $responseFactory;
    private $errorHandler;
    private $container;

    private $renderers = [
        'application/json' => JsonRenderer::class,
        'application/xml' => XmlRenderer::class,
        'text/xml' => XmlRenderer::class,
        'text/plain' => PlainTextRenderer::class,
        'text/html' => HtmlRenderer::class,
    ];

    public function __construct(ResponseFactoryInterface $responseFactory, ErrorHandler $errorHandler, ContainerInterface $container)
    {
        $this->responseFactory = $responseFactory;
        $this->errorHandler = $errorHandler;
        $this->container = $container;
    }

    private function handleException(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $contentType = $this->getContentType($request);
        $renderer = $this->getRenderer($contentType);
        $renderer->setRequest($request);
        $content = $this->errorHandler->handleCaughtThrowable($e, $renderer);

        $response = $this->responseFactory->createResponse(500)
            ->withHeader('Content-type', $contentType);
        $response->getBody()->write($content);
        return $response;
    }

    private function getRenderer(string $contentType): ?ThrowableRendererInterface
    {
        if (isset($this->renderers[$contentType])) {
            return $this->container->get($this->renderers[$contentType]);
        }

        return null;
    }

    private function getContentType(ServerRequestInterface $request): string
    {
        $acceptHeaders = preg_split('~\s*,\s*~', $request->getHeaderLine('Accept'), PREG_SPLIT_NO_EMPTY);
        foreach ($acceptHeaders as $header) {
            if (array_key_exists($header, $this->renderers)) {
                return $header;
            }
        }
        return 'text/html';
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request);
        }
    }
}
