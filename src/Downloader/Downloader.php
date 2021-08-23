<?php

declare(strict_types=1);

/**
 * Copyright (c) 2021 Kai Sassnowski
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/roach-php/roach
 */

namespace Sassnowski\Roach\Downloader;

use Sassnowski\Roach\Events\RequestDropped;
use Sassnowski\Roach\Events\RequestSending;
use Sassnowski\Roach\Http\ClientInterface;
use Sassnowski\Roach\Http\Request;
use Sassnowski\Roach\Http\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Downloader
{
    /**
     * @var DownloaderMiddlewareInterface[]
     */
    private array $middleware = [];

    private array $requests = [];

    public function __construct(
        private ClientInterface $client,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function withMiddleware(DownloaderMiddlewareInterface ...$middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    public function prepare(Request $request): void
    {
        foreach ($this->middleware as $middleware) {
            $request = $middleware->handleRequest($request);

            if ($request->wasDropped()) {
                $this->eventDispatcher->dispatch(
                    new RequestDropped($request),
                    RequestDropped::NAME,
                );

                return;
            }
        }

        $this->eventDispatcher->dispatch(
            new RequestSending($request),
            RequestSending::NAME,
        );

        $this->requests[] = $request;
    }

    public function flush(?callable $callback = null): void
    {
        $requests = $this->requests;

        $this->requests = [];

        $this->client->pool($requests, function (Response $response) use ($callback): void {
            $this->onResponseReceived($response, $callback);
        });
    }

    private function onResponseReceived(Response $response, ?callable $callback): void
    {
        foreach ($this->middleware as $middleware) {
            $response = $middleware->handleResponse($response);

            if ($response->wasDropped()) {
                return;
            }
        }

        if (null !== $callback) {
            $callback($response);
        }
    }
}