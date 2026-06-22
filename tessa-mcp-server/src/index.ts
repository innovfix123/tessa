import "dotenv/config";
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import { tools, toolByName } from "./tools/index.js";
import { TessaApiError } from "./client.js";

const server = new Server(
  {
    name: "tessa-mcp-server",
    version: "0.1.0",
  },
  {
    capabilities: {
      tools: {},
    },
  },
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: tools.map((t) => ({
    name: t.name,
    description: t.description,
    inputSchema: t.inputSchema,
  })),
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  const tool = toolByName.get(name);
  if (!tool) {
    return {
      isError: true,
      content: [{ type: "text", text: `Unknown tool: ${name}` }],
    };
  }

  try {
    const result = await tool.handler(args ?? {});
    return {
      content: [
        {
          type: "text",
          text: typeof result === "string" ? result : JSON.stringify(result, null, 2),
        },
      ],
    };
  } catch (err) {
    if (err instanceof TessaApiError) {
      return {
        isError: true,
        content: [
          {
            type: "text",
            text: `Tessa API ${err.status} on ${err.path}\n${JSON.stringify(err.body, null, 2)}`,
          },
        ],
      };
    }
    const message = err instanceof Error ? err.message : String(err);
    return {
      isError: true,
      content: [{ type: "text", text: `Tool error: ${message}` }],
    };
  }
});

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  // stdio mode — log to stderr only (stdout is the MCP channel)
  process.stderr.write("tessa-mcp-server ready (stdio)\n");
}

main().catch((err) => {
  process.stderr.write(`tessa-mcp-server failed to start: ${err}\n`);
  process.exit(1);
});
