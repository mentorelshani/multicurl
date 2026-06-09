<?php
declare(strict_types = 1);

namespace Maurice\Multicurl\Tests;

use InvalidArgumentException;
use Maurice\Multicurl\Mcp\JsonObject;
use Maurice\Multicurl\Mcp\RpcMessage;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests the public behavior of RpcMessage.
 */
class RpcMessageTest extends TestCase
{
    public function testRequestCreatesExpectedMessage(): void
    {
        $message = RpcMessage::request('tools/list', ['foo' => 'bar'], 'req-1');

        $this->assertTrue($message->isRequest());
        $this->assertSame('tools/list', $message->getMethod());
        $this->assertSame(['foo' => 'bar'], $message->getParams());
        $this->assertSame('req-1', $message->getId());
    }

    public function testResponseCreatesExpectedMessage(): void
    {
        $message = RpcMessage::response(['capabilities' => []], '1');

        $this->assertTrue($message->isResponse());
        $this->assertSame(['capabilities' => []], $message->getResult());
        $this->assertSame('1', $message->getId());
    }

    public function testErrorCreatesExpectedMessage(): void
    {
        $message = RpcMessage::error(-32603, 'Internal error', ['detail' => 'x'], '9');

        $this->assertTrue($message->isError());
        $this->assertSame(-32603, $message->getErrorCode());
        $this->assertSame('Internal error', $message->getErrorMessage());
        $this->assertInstanceOf(JsonObject::class, $message->getError());
        $this->assertSame(['detail' => 'x'], $message->getError()['data']);
        $this->assertSame('9', $message->getId());
    }

    public function testToolsListRequestUsesEmptyObjectParams(): void
    {
        $message = RpcMessage::toolsListRequest();

        $this->assertTrue($message->isRequest());
        $this->assertSame('tools/list', $message->getMethod());
        $this->assertInstanceOf(stdClass::class, $message->getParams());

        $payload = json_decode($message->toJson(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload->params);
    }

    public function testToolsListRequestAcceptsCustomParams(): void
    {
        $params = [
            '_meta' => [
                'channel' => 'phone',
            ],
            'cursor' => 'next-page',
        ];

        $message = RpcMessage::toolsListRequest(params: $params);

        $this->assertSame($params, $message->getParams());
    }

    public function testPromptsListRequestAcceptsCustomParams(): void
    {
        $params = [
            '_meta' => [
                'channel' => 'phone',
            ],
            'cursor' => 'next-page',
        ];

        $message = RpcMessage::promptsListRequest(params: $params);

        $this->assertTrue($message->isRequest());
        $this->assertSame('prompts/list', $message->getMethod());
        $this->assertSame($params, $message->getParams());
    }

    public function testToolsCallRequestBuildsExpectedParams(): void
    {
        $message = RpcMessage::toolsCallRequest('my_tool', ['a' => 1], ['type' => 'object']);

        $this->assertSame('tools/call', $message->getMethod());
        $this->assertSame(
            [
                'name' => 'my_tool',
                'arguments' => ['a' => 1],
                'outputSchema' => ['type' => 'object'],
            ],
            $message->getParams()
        );
    }

    public function testToolsCallRequestOmitsOutputSchemaWhenNotProvided(): void
    {
        $message = RpcMessage::toolsCallRequest('my_tool', ['a' => 1]);

        $this->assertArrayNotHasKey('outputSchema', $message->getParams());
    }

    public function testToolsCallRequestUsesEmptyObjectArgumentsWhenNotProvided(): void
    {
        $message = RpcMessage::toolsCallRequest('my_tool');

        $this->assertInstanceOf(stdClass::class, $message->getParams()['arguments']);

        $payload = json_decode($message->toJson(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('my_tool', $payload->params->name);
        $this->assertInstanceOf(stdClass::class, $payload->params->arguments);
    }

    public function testInitializeRequestUsesDefaultClientInfoAndEmptyCapabilitiesObject(): void
    {
        $message = RpcMessage::initializeRequest();
        $params = $message->getParams();

        $this->assertSame('initialize', $message->getMethod());
        $this->assertSame('2025-06-18', $params['protocolVersion']);
        $this->assertSame(
            [
                'name' => 'maurice2k/multicurl MCP Client',
                'version' => '1.0.0',
            ],
            $params['clientInfo']
        );
        $this->assertInstanceOf(stdClass::class, $params['capabilities']);
        $this->assertStringContainsString('"capabilities":{}', $message->toJson());
    }

    public function testInitializeRequestKeepsProvidedClientInfoAndCapabilities(): void
    {
        $clientInfo = [
            'name' => 'Test Client',
            'version' => '1.2.3',
        ];
        $capabilities = [
            'tools' => ['listChanged' => true],
            'roots' => ['listChanged' => false],
        ];

        $message = RpcMessage::initializeRequest('2025-06-18', $clientInfo, $capabilities);

        $this->assertSame($clientInfo, $message->getParams()['clientInfo']);
        $this->assertSame($capabilities, $message->getParams()['capabilities']);
    }

    public function testFromJsonRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        RpcMessage::fromJson('{');
    }

    public function testFromJsonRejectsTopLevelArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC message');

        RpcMessage::fromJson('[{"jsonrpc":"2.0","method":"tools/list","id":"1"}]');
    }

    public function testFromJsonParsesRequestMessage(): void
    {
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","method":"tools/list","id":"1","params":{"foo":"bar"}}'
        );

        $this->assertTrue($message->isRequest());
        $this->assertSame('tools/list', $message->getMethod());
        $this->assertSame('1', $message->getId());
        $this->assertInstanceOf(JsonObject::class, $message->getParams());
        $this->assertSame('bar', $message->getParams()['foo']);
    }

    public function testFromJsonPreservesResultJsonShape(): void
    {
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","id":"1","result":{"inputSchema":{"type":"object","properties":{},"required":[]}}}'
        );

        $result = $message->getResult();
        $this->assertInstanceOf(JsonObject::class, $result);
        $this->assertInstanceOf(JsonObject::class, $result['inputSchema']);
        $this->assertInstanceOf(JsonObject::class, $result['inputSchema']['properties']);
        $this->assertIsArray($result['inputSchema']['required']);
    }

    public function testParsedResultSupportsArrayAndObjectAccess(): void
    {
        // Backward-compatibility guarantee: parsed objects behave like the
        // pre-3.0 associative arrays (array access, isset, foreach, count)
        // while also supporting object access.
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","id":"1","result":{"inputSchema":{"type":"object","properties":{}}}}'
        );

        $result = $message->getResult();
        $this->assertInstanceOf(JsonObject::class, $result);

        // array access (the canonical, pre-3.0 compatible API)
        $this->assertSame('object', $result['inputSchema']['type']);
        $this->assertTrue(isset($result['inputSchema']));
        $this->assertFalse(isset($result['missing']));
        $this->assertCount(1, $result);

        $keys = [];
        foreach ($result as $key => $value) {
            $keys[] = $key;
        }
        $this->assertSame(['inputSchema'], $keys);

        // object access also works at runtime (PR style)
        /** @phpstan-ignore property.notFound */
        $this->assertSame('object', $result->inputSchema->type);
    }

    public function testFromJsonPreservesParamsJsonShape(): void
    {
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","method":"tools/call","id":"1","params":{"name":"test","arguments":{},"tags":[]}}'
        );

        $params = $message->getParams();
        $this->assertInstanceOf(JsonObject::class, $params);
        $this->assertSame('test', $params['name']);
        $this->assertInstanceOf(JsonObject::class, $params['arguments']);
        $this->assertIsArray($params['tags']);
    }

    public function testFromJsonPreservesTopLevelParamsAndResultArrays(): void
    {
        $request = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","method":"notifications/progress","params":[]}'
        );
        $response = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","id":"1","result":[]}'
        );

        $this->assertSame([], $request->getParams());
        $this->assertSame([], $response->getResult());
    }

    public function testFromJsonPreservesErrorJsonShape(): void
    {
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","id":"1","error":{"code":-32602,"message":"Invalid params","data":{"details":{},"items":[]}}}'
        );

        $this->assertTrue($message->isError());
        $this->assertSame(-32602, $message->getErrorCode());
        $this->assertSame('Invalid params', $message->getErrorMessage());

        $error = $message->getError();
        $this->assertInstanceOf(JsonObject::class, $error);
        $this->assertSame(-32602, $error['code']);
        $this->assertSame('Invalid params', $error['message']);
        $this->assertInstanceOf(JsonObject::class, $error['data']);
        $this->assertInstanceOf(JsonObject::class, $error['data']['details']);
        $this->assertIsArray($error['data']['items']);
    }

    public function testFromJsonPreservesNumericObjectKeysAsObject(): void
    {
        $message = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","id":"1","result":{"map":{"0":"x"},"list":["x"]}}'
        );

        $result = $message->getResult();
        $this->assertInstanceOf(JsonObject::class, $result->map);
        $this->assertSame(['0' => 'x'], $result->map->toArray());
        $this->assertIsArray($result->list);

        $roundTripped = json_decode($message->toJson(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $roundTripped->result->map);
        $this->assertIsArray($roundTripped->result->list);
    }

    public function testFromJsonPreservesNumericObjectKeysInParamsAndErrorData(): void
    {
        $request = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","method":"tools/call","id":"1","params":{"map":{"0":"x"},"list":["x"]}}'
        );
        $params = $request->getParams();

        $this->assertInstanceOf(JsonObject::class, $params->map);
        $this->assertSame(['0' => 'x'], $params->map->toArray());
        $this->assertIsArray($params->list);

        $errorResponse = RpcMessage::fromJson(
            '{"jsonrpc":"2.0","id":"1","error":{"code":-32602,"message":"Invalid params","data":{"map":{"0":"x"},"list":["x"]}}}'
        );
        $error = $errorResponse->getError();

        $this->assertInstanceOf(JsonObject::class, $error->data->map);
        $this->assertSame(['0' => 'x'], $error->data->map->toArray());
        $this->assertIsArray($error->data->list);
    }

    public function testToolsListInputSchemaRoundTripsWithEmptyPropertiesObject(): void
    {
        $json = '{"jsonrpc":"2.0","id":"1","result":{"tools":[{"name":"report","inputSchema":{"type":"object","properties":{"datapoints":{"type":"object","properties":{}}},"required":[]}}]}}';

        $message = RpcMessage::fromJson($json);
        $result = $message->getResult();

        $this->assertInstanceOf(JsonObject::class, $result->tools[0]->inputSchema->properties->datapoints->properties);

        $encoded = $message->toJson();
        $roundTripped = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(stdClass::class, $roundTripped->result->tools[0]->inputSchema->properties->datapoints->properties);
        $this->assertSame([], $roundTripped->result->tools[0]->inputSchema->required);
    }

    public function testFromJsonRejectsNullMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('method');

        RpcMessage::fromJson('{"jsonrpc":"2.0","method":null,"id":"1"}');
    }

    public function testFromJsonRejectsScalarParams(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('params');

        RpcMessage::fromJson('{"jsonrpc":"2.0","method":"tools/list","id":"1","params":"bad"}');
    }

    public function testFromJsonRejectsResponseWithResultAndError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response');

        RpcMessage::fromJson('{"jsonrpc":"2.0","id":"1","result":{},"error":{"code":-32603,"message":"bad"}}');
    }

    public function testFromJsonRejectsSuccessResponseWithoutId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response');

        RpcMessage::fromJson('{"jsonrpc":"2.0","result":{}}');
    }

    public function testFromJsonRejectsInvalidErrorShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('error');

        RpcMessage::fromJson('{"jsonrpc":"2.0","id":"1","error":null}');
    }

    public function testFromJsonRejectsInvalidIdShape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('id');

        RpcMessage::fromJson('{"jsonrpc":"2.0","method":"tools/list","id":{}}');
    }

    public function testFromDecodedJsonRejectsMissingJsonRpcVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC version');

        RpcMessage::fromDecodedJson(new stdClass());
    }

    public function testFromDecodedJsonRejectsWrongJsonRpcVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON-RPC version');

        RpcMessage::fromDecodedJson($this->decodeObject('{"jsonrpc":"1.0","id":1,"result":null}'));
    }

    public function testFromDecodedJsonParsesNotificationMessage(): void
    {
        $message = RpcMessage::fromDecodedJson(
            $this->decodeObject('{"jsonrpc":"2.0","method":"notifications/initialized","params":{"status":"ready"}}')
        );

        $this->assertTrue($message->isNotification());
        $this->assertSame('notifications/initialized', $message->getMethod());
        $this->assertNull($message->getId());
        $this->assertInstanceOf(JsonObject::class, $message->getParams());
        $this->assertSame('ready', $message->getParams()['status']);
    }

    public function testFromDecodedJsonParsesResponseMessage(): void
    {
        $message = RpcMessage::fromDecodedJson(
            $this->decodeObject('{"jsonrpc":"2.0","id":2,"result":{"x":1}}')
        );

        $this->assertTrue($message->isResponse());
        $this->assertSame(2, $message->getId());
        $this->assertInstanceOf(JsonObject::class, $message->getResult());
        $this->assertSame(1, $message->getResult()['x']);
    }

    public function testFromDecodedJsonParsesErrorMessage(): void
    {
        $message = RpcMessage::fromDecodedJson(
            $this->decodeObject('{"jsonrpc":"2.0","id":3,"error":{"code":-32600,"message":"Invalid Request"}}')
        );

        $this->assertTrue($message->isError());
        $this->assertSame(3, $message->getId());
        $this->assertSame(-32600, $message->getErrorCode());
        $this->assertSame('Invalid Request', $message->getErrorMessage());
    }

    private function decodeObject(string $json): stdClass
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $decoded);

        return $decoded;
    }
}
