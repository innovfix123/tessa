import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const listSquadsInput = z.object({}).strict();

const getSprintBoardInput = z.object({
  sprint_id: z.number().int(),
});

export const agileTools: Tool[] = [
  {
    name: "list_squads",
    description: "List all agile squads (teams) in Tessa.",
    inputSchema: zodToJsonSchema(listSquadsInput) as Record<string, unknown>,
    handler: async () => tessa.get("/squads"),
  },
  {
    name: "get_sprint_board",
    description: "Fetch the kanban board for a sprint (lanes + cards).",
    inputSchema: zodToJsonSchema(getSprintBoardInput) as Record<string, unknown>,
    handler: async (raw) => {
      const { sprint_id } = getSprintBoardInput.parse(raw);
      return tessa.get(`/sprints/${sprint_id}/board`);
    },
  },
];
