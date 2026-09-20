# Example: define and call a tool end-to-end.
#
# 1. This YAML (config/tools/echo.yaml) defines the tool:
#
#    name: echo
#    description: Echo back the provided message, uppercased.
#    handler: App\Tool\Echo\EchoTool::echo
#    parameters:
#      message:
#        type: string
#        description: The text to echo.
#        required: true
#
# 2. The handler (src/Tool/Echo/EchoTool.php):
#
#    final class EchoTool
#    {
#        public function echo(string $message): array
#        {
#            return ['echo' => strtoupper($message)];
#        }
#    }
#
# 3. Register the service publicly (config/services.yaml):
#
#    App\Tool\Echo\EchoTool:
#        public: true
#
# 4. Call it via MCP:
#
#    curl -X POST http://localhost:8000/mcp \
#      -H 'Content-Type: application/json' \
#      -H 'Accept: application/json, text/event-stream' \
#      -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{
#            "name":"echo","arguments":{"message":"hello shuttle"}}}'
#
#    → {"jsonrpc":"2.0","id":1,"result":{"content":[
#        {"type":"text","text":"{\"echo\":\"HELLO SHUTTLE\"}"}],"isError":false}}
#
# 5. Or via REST:
#
#    curl -X POST http://localhost:8000/tools/echo \
#      -H 'Content-Type: application/json' \
#      -d '{"message":"hello shuttle"}'
#
#    → {"tool":"echo","result":{"echo":"HELLO SHUTTLE"}}
#
# 6. Inspect the OpenAPI spec it generated:
#
#    curl http://localhost:8000/openapi.json | jq '.paths."/tools/echo"'
#
# 7. Every invocation was logged as one JSON line (channel mcp_invocation).