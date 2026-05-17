<?php
declare(strict_types=1);

namespace Maurice\Multicurl\Tests;

use Maurice\Multicurl\Channel;
use Maurice\Multicurl\Manager;
use Maurice\Multicurl\SseChannel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
class StreamLimitIntegrationTest extends TestCase
{
    public function testStreamBufferLimitReportsErrorThroughManager(): void
    {
        $tmpFile = $this->createTempFile(str_repeat('x', 65536));

        try {
            $manager = new Manager(1);
            $channel = new Channel();
            $onReadyCalled = false;
            $errorMessage = null;
            $errorCode = null;

            $channel->setCurlOption(CURLOPT_URL, 'file://' . $tmpFile);
            $channel->setStreamable(true);
            $channel->setMaxStreamBufferSize(1024);
            $channel->setOnReadyCallback(function () use (&$onReadyCalled): void {
                $onReadyCalled = true;
            });
            $channel->setOnErrorCallback(function (Channel $channel, string $message, int $errno) use (&$errorMessage, &$errorCode): void {
                $errorMessage = $message;
                $errorCode = $errno;
            });

            $manager->addChannel($channel);
            $manager->run();

            $this->assertFalse($onReadyCalled);
            $this->assertSame(CURLE_WRITE_ERROR, $errorCode);
            $this->assertSame('Stream buffer exceeded maximum size of 1024 bytes', $errorMessage);
            $this->assertTrue($channel->isStreamAbortedByError());
            $this->assertNull($channel->getCurlHandle());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testHttpStreamBufferLimitReportsErrorThroughManager(): void
    {
        $baseUrl = $this->getAvailableHttpBaseUrl();
        $manager = new Manager(1);
        $channel = new Channel();
        $onReadyCalled = false;
        $errorMessage = null;
        $errorCode = null;

        $channel->setCurlOption(CURLOPT_URL, $baseUrl . '/bytes/65536');
        $channel->setStreamable(true);
        $channel->setMaxStreamBufferSize(1024);
        $channel->setOnReadyCallback(function () use (&$onReadyCalled): void {
            $onReadyCalled = true;
        });
        $channel->setOnErrorCallback(function (Channel $channel, string $message, int $errno) use (&$errorMessage, &$errorCode): void {
            $errorMessage = $message;
            $errorCode = $errno;
        });

        $manager->addChannel($channel);
        $manager->run();

        $this->assertFalse($onReadyCalled);
        $this->assertSame(CURLE_WRITE_ERROR, $errorCode);
        $this->assertSame('Stream buffer exceeded maximum size of 1024 bytes', $errorMessage);
        $this->assertTrue($channel->isStreamAbortedByError());
        $this->assertNull($channel->getCurlHandle());
    }

    public function testSseEventLimitReportsErrorThroughManager(): void
    {
        $tmpFile = $this->createTempFile('data: abcdef' . "\n");

        try {
            $manager = new Manager(1);
            $channel = new SseChannel('file://' . $tmpFile);
            $onReadyCalled = false;
            $errorMessage = null;
            $errorCode = null;

            $channel->setMaxSseEventSize(5);
            $channel->setOnReadyCallback(function () use (&$onReadyCalled): void {
                $onReadyCalled = true;
            });
            $channel->setOnErrorCallback(function (SseChannel $channel, string $message, int $errno) use (&$errorMessage, &$errorCode): void {
                $errorMessage = $message;
                $errorCode = $errno;
            });

            $manager->addChannel($channel);
            $manager->run();

            $this->assertFalse($onReadyCalled);
            $this->assertSame(CURLE_WRITE_ERROR, $errorCode);
            $this->assertSame('SSE event exceeded maximum size of 5 bytes', $errorMessage);
            $this->assertTrue($channel->isStreamAbortedByError());
            $this->assertNull($channel->getCurlHandle());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testHttpSseEventLimitReportsErrorThroughManager(): void
    {
        $baseUrl = $this->getAvailableHttpBaseUrl();
        $manager = new Manager(1);
        $channel = new SseChannel($baseUrl . '/sse?count=1');
        $onReadyCalled = false;
        $errorMessage = null;
        $errorCode = null;

        $channel->setMaxSseEventSize(1);
        $channel->setOnReadyCallback(function () use (&$onReadyCalled): void {
            $onReadyCalled = true;
        });
        $channel->setOnErrorCallback(function (SseChannel $channel, string $message, int $errno) use (&$errorMessage, &$errorCode): void {
            $errorMessage = $message;
            $errorCode = $errno;
        });

        $manager->addChannel($channel);
        $manager->run();

        $this->assertFalse($onReadyCalled);
        $this->assertSame(CURLE_WRITE_ERROR, $errorCode);
        $this->assertSame('SSE event exceeded maximum size of 1 bytes', $errorMessage);
        $this->assertTrue($channel->isStreamAbortedByError());
        $this->assertNull($channel->getCurlHandle());
    }

    private function createTempFile(string $content): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'multicurl-stream-limit-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        file_put_contents($tmpFile, $content);

        return $tmpFile;
    }

    private function getAvailableHttpBaseUrl(): string
    {
        $baseUrl = 'http://' . ($_ENV['TEST_HTTP_SERVER'] ?? 'localhost:8080');
        $ch = curl_init($baseUrl . '/status/200');
        if ($ch === false) {
            $this->markTestSkipped('Failed to initialize curl for httpbin availability check');
        }

        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            $this->markTestSkipped('httpbin service not available at ' . $baseUrl);
        }

        return $baseUrl;
    }
}
