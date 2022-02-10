<?php

declare(strict_types=1);

namespace Keboola\OneDriveWriter\Api;

use InvalidArgumentException;
use Keboola\OneDriveWriter\Exception\BadRequestException;
use Keboola\OneDriveWriter\Exception\GatewayTimeoutException;
use Normalizer;
use GuzzleHttp\Exception\RequestException;
use Keboola\Component\JsonHelper;
use Keboola\OneDriveWriter\Exception\InvalidFileTypeException;
use Keboola\OneDriveWriter\Exception\ResourceNotFoundException;
use Psr\Http\Message\MessageInterface;

class Helpers
{
    public static function isFilePath(string $str): bool
    {
        // Relative or absolute path
        return preg_match('~^(/?[^/]+)(/[^/]+)*$~ui', $str) === 1;
    }

    public static function isDriveFilePath(string $str): bool
    {
        try {
            self::explodeDriveFilePath($str);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public static function isSiteFilePath(string $str): bool
    {
        try {
            self::explodeSiteFilePath($str);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public static function isHttpsUrl(string $str): bool
    {
        return preg_match('~^https://~', $str) === 1;
    }

    public static function explodeDriveFilePath(string $str): array
    {
        preg_match('~^drive://([^/]+)/(.+)$~', $str, $m);
        if (!$m) {
            throw new InvalidArgumentException('Input not match regexp.');
        }
        $site = urldecode(rtrim($m[1], '/'));
        $path = $m[2];
        return [$site, $path];
    }

    public static function explodeSiteFilePath(string $str): array
    {
        preg_match('~^site://([^/]+)/(.+)$~', $str, $m);
        if (!$m) {
            throw new InvalidArgumentException('Input not match regexp.');
        }
        $site = urldecode(rtrim($m[1], '/'));
        $path = $m[2];
        return [$site, $path];
    }

    public static function processRequestException(RequestException $e): \Throwable
    {
        $error = Helpers::getErrorFromRequestException($e);
        if ($error === 'AccessDenied: Could not obtain a WAC access token.') {
            $msg = 'It looks like the specified file is not in the "XLSX" Excel format. Error: "%s"';
            return new InvalidFileTypeException(sprintf($msg, $error), 0, $e);
        } elseif ($error && strpos($error, 'ItemNotFound:') === 0) {
            $msg = 'The resource could not be found. Uri: "%s"';
            return new ResourceNotFoundException(sprintf($msg, $e->getRequest()->getUri()), 0, $e);
        } elseif ($e->getCode() === 404) {
            // ResourceNotFound, eg. bad fileId, "-1, Microsoft.SharePoint.Client.ResourceNotFoundException"
            return new ResourceNotFoundException(
                'Not found error. Please check configuration. ' .
                'It can be caused by typo in an ID, or resource doesn\'t exists.',
                $e->getCode(),
                $e
            );
        } elseif ($error && strpos($error, 'BadRequest: ') === 0) {
            // eg. BadRequest: Tenant does not have a SPO license.
            return new BadRequestException($error, 0, $e);
        } elseif ($e->getCode() === 400) {
            // BadRequest, eg. bad fileId, "-1, Microsoft.SharePoint.Client.InvalidClientQueryException"
            return new BadRequestException(sprintf(
                'Bad request error. Please check configuration. ' .
                'It can be caused by typo in an ID, or resource doesn\'t exists. ' .
                'API error: %s',
                $error ?: $e->getMessage(),
            ), $e->getCode(), $e);
        } elseif ($e->getCode() === 504) {
            return new GatewayTimeoutException(
                'Gateway Timeout Error. The Microsoft OneDrive API has some problems. ' .
                'Please try again later. API message: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        return $e;
    }

    public static function getErrorFromRequestException(RequestException $exception): ?string
    {
        try {
            /** @var MessageInterface $response */
            $response = $exception->getResponse();
            $stream = $response->getBody();
            $stream->rewind();
            $body = JsonHelper::decode($stream->getContents());
            $error = $body['error'];
            return sprintf('%s: %s', ucfirst($error['code']), $error['message']);
        } catch (\Throwable $jsonException) {
            return null;
        }
    }

    public static function replaceParamsInUri(string $uri, array $params): string
    {
        // Replace params
        foreach ($params as $key => $value) {
            $uri = str_replace("{{$key}}", urlencode((string) $value), $uri);
        }
        return $uri;
    }

    public static function convertPathToApiFormat(string $path): string
    {
        // API use special path format:
        // eg. root path: /me/drive/root/children ... output of this fn is "/"
        // eg. absolute path /me/drive/root:/path/to/folder:/children ... output of this fn is ":/path/to/folder:/"
        $path = trim($path, '/');
        $path = $path ? (":/{$path}:/") : '/';
        return $path;
    }

    public static function toAscii(string $str): string
    {
        $str = Normalizer::normalize($str, Normalizer::FORM_D);
        $str = (string) preg_replace('~\pM~u', '', $str);
        $str = (string) preg_replace('~[^a-zA-Z0-9\-.]+~', '_', $str);
        $str = trim($str, '_');
        return $str;
    }

    public static function truncate(string $value, int $maxLength = 20): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) . '...' : $value;
    }

    public static function formatIterable(iterable $values, int $maxItems = 20, int $strLength = 30): string
    {
        $out = '';
        $i = 0;
        foreach ($values as $value) {
            // Append '...' if there are more items
            if ($i >= $maxItems) {
                $out .= ', ...';
                break;
            }

            // Truncate item length
            $value = self::truncate($value, $strLength);

            $out .= $i === 0 ? "\"{$value}\"" : ", \"{$value}\"";
            $i++;
        }

        if ($i === 0) {
            return '(empty)';
        }

        return $out;
    }

    /**
     * Convert Excel column name to it int position.
     * See https://stackoverflow.com/questions/848147
     * Eg. A => 1, B => 2, AA => 27, ...
     */
    public static function columnStrToInt(string $columnName): int
    {
        if ($columnName === '') {
            throw new InvalidArgumentException('Column name cannot be empty.');
        }

        $columnName = strtoupper($columnName);
        $columnNumber = 0;
        $pow = 1;
        foreach (array_reverse(str_split($columnName)) as $letter) {
            if (!preg_match('~^[A-Z]$~', $letter)) {
                throw new InvalidArgumentException(sprintf('Unexpected letter, expected A-Z, given: "%s"', $letter));
            }
            $columnNumber += (ord($letter) - 65 + 1) * $pow;
            $pow *= 26;
        }

        return $columnNumber;
    }

    /**
     * Convert column position to it Excel column name
     * See https://stackoverflow.com/questions/181596
     * Eg. 1 => A, 2 => B, 26 => AA
     */
    public static function columnIntToStr(int $columnNumber): string
    {
        if ($columnNumber <= 0) {
            throw new InvalidArgumentException('Column must be greater than zero.');
        }

        $dividend = $columnNumber;
        $columnName = '';
        while ($dividend > 0) {
            $modulo = ($dividend - 1) % 26;
            $columnName = chr(65 + $modulo) . $columnName;
            $dividend = (int) (($dividend - $modulo) / 26);
        }

        return $columnName;
    }

    /**
     * Escape Excel expressions with "'", apostrophe, char code 39.
     * Expression starts with "=".
     * Example: "=foo-bar" is converted to "'=foo-bar"
     */
    public static function escapeExcelExpressions(array &$values): void
    {
        array_walk_recursive($values, function (&$value): void {
            // Escape expression, eg. "=foo-bar" with "'", eg. "'=foo-bar"
            if (is_string($value) && isset($value[0]) && $value[0] === '=') {
                $value = "'" . $value;
            }
        });
    }
}
