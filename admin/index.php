<?php
// admin/index.php — Landing page for the admin panel
declare(strict_types=1);

// If already logged in, go straight to dashboard
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['admin_id'])) {
    header('Location: about.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>WOOLF Admin — Welcome</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --ink:#0d1310;
    --ink2:#141b17;
    --panel:#1a211e;
    --gold:#d4a943;
    --gold-h:#f0c766;
    --line:rgba(255,255,255,.08);
    --muted:#8a9389;
    --text:#d6d9d4;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;height:100%}
body{
    background:var(--ink);
    color:var(--text);
    font-family:'DM Sans',sans-serif;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    position:relative;
    overflow:hidden;
}

/* Ambient background */
.bg-grid{
    position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:64px 64px;
    mask-image:radial-gradient(ellipse at center,#000 20%,transparent 70%);
    pointer-events:none;
}
.bg-glow-1{
    position:fixed;
    width:620px;height:620px;border-radius:50%;
    top:-200px;right:-180px;
    background:radial-gradient(circle,rgba(212,169,67,.18),transparent 65%);
    filter:blur(60px);
    pointer-events:none;
}
.bg-glow-2{
    position:fixed;
    width:520px;height:520px;border-radius:50%;
    bottom:-180px;left:-160px;
    background:radial-gradient(circle,rgba(212,169,67,.10),transparent 65%);
    filter:blur(70px);
    pointer-events:none;
}

/* Card */
.landing{
    position:relative;
    z-index:1;
    background:linear-gradient(160deg,rgba(26,33,30,.95),rgba(20,27,23,.95));
    border:1px solid var(--line);
    border-radius:12px;
    padding:56px 48px 48px;
    width:100%;
    max-width:480px;
    text-align:center;
    box-shadow:
        0 30px 80px rgba(0,0,0,.55),
        0 0 0 1px rgba(255,255,255,.02) inset;
    backdrop-filter:blur(20px);
    animation:fadeIn .6s ease both;
}
@keyframes fadeIn{
    from{opacity:0;transform:translateY(16px)}
    to{opacity:1;transform:none}
}

/* Brand */
.brand{
    display:inline-flex;
    align-items:center;
    gap:12px;
    margin-bottom:32px;
}
.brand-mark{
    width:52px;height:52px;
    display:grid;place-items:center;
    background:rgba(212,169,67,.1);
    border:1px solid rgba(212,169,67,.35);
    border-radius:10px;
    font:800 28px/1 Manrope,sans-serif;
    color:var(--gold);
    box-shadow:0 0 24px rgba(212,169,67,.15);
}
.brand-txt{
    text-align:left;
    font:700 14px/1.15 Manrope,sans-serif;
    letter-spacing:.16em;
    color:#fff;
}
.brand-txt small{
    display:block;
    font-size:9px;
    color:var(--gold);
    letter-spacing:.28em;
    margin-top:5px;
    font-weight:600;
}

/* Tagline badge */
.badge-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:6px 14px;
    font-size:10px;
    font-weight:700;
    letter-spacing:.2em;
    text-transform:uppercase;
    color:var(--gold);
    background:rgba(212,169,67,.08);
    border:1px solid rgba(212,169,67,.3);
    border-radius:100px;
    margin-bottom:20px;
}
.badge-pill i{font-size:11px}

/* Headings */
h1{
    font:800 clamp(28px,5vw,40px)/1.1 Manrope,sans-serif;
    letter-spacing:-.03em;
    color:#fff;
    margin:0 0 14px;
}
h1 em{
    font-family:Georgia,serif;
    font-weight:400;
    font-style:italic;
    color:var(--gold);
    letter-spacing:-.02em;
}
.sub{
    font-size:14px;
    line-height:1.7;
    color:var(--muted);
    margin:0 auto 36px;
    max-width:360px;
}

/* Big CTA button */
.btn-login{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:14px;
    width:100%;
    padding:20px 32px;
    font:700 14px/1 Manrope,sans-serif;
    letter-spacing:.16em;
    text-transform:uppercase;
    color:#111;
    background:var(--gold);
    border:1px solid var(--gold);
    border-radius:8px;
    cursor:pointer;
    transition:all .3s cubic-bezier(.4,0,.2,1);
    text-decoration:none;
    position:relative;
    overflow:hidden;
    box-shadow:0 8px 32px rgba(212,169,67,.25);
}
.btn-login:before{
    content:"";
    position:absolute;
    top:0;left:-100%;
    width:100%;height:100%;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.35),transparent);
    transition:left .6s;
}
.btn-login:hover:before{left:100%}
.btn-login:hover{
    background:var(--gold-h);
    border-color:var(--gold-h);
    color:#111;
    transform:translateY(-2px);
    box-shadow:0 14px 44px rgba(212,169,67,.4);
}
.btn-login:active{transform:translateY(0)}
.btn-login i{
    font-size:16px;
    transition:transform .3s;
}
.btn-login:hover i:last-child{transform:translateX(4px)}

/* Secondary link */
.btn-view-site{
    display:inline-flex;
    align-items:center;
    gap:8px;
    margin-top:18px;
    font-size:12px;
    color:var(--muted);
    letter-spacing:.04em;
    transition:.2s;
    text-decoration:none;
}
.btn-view-site:hover{color:var(--gold)}
.btn-view-site i{font-size:13px}

/* Divider */
.divider{
    display:flex;
    align-items:center;
    gap:14px;
    margin:32px 0 24px;
    color:rgba(255,255,255,.15);
    font-size:10px;
    letter-spacing:.2em;
    text-transform:uppercase;
}
.divider:before,.divider:after{
    content:"";
    flex:1;
    height:1px;
    background:var(--line);
}

/* Mini features */
.features{
    display:grid;
    grid-template-columns:1fr 1fr 1fr;
    gap:12px;
    margin-top:8px;
}
.feature{
    padding:14px 10px;
    background:rgba(255,255,255,.02);
    border:1px solid var(--line);
    border-radius:6px;
    text-align:center;
    transition:.2s;
}
.feature:hover{
    background:rgba(212,169,67,.06);
    border-color:rgba(212,169,67,.2);
}
.feature i{
    display:block;
    font-size:18px;
    color:var(--gold);
    margin-bottom:6px;
}
.feature span{
    font-size:10px;
    font-weight:600;
    letter-spacing:.1em;
    text-transform:uppercase;
    color:var(--muted);
}

/* Footer */
.footnote{
    position:fixed;
    bottom:16px;
    left:0;right:0;
    text-align:center;
    font-size:10px;
    letter-spacing:.16em;
    color:rgba(255,255,255,.2);
    text-transform:uppercase;
    z-index:1;
}

/* Responsive */
@media (max-width:480px){
    .landing{padding:40px 24px 32px;border-radius:10px}
    .brand-mark{width:44px;height:44px;font-size:24px}
    .brand-txt{font-size:12px}
    .btn-login{padding:17px 24px;font-size:12px}
    .sub{font-size:13px;margin-bottom:28px}
    .features{gap:8px}
    .feature{padding:10px 6px}
    .feature i{font-size:16px}
    .feature span{font-size:9px}
}
</style>
</head>
<body>

<div class="bg-grid"></div>
<div class="bg-glow-1"></div>
<div class="bg-glow-2"></div>

<div class="landing">

    <!-- Brand -->
    <div class="brand">
        <div class="brand-mark">W</div>
        <div class="brand-txt">
            WOOLF
            <small>ADMIN</small>
        </div>
    </div>

    <!-- Tagline badge -->
    <div class="badge-pill">
        <i class="bi bi-shield-lock-fill"></i>
        Secure Admin Access
    </div>

    <!-- Heading -->
    <h1>
        Welcome<br><em>back.</em>
    </h1>
    <p class="sub">
        Manage your website content, monitor customer enquiries, and keep everything up to date — all in one place.
    </p>

    <!-- BIG BUTTON -->
    <a href="about.php" class="btn-login">
        <i class="bi bi-box-arrow-in-right"></i>
        Login to Admin Panel
        <i class="bi bi-arrow-right"></i>
    </a>

    <!-- View site -->
    <a href="../index.php" target="_blank" class="btn-view-site">
        <i class="bi bi-globe2"></i>
        View live site
    </a>

    <!-- Divider -->
    <div class="divider">What you can manage</div>

    <!-- Features -->
    <div class="features">
        <div class="feature">
            <i class="bi bi-info-circle"></i>
            <span>About</span>
        </div>
        <div class="feature">
            <i class="bi bi-inbox"></i>
            <span>Enquiries</span>
        </div>
        <div class="feature">
            <i class="bi bi-people"></i>
            <span>Clients</span>
        </div>
    </div>

</div>

<div class="footnote">
    WOOLF Bricks &amp; Fly Ash · Admin Portal v1.0
</div>

</body>
</html>