<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests;

use ArrayObject;
use Generator;
use GuzzleHttp\Psr7\Response;
use Keboola\Component\Logger;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Api\GraphApiFactory;
use Keboola\OneDriveWriter\Auth\RefreshTokenProvider;
use Keboola\OneDriveWriter\Auth\TokenDataManager;
use Keboola\OneDriveWriter\Exception\UserException;
use Microsoft\Graph\Graph;
use PHPUnit\Framework\TestCase;

class ErrorResponseHandlingTest extends TestCase
{
    /**
     * @dataProvider dataProviderBatchRequest
     * @param Response[] $responses
     */
    public function testErrorResponseHandlingOnBatchRequest(array $responses, string $expectedMessage): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage($expectedMessage);

        $graphApi = $this->createGraphApi();
        $api = new Api($graphApi, new Logger());
        $httpClient = HttpClientMockBuilder::create()->setResponses($responses)->getHttpClient();
        $api->setHttpClient($httpClient);
        iterator_to_array($api->getSheets('1', '1'));
    }

    /**
     * @dataProvider dataProviderRequest
     * @param Response[] $responses
     */
    public function testErrorResponseHandlingRequest(array $responses, string $expectedMessage): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage($expectedMessage);

        $graphApi = $this->createGraphApi();
        $api = new Api($graphApi, new Logger());
        $httpClient = HttpClientMockBuilder::create()->setResponses($responses)->getHttpClient();
        $api->setHttpClient($httpClient);
        $api->getSite('test');
    }

    private function createGraphApi(): Graph
    {
        $appId = (string) getenv('OAUTH_APP_ID');
        $appSecret = (string) getenv('OAUTH_APP_SECRET');
        $accessToken = (string) getenv('OAUTH_ACCESS_TOKEN');
        $refreshToken = (string) getenv('OAUTH_REFRESH_TOKEN');
        $oauthData = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
        $dataManager = new TokenDataManager($oauthData, new ArrayObject());
        $tokenProvider = new RefreshTokenProvider($appId, $appSecret, $dataManager);
        $apiFactory = new GraphApiFactory();
        return $apiFactory->create($tokenProvider->get());
    }

    /**
     * @return Generator<string, mixed>
     */
    public function dataProviderBatchRequest(): Generator
    {
        yield 'Workbook 500 error' => [
            'responses' => [
                $this->getLoadSheetListSuccessResponse(),
                $this->getBatchErrorResponse(
                    503,
                    'GenericFileOpenError',
                    'The workbook cannot be opened.'
                ),
            ],
            'expectedMessage' => 'OneDrive API error: The workbook cannot be opened. Make sure nobody is editing it.',
        ];

        yield 'Service unavailable 503 error' => [
            'responses' => [
                $this->getLoadSheetListSuccessResponse(),
                $this->getBatchErrorResponse(
                    503,
                    'UnknownError',
                    'The service is unavailable.'
                ),
            ],
            'expectedMessage' => 'OneDrive API error: The service is unavailable.',
        ];

        yield 'Request took to long 504 error' => [
            'responses' => [
                $this->getLoadSheetListSuccessResponse(),
                $this->getBatchErrorResponse(504, 'MaxRequestDurationExceeded', "We'" .
                "re sorry. We couldn't finish what you asked us to do because it was taking too long."),
            ],
            'expectedMessage' => 'OneDrive API error: Request took too long.',
        ];
    }

    protected function getLoadSheetListSuccessResponse(): Response
    {
        return new Response(200, [], '{"@odata.context":"https:\/\/graph.microsoft.com\/v1.0\/' .
            '$metadata#drives(\'b%21nZgsjp3RK0aRFp01PZWjKUicqho1KehCtKM1UhLEWybvgM_dt6mJRKV57vuJLf4Q\')\/items' .
            '(\'01GQDMCCOULCHLF6PJD5FI3CH4OWOP4ZLR\')\/workbook\/worksheets(id,position,name,visibility)","value"' .
            ':[{"@odata.id":"\/drives(\'b%21nZgsjp3RK0aRFp01PZWjKUicqho1KehCtKM1UhLEWybvgM_dt6mJRKV57vuJLf4Q\')\/' .
            'items(\'01GQDMCCOULCHLF6PJD5FI3CH4OWOP4ZLR\')\/workbook\/worksheets(%27%7B00000000-0001-0000-0000-0000' .
            '00000000%7D%27)","id":"{00000000-0001-0000-0000-000000000000}","name":"Only One Sheet","position":0,' .
            '"visibility":"Visible"}]}');
    }

    protected function getBatchErrorResponse(int $statusCode, string $errorCode, string $errorMessage): Response
    {
        return new Response(200, [], sprintf('
                    {
                      "responses": [
                            {
                              "id": "1",
                              "status": %d,
                              "body": {
                                "error": {
                                  "code": "%s",
                                  "message": "%s"
                                }
                              }
                            }
                        ]
                    }', $statusCode, $errorCode, $errorMessage));
    }

    /**
     * @return Generator<string, mixed>
     */
    public function dataProviderRequest(): Generator
    {
        yield 'Retry-After over maximum limit' => [
            'responses' => $this->get429Responses(['Retry-After' => [Api::MAX_INTERVAL + 1]]),
            'expectedMessage' => sprintf('OneDrive API error: Too many requests. Retry-After (%d seconds) ' .
                'exceeded maximum retry interval (%d seconds)', Api::MAX_INTERVAL + 1, Api::MAX_INTERVAL),
        ];

        yield 'Retry-After within maximum limit' => [
            'responses' => $this->get429Responses(['Retry-After' => [1000]], 15),
            'expectedMessage' => 'OneDrive API error: Too many requests.',
        ];

        yield 'Too many request without Retry-After header' => [
            'responses' => $this->get429Responses([], 15),
            'expectedMessage' => 'OneDrive API error: Too many requests.',
        ];
    }

    /**
     * @param array<string, mixed> $headers
     * @return Response[]
     */
    private function get429Responses(array $headers, int $count = 1): array
    {
        return array_fill(
            0,
            $count,
            new Response(429, $headers, '{
                  "error": {
                    "code": "TooManyRequests",
                    "innerError": {
                      "code": "429",
                      "date": "2020-08-18T12:51:51",
                      "message": "Please retry after",
                      "request-id": "94fb3b52-452a-4535-a601-69e0a90e3aa2",
                      "status": "429"
                    },
                    "message": "Please retry again later."
                  }
                }')
        );
    }
}
