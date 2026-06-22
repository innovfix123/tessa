import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const tessaRequestInput = z
  .object({
    method: z.enum(["GET", "POST", "PUT", "PATCH", "DELETE"]),
    path: z
      .string()
      .startsWith("/", "Path must start with '/' (do not include /api/mcp)")
      .describe(
        "Tessa API path AFTER the /api/mcp prefix, e.g. '/tessa/tasks' or '/sprints/12/board'.",
      ),
    query: z.record(z.unknown()).optional(),
    body: z.unknown().optional(),
  })
  .strict();

export const escapeTools: Tool[] = [
  {
    name: "tessa_request",
    description:
      "Generic escape hatch for any Tessa API endpoint not covered by a typed tool. " +
      "Issues an authenticated HTTPS request to https://tessa.innovfix.ai/api/mcp{path}. " +
      "Writes are NOT sandboxed — POST/PUT/PATCH/DELETE will mutate real data. " +
      "Always prefer a typed tool when one exists.",
    inputSchema: zodToJsonSchema(tessaRequestInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = tessaRequestInput.parse(raw);
      switch (input.method) {
        case "GET":
          return tessa.get(input.path, { query: input.query });
        case "POST":
          return tessa.post(input.path, { query: input.query, body: input.body });
        case "PUT":
          return tessa.put(input.path, { query: input.query, body: input.body });
        case "PATCH":
          return tessa.patch(input.path, { query: input.query, body: input.body });
        case "DELETE":
          return tessa.delete(input.path, { query: input.query, body: input.body });
      }
    },
  },
];
