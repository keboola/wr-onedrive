<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Tests;

use ArrayObject;
use Generator;
use GuzzleHttp\Psr7\Response;
use Keboola\OneDriveWriter\Api\Api;
use Keboola\OneDriveWriter\Api\GraphApiFactory;
use Keboola\OneDriveWriter\Auth\RefreshTokenProvider;
use Keboola\OneDriveWriter\Auth\TokenDataManager;
use Keboola\OneDriveWriter\Exception\GatewayTimeoutException;
use Keboola\OneDriveWriter\Exception\UserException;
use Microsoft\Graph\Graph;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Throwable;

class ErrorResponseHandlingTest extends TestCase
{
    /**
     * @dataProvider dataProviderBatchRequest
     * @param Response[] $responses
     */
    public function testErrorResponseHandlingOnBatchRequest(
        array $responses,
        string $expectedMessage,
        bool $checkIfRetries
    ): void {

        $logger = new TestLogger();
        try {
            $graphApi = $this->createGraphApi();
            $api = new Api($graphApi, $logger);
            $httpClient = HttpClientMockBuilder::create()->setResponses($responses)->getHttpClient();
            $api->setHttpClient($httpClient);
            iterator_to_array($api->getSheets('1', '1'));
            $this->fail('Should fail');
        } catch (UserException $exception) {
            Assert::assertEquals($expectedMessage, $exception->getMessage());
        }

        if ($checkIfRetries) {
            $this->assertTrue($logger->hasInfoThatContains(
                sprintf('Retrying... [%dx]', Api::RETRY_MAX_ATTEMPTS - 1)
            ));
        }
    }

    /**
     * @dataProvider dataProviderRequest
     * @param Response[] $responses
     * @param class-string $errorClass
     */
    public function testErrorResponseHandlingRequest(
        array $responses,
        string $errorClass,
        string $expectedMessage,
        bool $checkIfRetries
    ): void {
        $graphApi = $this->createGraphApi();
        $logger = new TestLogger();
        $api = new Api($graphApi, $logger);
        $httpClient = HttpClientMockBuilder::create()->setResponses($responses)->getHttpClient();
        $api->setHttpClient($httpClient);

        try {
            $api->getSite('test');
        } catch (Throwable $e) {
            $this->assertInstanceOf($errorClass, $e);
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        if ($checkIfRetries) {
            $this->assertTrue($logger->hasInfoThatContains(
                sprintf('Retrying... [%dx]', Api::RETRY_MAX_ATTEMPTS - 1)
            ));
        }
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
            'checkIfRetires' => false,
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
            'checkIfRetires' => false,
        ];

        $responses = [
            $this->getLoadSheetListSuccessResponse(),
        ];

        array_push(
            $responses,
            ...array_fill(
                0,
                Api::RETRY_MAX_ATTEMPTS,
                $this->getBatchErrorResponse(504, 'MaxRequestDurationExceeded', "We'" .
                    "re sorry. We couldn't finish what you asked us to do because it was taking too long.")
            )
        );
        yield 'Request took to long 504 error' => [
            'responses' => $responses,
            'expectedMessage' => 'OneDrive API error: Request took too long.',
            'checkIfRetires' => true,
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
            'errorClass' => UserException::class,
            'expectedMessage' => sprintf('OneDrive API error: Too many requests. Retry-After (%d seconds) ' .
                'exceeded maximum retry interval (%d seconds)', Api::MAX_INTERVAL + 1, Api::MAX_INTERVAL),
            'checkIfRetires' => false,
        ];

        yield 'Retry-After within maximum limit' => [
            'responses' => $this->get429Responses(['Retry-After' => [1000]], 15),
            'errorClass' => UserException::class,
            'expectedMessage' => 'OneDrive API error: Too many requests.',
            'checkIfRetires' => true,
        ];

        yield 'Too many request without Retry-After header' => [
            'responses' => $this->get429Responses([], 15),
            'errorClass' => UserException::class,
            'expectedMessage' => 'OneDrive API error: Too many requests.',
            'checkIfRetires' => true,
        ];

        yield '504 Gateway Timeout' => [
            'responses' => $this->get504Responses(15),
            'errorClass' => GatewayTimeoutException::class,
            'expectedMessage' => 'Gateway Timeout Error. The Microsoft OneDrive API has some problems. Please try ' .
                'again later. API message: Server error: `GET v1.0/sites?search=test&$select=id,name` resulted in a ' .
                '`504 Gateway Time-out` response:
 (truncated...)
',
            'checkIfRetires' => true,
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

    /**
     * @return Response[]
     */
    private function get504Responses(int $count = 1): array
    {
        return array_fill(
            0,
            $count,
            new Response(504, [], '{
                  "error": {
                    "code": "MaxRequestDurationExceeded",
                    "innerError": {
                      "code": "504",
                      "date": "2020-08-18T12:51:51",
                      "message": "We couldn\'t finish what you asked us to do because it was taking too long.We ' .
                'couldn\'t finish what you asked us to do because it was taking too long.",
                      "request-id": "94fb3b52-452a-4535-a601-69e0a90e3aa2",
                      "status": "429"
                    },
                    "message": "Please retry again later."
                  }
                }')
        );
    }
}
