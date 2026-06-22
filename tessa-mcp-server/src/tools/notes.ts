import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const listNotesInput = z.object({}).strict();

export const noteTools: Tool[] = [
  {
    name: "list_dashboard_notes",
    description: "List the signed-in user's dashboard notes (sticky notes pinned to the portal home).",
    inputSchema: zodToJsonSchema(listNotesInput) as Record<string, unknown>,
    handler: async () => tessa.get("/notes"),
  },
];
