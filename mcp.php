<?php

/**
 * MCP (Model Context Protocol) server: exposes this account's Trakt data —
 * full watch history with real dates/times, top shows/movies, genres,
 * ratings, watchlist, the insight widgets, and now/previously-watching —
 * as tools that any MCP-compatible AI client can call. MCP is a shared,
 * model-agnostic standard (not an Anthropic-only thing), so the same
 * endpoint works for Claude, ChatGPT/OpenAI, or any other client speaking
 * it — point your client at this file's URL.
 *
 * Read-only: every tool just reads existing data, nothing here can modify
 * anything. Gated behind a bearer API key set as 'mcp_api_key' in
 * config.php — leave that blank (the default) to disable this endpoint
 * entirely (it 404s). Set your MCP client's Authorization header to
 * "Bearer <your mcp_api_key>".
 *
 * Implements the request/response subset of MCP's Streamable HTTP
 * transport: a single POST endpoint handling initialize / tools/list /
 * tools/call, responding with a plain JSON body. Not implemented: the
 * optional SSE stream for server-initiated pushes, which a read-only tool
 * server like this one never needs to send anyway, and MCP's optional
 * session-ID mechanism, since every call here is independently
 * authenticated and stateless.
 *
 * See lib/Mcp.php for the actual tool definitions and logic.
 */

if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require __DIR__ . '/lib/Mcp.php';

header('Content-Type: application/json');

function mcpSend($id, $result, ?array $error = null, int $httpStatus = 200): void
{
    http_response_code($httpStatus);
    $response = ['jsonrpc' => '2.0', 'id' => $id];
    $response[$error !== null ? 'error' : 'result'] = $error ?? $result;
    echo json_encode($response);
    exit;
}

$config = App::loadConfig();
if (App::needsSetup($config)) {
    mcpSend(null, null, ['code' => -32000, 'message' => 'missing or incomplete config.php'], 500);
}

$apiKey = $config['mcp_api_key'];

if ($apiKey === '') {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed — POST a JSON-RPC 2.0 request']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
$provided = '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    $provided = trim($m[1]);
}

if ($provided === '' || !hash_equals($apiKey, $provided)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$request = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($request) || ($request['jsonrpc'] ?? null) !== '2.0' || !isset($request['method'])) {
    mcpSend(null, null, ['code' => -32600, 'message' => 'Invalid JSON-RPC 2.0 request'], 400);
}

$id = $request['id'] ?? null;
$method = $request['method'];
$params = is_array($request['params'] ?? null) ? $request['params'] : [];
$isNotification = !array_key_exists('id', $request);

$mcp = new Mcp(App::boot($config));

switch ($method) {
    case 'initialize':
        $result = $mcp->initialize();
        break;

    case 'notifications/initialized':
        // Client acknowledging the handshake — no response body for a
        // notification (no 'id'), per JSON-RPC 2.0.
        http_response_code(202);
        exit;

    case 'ping':
        $result = new stdClass();
        break;

    case 'tools/list':
        $result = ['tools' => $mcp->listTools()];
        break;

    case 'tools/call':
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $result = $mcp->callTool($name, $arguments);
        break;

    default:
        mcpSend($id, null, ['code' => -32601, 'message' => 'Method not found: ' . $method], 404);
        exit;
}

if ($isNotification) {
    http_response_code(202);
    exit;
}

mcpSend($id, $result);
