<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

class HttpClientMockBuilder
{
    private array $responses = [];

    public static function create(): self
    {
        return new self();
    }

    public function getHttpClient(): Client
    {
        $handlerStack = HandlerStack::create(new MockHandler($this->responses));
        return new Client(['handler' => $handlerStack]);
    }

    /**
     * @param ResponseInterface[] $responses
     */
    public function setResponses(array $responses): self
    {
        $this->responses = $responses;
        return $this;
    }
}
