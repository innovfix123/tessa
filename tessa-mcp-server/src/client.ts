import { request } from "undici";

export interface TessaCallOptions {
  query?: Record<string, unknown>;
  body?: unknown;
}

export class TessaApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly path: string,
    public readonly body: unknown,
  ) {
    super(`Tessa API ${status} on ${path}`);
    this.name = "TessaApiError";
  }
}

const DEFAULT_TIMEOUT_MS = 30_000;

function getEnv(name: string): string {
  const value = process.env[name];
  if (!value || value.trim() === "") {
    throw new Error(`Missing required env var: ${name}`);
  }
  return value.trim();
}

function buildUrl(base: string, path: string, query?: Record<string, unknown>): string {
  if (!path.startsWith("/")) {
    throw new Error(`Path must start with "/": got ${JSON.stringify(path)}`);
  }
  const trimmedBase = base.replace(/\/+$/, "");
  const url = new URL(`${trimmedBase}/api/mcp${path}`);
  if (query) {
    for (const [key, raw] of Object.entries(query)) {
      if (raw === undefined || raw === null) continue;
      if (Array.isArray(raw)) {
        for (const v of raw) url.searchParams.append(`${key}[]`, String(v));
      } else if (typeof raw === "object") {
        url.searchParams.append(key, JSON.stringify(raw));
      } else {
        url.searchParams.append(key, String(raw));
      }
    }
  }
  return url.toString();
}

async function call(
  method: "GET" | "POST" | "PUT" | "PATCH" | "DELETE",
  path: string,
  opts: TessaCallOptions = {},
): Promise<unknown> {
  const baseUrl = getEnv("TESSA_BASE_URL");
  const token = getEnv("TESSA_API_TOKEN");
  const url = buildUrl(baseUrl, path, opts.query);

  const headers: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
  };
  let body: string | undefined;
  if (opts.body !== undefined && method !== "GET") {
    body = JSON.stringify(opts.body);
    headers["Content-Type"] = "application/json";
  }

  const res = await request(url, {
    method,
    headers,
    body,
    bodyTimeout: DEFAULT_TIMEOUT_MS,
    headersTimeout: DEFAULT_TIMEOUT_MS,
  });

  const text = await res.body.text();
  let parsed: unknown = text;
  if (text.length > 0) {
    try {
      parsed = JSON.parse(text);
    } catch {
      // leave as raw text
    }
  }

  if (res.statusCode < 200 || res.statusCode >= 300) {
    throw new TessaApiError(res.statusCode, `${method} ${path}`, parsed);
  }

  return parsed;
}

export const tessa = {
  get: (path: string, opts?: TessaCallOptions) => call("GET", path, opts),
  post: (path: string, opts?: TessaCallOptions) => call("POST", path, opts),
  put: (path: string, opts?: TessaCallOptions) => call("PUT", path, opts),
  patch: (path: string, opts?: TessaCallOptions) => call("PATCH", path, opts),
  delete: (path: string, opts?: TessaCallOptions) => call("DELETE", path, opts),
};

export interface TessaUser {
  id: number;
  name: string;
  email: string;
  role: string;
}

let cachedUser: TessaUser | null = null;

export async function getMe(): Promise<TessaUser> {
  if (cachedUser) return cachedUser;
  const res = (await tessa.get("/auth/session")) as
    | { authenticated: boolean; user?: TessaUser }
    | undefined;
  if (!res || !res.authenticated || !res.user) {
    throw new Error("Tessa session lookup failed — check TESSA_API_TOKEN.");
  }
  cachedUser = res.user;
  return res.user;
}
