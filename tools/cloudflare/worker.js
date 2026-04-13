const ORIGIN_HOST = 'attendancev2app123.azurewebsites.net';
const FALLBACK_HOME = '/index.php';

const ERROR_PATHS = new Map([
  [400, '/400.php'],
  [401, '/401.php'],
  [403, '/403.php'],
  [404, '/404.php'],
  [405, '/405.php'],
  [408, '/408.php'],
  [429, '/429.php'],
  [500, '/500.php'],
  [502, '/502.php'],
  [503, '/503.php'],
  [504, '/504.php'],
]);

function fallbackHtml(status) {
  const titles = {
    400: 'Bad Request',
    401: 'Unauthorized',
    403: 'Forbidden',
    404: 'Page Not Found',
    405: 'Method Not Allowed',
    408: 'Request Timeout',
    429: 'Too Many Requests',
    500: 'Server Error',
    502: 'Bad Gateway',
    503: 'Service Unavailable',
    504: 'Gateway Timeout',
  };

  const title = titles[status] || 'Server Error';
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

async function fetchCustomError(originUrl, status, request) {
  const errorPath = ERROR_PATHS.get(status) || '/500.php';
  const errorUrl = new URL(originUrl);
  errorUrl.hostname = ORIGIN_HOST;
  errorUrl.pathname = errorPath;
  errorUrl.search = '';

  try {
    const errorResponse = await fetch(new Request(errorUrl.toString(), {
      method: 'GET',
      headers: request.headers,
    }));

    if (errorResponse.ok) {
      const headers = new Headers(errorResponse.headers);
      headers.set('content-type', 'text/html; charset=UTF-8');
      headers.set('cache-control', 'no-store');
      return new Response(await errorResponse.text(), {
        status,
        headers,
      });
    }
  } catch (e) {
    // Fall through to built-in HTML response.
  }

  return new Response(fallbackHtml(status), {
    status,
    headers: {
      'content-type': 'text/html; charset=UTF-8',
      'cache-control': 'no-store',
    },
  });
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (url.pathname === '/healthz') {
      return new Response('ok', { status: 200 });
    }

    const originUrl = new URL(request.url);
    originUrl.hostname = ORIGIN_HOST;
    originUrl.protocol = 'https:';

    const response = await fetch(new Request(originUrl.toString(), request));

    if ([400, 401, 403, 404, 405, 408, 429, 500, 502, 503, 504].includes(response.status)) {
      return fetchCustomError(originUrl.toString(), response.status, request);
    }

    return response;
  },
};
