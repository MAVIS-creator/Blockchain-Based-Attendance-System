const ORIGIN_HOST = "attendancev2app7t5g81ps.azurewebsites.net";
const FALLBACK_HOME = "/index.php";

const ERROR_PATHS = new Map([
  [400, "/400.php"],
  [401, "/401.php"],
  [403, "/403.php"],
  [404, "/404.php"],
  [405, "/405.php"],
  [408, "/408.php"],
  [429, "/429.php"],
  [500, "/500.php"],
  [502, "/502.php"],
  [503, "/503.php"],
  [504, "/504.php"],
]);

const SENSITIVE_JSON_BASENAMES = new Set([
  "accounts.json",
  "settings.json",
  "settings_templates.json",
  "chat.json",
  "revoked.json",
  "support_tickets.json",
  "sessions.json",
]);

const BLOCKED_DIR_PREFIXES = ["/storage/", "/vendor/"];
const BLOCKED_EXTENSIONS = [
  ".env",
  ".local",
  ".log",
  ".bak",
  ".lock",
  ".ps1",
  ".bat",
  ".md",
  ".sql",
  ".zip",
];

function shouldBlockPath(url) {
  const pathname = decodeURIComponent(url.pathname || "/");
  const lowerPath = pathname.toLowerCase();

  // Allow ACME/challenge style routes if needed.
  if (lowerPath.startsWith("/.well-known/")) {
    return false;
  }

  // Block dotfiles anywhere in the path.
  if (/(^|\/)\.[^/]/.test(pathname)) {
    return true;
  }

  // Block known sensitive top-level directories.
  if (BLOCKED_DIR_PREFIXES.some((prefix) => lowerPath.startsWith(prefix))) {
    return true;
  }

  const basename = lowerPath.split("/").pop() || "";
  if (SENSITIVE_JSON_BASENAMES.has(basename)) {
    return true;
  }

  if (BLOCKED_EXTENSIONS.some((ext) => basename.endsWith(ext))) {
    return true;
  }

  return false;
}

function fallbackHtml(status) {
  const titles = {
    400: "Bad Request",
    401: "Unauthorized",
    403: "Forbidden",
    404: "Page Not Found",
    405: "Method Not Allowed",
    408: "Request Timeout",
    429: "Too Many Requests",
    500: "Server Error",
    502: "Bad Gateway",
    503: "Service Unavailable",
    504: "Gateway Timeout",
  };

  const title = titles[status] || "Server Error";
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${status} - ${title}</title>
  <style>
    body{min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f8fafc;color:#0f172a;padding:2rem}
    .card{max-width:560px;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 20px 60px rgba(15,23,42,.08);padding:3rem 2.5rem;text-align:center}
    .code{font-size:clamp(3rem,8vw,5rem);font-weight:800;color:#4361ee;line-height:1;margin-bottom:1rem}
    h1{margin:0 0 .75rem;font-size:clamp(1.5rem,4vw,2rem)}
    p{margin:0;color:#64748b;line-height:1.65}
  </style>
</head>
<body>
  <main class="card">
    <div class="code">${status}</div>
    <h1>${title}</h1>
    <p>The page you requested is unavailable right now. Please try again later.</p>
  </main>
</body>
</html>`;
}

function buildOriginRequest(request, originUrl, visitorUrl) {
  const headers = new Headers(request.headers);
  headers.set("X-Forwarded-Host", visitorUrl.host);
  headers.set("X-Forwarded-Proto", visitorUrl.protocol.replace(":", ""));
  headers.set("X-Original-Host", visitorUrl.host);
  headers.set("X-Forwarded-For", request.headers.get("CF-Connecting-IP") || "");

  return new Request(originUrl.toString(), {
    method: request.method,
    headers,
    body: request.method === "GET" || request.method === "HEAD" ? undefined : request.body,
    redirect: "manual",
  });
}

function rewriteRedirectHeaders(response, visitorUrl) {
  const headers = new Headers(response.headers);
  const location = headers.get("location");
  if (location) {
    try {
      const redirectUrl = new URL(location, visitorUrl.toString());
      if (redirectUrl.hostname === ORIGIN_HOST) {
        redirectUrl.protocol = visitorUrl.protocol;
        redirectUrl.host = visitorUrl.host;
        headers.set("location", redirectUrl.toString());
      }
    } catch (e) {
      // Leave malformed locations unchanged.
    }
  }

  const refresh = headers.get("refresh");
  if (refresh && refresh.includes(ORIGIN_HOST)) {
    headers.set("refresh", refresh.replaceAll(ORIGIN_HOST, visitorUrl.host));
  }

  return headers;
}

async function fetchCustomError(originUrl, status, request) {
  const errorPath = ERROR_PATHS.get(status) || "/500.php";
  const errorUrl = new URL(originUrl);
  errorUrl.hostname = ORIGIN_HOST;
  errorUrl.pathname = errorPath;
  errorUrl.search = "";
  errorUrl.protocol = "https:";

  try {
    // Fetch the error page with a reasonable timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000);
    
    const errorResponse = await fetch(
      new Request(errorUrl.toString(), {
        method: "GET",
        headers: new Headers({
          "User-Agent": request.headers.get("User-Agent") || "CloudflareWorker",
          "Accept": "text/html,application/xhtml+xml",
        }),
        redirect: "manual",
        signal: controller.signal,
      }),
    );
    
    clearTimeout(timeoutId);

    // Accept 2xx responses for error pages
    if (errorResponse.status >= 200 && errorResponse.status < 300) {
      const htmlContent = await errorResponse.text();
      return new Response(htmlContent, {
        status,
        headers: {
          "content-type": "text/html; charset=UTF-8",
          "cache-control": "no-store, must-revalidate",
          "x-error-source": "origin-php",
        },
      });
    }
  } catch (e) {
    // Log error for debugging (in production, Cloudflare will capture this)
    console.error(`Failed to fetch error page ${errorPath}: ${e.message}`);
  }

  // Fallback to built-in HTML if fetch fails or returns non-2xx
  return new Response(fallbackHtml(status), {
    status,
    headers: {
      "content-type": "text/html; charset=UTF-8",
      "cache-control": "no-store, must-revalidate",
      "x-error-source": "fallback",
    },
  });
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (url.pathname === "/healthz") {
      return new Response("ok", { status: 200 });
    }

    const originUrl = new URL(request.url);
    originUrl.hostname = ORIGIN_HOST;
    originUrl.protocol = "https:";

    if (shouldBlockPath(url)) {
      return fetchCustomError(originUrl.toString(), 403, request);
    }

    const originRequest = buildOriginRequest(request, originUrl, url);
    let response;
    try {
      response = await fetch(originRequest);
    } catch (e) {
      return fetchCustomError(originUrl.toString(), 502, request);
    }

    if (
      [400, 401, 403, 404, 405, 408, 429, 500, 502, 503, 504].includes(
        response.status,
      )
    ) {
      return fetchCustomError(originUrl.toString(), response.status, request);
    }

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: rewriteRedirectHeaders(response, url),
    });
  },
};
