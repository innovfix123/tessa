import type { Tool } from "./types.js";
import { taskTools } from "./tasks.js";
import { meetingTools } from "./meetings.js";
import { noteTools } from "./notes.js";
import { reportTools } from "./reports.js";
import { hrTools } from "./hr.js";
import { agileTools } from "./agile.js";
import { writeTools } from "./writes.js";
import { escapeTools } from "./escape.js";

export const tools: Tool[] = [
  ...taskTools,
  ...meetingTools,
  ...noteTools,
  ...reportTools,
  ...hrTools,
  ...agileTools,
  ...writeTools,
  ...escapeTools,
];

export const toolByName: Map<string, Tool> = new Map(tools.map((t) => [t.name, t]));
