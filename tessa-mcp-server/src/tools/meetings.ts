import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const listMeetingsInput = z.object({
  from: z.string().describe("ISO date, e.g. 2026-05-01").optional(),
  to: z.string().describe("ISO date").optional(),
  scope: z.enum(["mine", "team", "all"]).optional(),
  limit: z.number().int().min(1).max(200).default(50),
});

const listActionItemsInput = z.object({
  meeting_id: z.number().int().optional(),
  assignee_id: z.number().int().optional(),
  status: z.enum(["open", "done", "cancelled"]).optional(),
  limit: z.number().int().min(1).max(200).default(50),
});

export const meetingTools: Tool[] = [
  {
    name: "list_meetings",
    description: "List meetings (filter by date range, scope).",
    inputSchema: zodToJsonSchema(listMeetingsInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = listMeetingsInput.parse(raw);
      return tessa.get("/meetings", { query: input });
    },
  },
  {
    name: "list_action_items",
    description: "List action items from meetings.",
    inputSchema: zodToJsonSchema(listActionItemsInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = listActionItemsInput.parse(raw);
      return tessa.get("/action-items", { query: input });
    },
  },
];
