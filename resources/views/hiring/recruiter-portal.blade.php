<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>InnovFix Recruitment</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #0f172a; color: #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; }
        a { color: #93c5fd; }
        .rp-wrap { max-width: 880px; margin: 0 auto; padding: 24px 16px 72px; }
        .rp-brand { font-weight: 700; letter-spacing: .5px; color: #38bdf8; font-size: 13px; text-transform: uppercase; }
        .rp-h1 { font-size: 22px; margin: 6px 0 2px; color: #f8fafc; }
        .rp-sub { color: #94a3b8; font-size: 14px; margin: 0 0 18px; }

        /* Buttons */
        .rp-btn { background: #2563eb; color: #fff; border: 1px solid #2563eb; border-radius: 8px; padding: 9px 16px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
        .rp-btn:hover:not(:disabled) { background: #1d4ed8; }
        .rp-btn:disabled { opacity: .55; cursor: not-allowed; }
        .rp-btn-ghost { background: transparent; color: #cbd5e1; border-color: #334155; }
        .rp-btn-ghost:hover:not(:disabled) { background: #1e293b; color: #f8fafc; }
        .rp-btn-sm { padding: 6px 12px; font-size: 13px; }

        /* Stats */
        .rp-stats { display: flex; gap: 12px; margin-bottom: 22px; }
        .rp-stat { flex: 1; background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 14px 16px; }
        .rp-stat b { display: block; font-size: 26px; color: #f8fafc; }
        .rp-stat span { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; }

        /* JD cards grid */
        .rp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px; }
        .rp-card { background: #1e293b; border: 1px solid #334155; border-radius: 14px; padding: 18px; display: flex; flex-direction: column; }
        .rp-card h2 { font-size: 17px; margin: 0 0 8px; color: #f8fafc; }
        .rp-meta { font-size: 13px; color: #cbd5e1; margin: 2px 0; }
        .rp-meta b { color: #94a3b8; font-weight: 600; }
        .rp-skills { font-size: 12px; color: #94a3b8; margin: 6px 0 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .rp-count { display: inline-block; margin-top: 12px; font-size: 12px; color: #94a3b8; }
        .rp-count b { color: #e2e8f0; }
        .rp-card-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }

        /* JD detail */
        .rp-back { display: inline-block; font-size: 13px; color: #94a3b8; text-decoration: none; margin-bottom: 14px; cursor: pointer; background: none; border: 0; padding: 0; font-family: inherit; }
        .rp-back:hover { color: #e2e8f0; }
        .rp-desc { font-size: 14px; color: #cbd5e1; white-space: pre-wrap; margin: 10px 0 0; padding: 14px; background: #0b1222; border: 1px solid #1e293b; border-radius: 10px; max-height: 280px; overflow: auto; }
        .rp-section { margin-top: 26px; }
        .rp-section h3 { font-size: 13px; text-transform: uppercase; letter-spacing: .4px; color: #94a3b8; margin: 0 0 12px; border-bottom: 1px solid #1e293b; padding-bottom: 8px; }

        /* Multi-candidate stager */
        .rp-cand { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
        .rp-cand-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .rp-cand-num { font-size: 13px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: .4px; }
        .rp-remove { background: none; border: 0; color: #64748b; font-size: 20px; line-height: 1; cursor: pointer; padding: 0 4px; }
        .rp-remove:hover { color: #fca5a5; }
        .rp-label { display: block; font-size: 12px; color: #94a3b8; margin: 8px 0 4px; }
        .rp-input { width: 100%; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #e2e8f0; padding: 9px 10px; font-size: 14px; font-family: inherit; }
        .rp-input[type=file] { padding: 7px; }
        .rp-input:focus { outline: none; border-color: #2563eb; }
        .rp-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .rp-row > div { flex: 1; min-width: 150px; }
        .rp-cand-status { font-size: 13px; font-weight: 600; margin-top: 10px; min-height: 18px; }
        .rp-cand-status.busy { color: #fcd34d; }
        .rp-cand-status.ok { color: #6ee7b7; }
        .rp-cand-status.err { color: #fca5a5; }
        .rp-stager-actions { display: flex; gap: 10px; align-items: center; margin-top: 4px; flex-wrap: wrap; }

        /* History + progress */
        .rp-hist-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-top: 1px solid #1e293b; flex-wrap: wrap; }
        .rp-hist-main { flex: 1; min-width: 200px; }
        .rp-hist-name { color: #f8fafc; font-size: 14px; font-weight: 600; }
        .rp-hist-sub { color: #64748b; font-size: 12px; margin-top: 2px; }
        .rp-resume-link { font-size: 12px; }
        .rp-progress { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .rp-steps { display: inline-flex; align-items: center; }
        .rp-step { display: flex; align-items: center; }
        .rp-step .rp-dot { width: 11px; height: 11px; border-radius: 50%; background: #334155; display: block; }
        .rp-step:not(:first-child)::before { content: ''; width: 16px; height: 2px; background: #334155; }
        .rp-step.done .rp-dot { background: #22c55e; }
        .rp-step.done::before { background: #22c55e; }
        .rp-step.current .rp-dot { background: #38bdf8; box-shadow: 0 0 0 3px rgba(56,189,248,.25); }
        .rp-step.current.hired .rp-dot { background: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,.25); }

        .rp-badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
        .rp-badge.pending { background: #422006; color: #fcd34d; }
        .rp-badge.sel { background: #064e3b; color: #6ee7b7; }
        .rp-badge.hired { background: #1e3a8a; color: #93c5fd; }
        .rp-badge.neg { background: #450a0a; color: #fca5a5; }
        .rp-term { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .rp-reason { font-size: 12px; color: #fca5a5; font-style: italic; }

        /* Misc */
        .rp-empty { border: 1px dashed #334155; border-radius: 12px; padding: 34px; text-align: center; color: #94a3b8; }
        .rp-loading { display: flex; align-items: center; gap: 10px; color: #94a3b8; font-size: 14px; padding: 40px 0; justify-content: center; }
        .rp-spinner { width: 18px; height: 18px; border: 2px solid #334155; border-top-color: #38bdf8; border-radius: 50%; animation: rp-spin .8s linear infinite; }
        @keyframes rp-spin { to { transform: rotate(360deg); } }
        .rp-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #1e293b; color: #f8fafc; border: 1px solid #334155; padding: 11px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; z-index: 1100; opacity: 0; transition: opacity .2s; max-width: 90vw; }
        .rp-toast.show { opacity: 1; }
        .rp-toast.err { border-color: #7f1d1d; color: #fca5a5; }
        .rp-foot { color: #475569; font-size: 12px; text-align: center; margin-top: 36px; }
    </style>
</head>
<body>
@if ($invalid)
    <div class="rp-wrap">
        <div class="rp-brand">InnovFix Recruitment</div>
        <h1 class="rp-h1">Link not valid</h1>
        <p class="rp-sub">This link is no longer valid. Please contact your HR contact at InnovFix for a new one.</p>
    </div>
@else
    <div id="rp-app" class="rp-wrap">
        <div class="rp-loading"><div class="rp-spinner"></div> Loading your dashboard…</div>
    </div>
    <noscript>
        <div class="rp-wrap"><p class="rp-sub">This portal needs JavaScript enabled in your browser.</p></div>
    </noscript>
    <script>
        window.RP = { token: @json($token), name: @json($name) };
    </script>
    <script src="/js/recruiter-portal.js?v=20260609a"></script>
@endif
</body>
</html>
