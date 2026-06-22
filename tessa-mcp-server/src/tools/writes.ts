import { z } from "zod";
import { zodToJsonSchema } from "zod-to-json-schema";
import { getMe, tessa } from "../client.js";
import type { Tool } from "./types.js";

// ─── meeting notes ────────────────────────────────────────────────────────

const saveMeetingNoteInput = z
  .object({
    meeting_id: z.string().describe("Meeting id (or meetingKey) — find via list_meetings."),
    week_key: z
      .string()
      .regex(/^\d{4}-W\d{2}$/, "weekKey format is YYYY-W## (ISO week), e.g. 2026-W19")
      .describe("ISO week key like 2026-W19. Falls back to current week if omitted via the helper."),
    content: z.string().describe("Markdown / plain-text note body. Replaces existing content for that week."),
  })
  .strict();

// ─── daily reports ────────────────────────────────────────────────────────

const updateDailyReportInput = z
  .object({
    report_date: z
      .string()
      .regex(/^\d{4}-\d{2}-\d{2}$/, "report_date must be YYYY-MM-DD")
      .describe("Date the value applies to (YYYY-MM-DD)."),
    field_key: z
      .string()
      .describe(
        "KPI field key for this user. Discover via list_daily_reports → fields[].key. " +
          "Examples: 'tasks_done', 'overtime_hours', 'meta_ad_spend'.",
      ),
    value: z
      .union([z.string(), z.number(), z.boolean(), z.null()])
      .describe("Field value — usually a number or short string."),
    user_id: z
      .number()
      .int()
      .optional()
      .describe("User id to update; defaults to the signed-in user."),
  })
  .strict();

// ─── tasks ────────────────────────────────────────────────────────────────

const createTaskInput = z
  .object({
    title: z.string().min(1).max(255),
    assigned_to: z.number().int().describe("User id of assignee. Use list_employees if unknown."),
    description: z.string().optional(),
    priority: z.enum(["low", "medium", "high", "urgent"]).default("medium"),
    deadline: z
      .string()
      .optional()
      .describe("Deadline as 'YYYY-MM-DD' or full ISO 8601 datetime."),
  })
  .strict();

const updateTaskInput = z
  .object({
    task_id: z.number().int(),
    status: z
      .enum(["pending", "in_progress", "completed", "closed", "cancelled", "on_hold"])
      .optional(),
    title: z.string().optional(),
    description: z.string().optional(),
    priority: z.enum(["low", "medium", "high", "urgent"]).optional(),
    deadline: z.string().optional(),
    status_note: z.string().optional(),
  })
  .strict();

// ─── dashboard notes ──────────────────────────────────────────────────────

const createNoteInput = z
  .object({
    title: z.string().max(200).optional(),
    body: z.string().max(5000).optional(),
    items: z
      .array(
        z.object({
          text: z.string().max(500),
          checked: z.boolean().optional(),
        }),
      )
      .optional()
      .describe("Checklist items, if you want a checklist note instead of a text body."),
    is_pinned: z.boolean().optional(),
    reminder_interval: z.enum(["10", "15", "30", "45", "60"]).optional(),
    reminder_at: z.string().optional().describe("One-shot reminder datetime (Asia/Kolkata)."),
  })
  .strict();

// ─── whoami ───────────────────────────────────────────────────────────────

const whoamiInput = z.object({}).strict();

export const writeTools: Tool[] = [
  {
    name: "whoami",
    description:
      "Return the signed-in Tessa user (id, name, email, role). " +
      "Useful for resolving the current user's id before passing to other tools.",
    inputSchema: zodToJsonSchema(whoamiInput) as Record<string, unknown>,
    handler: async () => getMe(),
  },
  {
    name: "save_meeting_note",
    description:
      "Save (create-or-replace) the note for a meeting for a given week. " +
      "meeting_id comes from list_meetings (use the `id` or `meetingKey` field). " +
      "week_key is the ISO week, e.g. 2026-W19.",
    inputSchema: zodToJsonSchema(saveMeetingNoteInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = saveMeetingNoteInput.parse(raw);
      return tessa.post("/meeting-notes", {
        body: {
          action: "save",
          meetingId: input.meeting_id,
          weekKey: input.week_key,
          content: input.content,
        },
      });
    },
  },
  {
    name: "update_daily_report",
    description:
      "Set a single field on a daily report for a date. " +
      "If user_id is omitted, defaults to the signed-in user. " +
      "Discover valid field_keys with list_daily_reports first.",
    inputSchema: zodToJsonSchema(updateDailyReportInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = updateDailyReportInput.parse(raw);
      const userId = input.user_id ?? (await getMe()).id;
      return tessa.post("/daily-reports", {
        body: {
          action: "save_entry",
          userId,
          reportDate: input.report_date,
          fieldKey: input.field_key,
          value: input.value,
        },
      });
    },
  },
  {
    name: "create_task",
    description: "Create a Tessa task assigned to a teammate.",
    inputSchema: zodToJsonSchema(createTaskInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = createTaskInput.parse(raw);
      return tessa.post("/tessa/tasks", { body: input });
    },
  },
  {
    name: "update_task",
    description:
      "Update an existing task — change status (e.g. 'completed'), title, description, deadline, priority, or add a status note.",
    inputSchema: zodToJsonSchema(updateTaskInput) as Record<string, unknown>,
    handler: async (raw) => {
      const { task_id, ...patch } = updateTaskInput.parse(raw);
      return tessa.put(`/tessa/tasks/${task_id}`, { body: patch });
    },
  },
  {
    name: "create_dashboard_note",
    description:
      "Create a dashboard sticky note on your Tessa portal home. " +
      "Either provide `body` (text note) or `items` (checklist).",
    inputSchema: zodToJsonSchema(createNoteInput) as Record<string, unknown>,
    handler: async (raw) => {
      const input = createNoteInput.parse(raw);
      const body: Record<string, unknown> = { ...input };
      if (input.reminder_interval !== undefined) {
        body.reminder_interval = parseInt(input.reminder_interval, 10);
      }
      return tessa.post("/notes", { body });
    },
  },
];
