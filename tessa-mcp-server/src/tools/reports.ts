import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const myKrasInput = z.object({}).strict();

const listDailyReportsInput = z.object({
  from: z.string().describe("ISO date").optional(),
  to: z.string().describe("ISO date").optional(),
  user_id: z.number().int().optional(),
  limit: z.number().int().min(1).max(200).default(50),
});

export const reportTools: Tool[] = [
  {
    name: "list_my_kras",
    description: "Fetch the signed-in user's KRAs/scorecard.",
    inputSchema: zodToJsonSchema(myKrasInput) as Record<string, unknown>,
    handler: async () => tessa.get("/my-kras"),
  },
  {
    name: "list_daily_reports",
    description: "List daily reports (signoff/signin reports). Filter by date or user.",
    inputSchema: zodToJsonSchema(listDailyReportsInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = listDailyReportsInput.parse(raw);
      return tessa.get("/daily-reports", { query: input });
    },
  },
];
