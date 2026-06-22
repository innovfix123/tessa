import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const listTasksInput = z.object({
  status: z.enum(["pending", "in_progress", "completed", "blocked", "cancelled"]).optional(),
  assignee_id: z.number().int().optional(),
  squad_id: z.number().int().optional(),
  due_before: z.string().describe("ISO 8601 datetime").optional(),
  due_after: z.string().describe("ISO 8601 datetime").optional(),
  limit: z.number().int().min(1).max(200).default(50),
});

const getTaskInput = z.object({
  task_id: z.number().int(),
});

export const taskTools: Tool[] = [
  {
    name: "list_tasks",
    description: "List Tessa tasks. Filter by status, assignee, squad, or due window.",
    inputSchema: zodToJsonSchema(listTasksInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = listTasksInput.parse(raw);
      return tessa.get("/tessa/tasks", { query: input });
    },
  },
  {
    name: "get_task",
    description: "Fetch a single Tessa task by id, including subtasks/blockers if returned by the API.",
    inputSchema: zodToJsonSchema(getTaskInput) as Record<string, unknown>,
    handler: async (raw) => {
      const { task_id } = getTaskInput.parse(raw);
      return tessa.get(`/tessa/tasks/${task_id}`);
    },
  },
];
