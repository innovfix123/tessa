import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { tessa } from "../client.js";
import type { Tool } from "./types.js";

const listEmployeesInput = z.object({
  department: z.string().optional(),
  designation: z.string().optional(),
  search: z.string().optional(),
  limit: z.number().int().min(1).max(500).default(100),
});

const listLeaveRequestsInput = z.object({
  status: z.enum(["pending", "approved", "rejected", "cancelled"]).optional(),
  user_id: z.number().int().optional(),
  type: z.string().optional(),
  from: z.string().optional(),
  to: z.string().optional(),
  limit: z.number().int().min(1).max(200).default(50),
});

export const hrTools: Tool[] = [
  {
    name: "list_employees",
    description: "List employees in Tessa.",
    inputSchema: zodToJsonSchema(listEmployeesInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = listEmployeesInput.parse(raw);
      return tessa.get("/employees", { query: input });
    },
  },
  {
    name: "list_leave_requests",
    description: "List leave requests. Filter by status, user, type, or date range.",
    inputSchema: zodToJsonSchema(listLeaveRequestsInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = listLeaveRequestsInput.parse(raw);
      return tessa.get("/leave/requests", { query: input });
    },
  },
];
