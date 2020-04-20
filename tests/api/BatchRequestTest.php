<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\ApiTests;

use PHPUnit\Framework\Assert;

class BatchRequestTest extends BaseTest
{
    public function testEmptyBatchRequest(): void
    {
        // Test for bug COM-214, when empty batch request resulted to "BadRequest: Invalid batch payload format."
        $batch = $this->api->createBatchRequest();
        Assert::assertCount(0, iterator_to_array($batch->execute()));
    }
}
