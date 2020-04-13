<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Keboola\OneDriveWriter\Fixtures\FixturesCatalog;
use \Keboola\OneDriveWriter\Fixtures\FixturesUtils;

FixturesCatalog::initialize();
FixturesUtils::disableLog();
