<?php
// ============================================================
// index.php — DB queries + form handler at top (before HTML)
// ============================================================
require_once __DIR__ . '/config/database.php';

$db         = getPDO();
$about      = null;
$aboutError = '';

/* ------------------------------------------------------------
   ABOUT SECTION FETCH
   ------------------------------------------------------------ */
try {
    $about = $db->query(
        "SELECT * FROM about_section WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
    )->fetch();

    if ($about) {
        $st = $db->prepare(
            "SELECT * FROM about_stats
             WHERE section_id = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $st->execute([$about['id']]);
        $about['stats'] = $st->fetchAll();

        // Normalize image path
        if (!empty($about['image_url'])) {
            $img = trim($about['image_url']);
            $img = str_replace('\\', '/', $img);
            $img = ltrim($img, '/');
            $img = preg_replace('#^\./+#', '', $img);
            $img = preg_replace('#^(\.\./)+#', '', $img);
            $img = preg_replace('#^admin/#', '', $img);

            if (!preg_match('#^https?://#i', $img)) {
                if (strpos($img, 'uploads/') === 0) {
                    // correct
                } elseif (strpos($img, 'uploads/') !== false) {
                    $img = substr($img, strpos($img, 'uploads/'));
                } else {
                    $img = 'uploads/' . $img;
                }
            }
            $about['image_url'] = $img;

            if (!preg_match('#^https?://#i', $img) && !file_exists(__DIR__ . '/' . $img)) {
                $aboutError = 'Image file not found: ' . $img;
                $about['image_url'] = null;
            }
        }
    } else {
        $aboutError = 'No active row in about_section.';
    }
} catch (Throwable $e) {
    $aboutError = 'DB error (about): ' . $e->getMessage();
    error_log($aboutError);
}

/* ------------------------------------------------------------
   ENQUIRY FORM HANDLER
   - Insert into DB
   - On success: build WhatsApp URL, redirect to it
   - On failure: show alert, repopulate fields
   ------------------------------------------------------------ */
$formMsg  = '';
$formType = '';
$old      = ['name'=>'', 'phone'=>'', 'email'=>'', 'product'=>'', 'message'=>''];

$WHATSAPP_NUMBER = '919374480068'; // wa.me format (no '+')

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'enquiry') {

    $old = [
        'name'    => trim($_POST['name']    ?? ''),
        'phone'   => trim($_POST['phone']   ?? ''),
        'email'   => trim($_POST['email']   ?? ''),
        'product' => trim($_POST['product'] ?? ''),
        'message' => trim($_POST['message'] ?? ''),
    ];

    $errors = [];

    if ($old['name'] === '') {
        $errors[] = 'Please enter your name.';
    } elseif (mb_strlen($old['name']) > 120) {
        $errors[] = 'Name is too long.';
    }

    if ($old['phone'] === '') {
        $errors[] = 'Please enter your phone number.';
    } elseif (!preg_match('/^[\+\d][\d\s\-\(\)]{6,20}$/', $old['phone'])) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($old['message'] === '') {
        $errors[] = 'Please enter a message.';
    } elseif (mb_strlen($old['message']) < 5) {
        $errors[] = 'Message is too short.';
    } elseif (mb_strlen($old['message']) > 2000) {
        $errors[] = 'Message is too long (max 2000 chars).';
    }

    // Honeypot
    if (!empty($_POST['website'])) {
        $errors[] = 'Submission rejected.';
    }

    if (empty($errors)) {
        try {
            // DB insert
            $stmt = $db->prepare(
                "INSERT INTO enquiries
                    (name, phone, email, product, message, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $old['name'],
                $old['phone'],
                $old['email'] ?: null,
                $old['product'] ?: null,
                $old['message'],
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            // Build WhatsApp message
            $waText = "New Enquiry from WOOLF Website:\n\n"
                    . "Name: "    . $old['name']    . "\n"
                    . "Phone: "   . $old['phone']   . "\n"
                    . "Email: "   . ($old['email'] ?: '—') . "\n"
                    . "Product: " . ($old['product'] ?: '—') . "\n"
                    . "Message: " . $old['message'];

            $waUrl = 'https://wa.me/' . $WHATSAPP_NUMBER . '?text=' . rawurlencode($waText);

            // Redirect to WhatsApp. We keep the form data empty so a back-nav shows a fresh form.
            $old = ['name'=>'', 'phone'=>'', 'email'=>'', 'product'=>'', 'message'=>''];
            header('Location: ' . $waUrl);
            exit;

        } catch (Throwable $e) {
            $formMsg  = 'Could not save your enquiry. Please try again or call us directly.';
            $formType = 'danger';
            error_log('Enquiry insert failed: ' . $e->getMessage());
        }
    } else {
        $formMsg  = implode(' ', $errors);
        $formType = 'danger';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
        content="WOOLF Bricks & Fly Ash — fly ash bricks and concrete blocks manufactured in Surat, Gujarat. Explore products, plant, specifications and enquire for supply.">
    <meta name="theme-color" content="#111715">
    <title>WOOLF — Bricks & Fly Ash | Built for What's Next</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root{--ink:#111715;--ink2:#1a211e;--gold:#d4a943;--paper:#f1f0eb;--muted:#9a9e97;
              --line:rgba(255,255,255,.15);--darkline:rgba(20,27,24,.16)}
        *{box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{margin:0;background:var(--paper);color:var(--ink);
             font-family:"DM Sans",sans-serif;overflow-x:hidden}
        a{text-decoration:none;color:inherit}

        .woolf-nav{padding:18px 0;background:rgba(17,23,21,.88);backdrop-filter:blur(18px);
                   border-bottom:1px solid rgba(255,255,255,.08)}
        .navbar-brand{display:flex;align-items:center;gap:10px;color:#fff}
        .brand-mark{font:800 34px Manrope;color:var(--gold);line-height:1}
        .brand-type{font:800 17px Manrope;letter-spacing:.14em;line-height:1.1}
        .brand-type small{display:block;color:var(--gold);font-size:8px;letter-spacing:.2em;margin-top:5px}
        .nav-link{color:#d6d9d4 !important;font-size:11px;text-transform:uppercase;
                  letter-spacing:.12em;padding:12px 14px !important}
        .nav-link:hover{color:var(--gold) !important}
        .nav-cta,.btn-gold,.btn-outline-light{display:inline-flex;align-items:center;gap:24px;
            padding:15px 21px;font-size:11px;text-transform:uppercase;letter-spacing:.12em;
            font-weight:700;transition:.3s}
        .nav-cta,.btn-gold{background:var(--gold);color:#161a17}
        .nav-cta:hover,.btn-gold:hover{background:#f0c766;color:#111;transform:translateY(-3px)}
        .btn-outline-light{border:1px solid rgba(255,255,255,.45);color:#fff}
        .btn-outline-light:hover{border-color:var(--gold);color:var(--gold)}

        .hero{min-height:790px;height:100vh;max-height:1000px;background:#111815;color:#fff;
              position:relative;overflow:hidden;padding-top:100px;display:flex;align-items:center}
        .hero-grid{position:absolute;inset:0;
            background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),
                             linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px);
            background-size:80px 80px;
            mask-image:linear-gradient(90deg,#000,transparent 85%)}
        .hero-glow{position:absolute;width:550px;height:550px;border-radius:50%;
            right:5%;top:15%;
            background:radial-gradient(circle,rgba(203,158,57,.16),transparent 70%);
            filter:blur(12px)}
        .hero-content{position:relative;z-index:2;padding-top:50px}
        .eyebrow{font-size:10px;letter-spacing:.22em;font-weight:700;color:var(--gold);margin-bottom:25px}
        .eyebrow span{display:inline-block;width:25px;height:1px;background:var(--gold);
                      vertical-align:middle;margin-right:10px}
        .eyebrow.dark{color:#96701d}
        .hero h1{font:800 clamp(58px,8vw,112px)/.95 Manrope;letter-spacing:-.075em;margin:0 0 28px;max-width:700px}
        .hero h1 em,.section-title em{font-family:Georgia,serif;font-weight:400;
            color:var(--gold);letter-spacing:-.06em}
        .hero-copy{max-width:470px;color:#b8beb8;font-size:15px;line-height:1.8;margin-bottom:34px}
        .hero-bottom{position:absolute;left:12px;right:12px;bottom:30px;
            display:flex;justify-content:space-between;color:#7f8981;
            font-size:9px;letter-spacing:.16em}
        .hero-vertical{position:absolute;right:35px;bottom:95px;writing-mode:vertical-rl;
            font-size:9px;letter-spacing:.25em;color:#a7a99f}
        .hero-brick-wrap{position:absolute;right:6%;top:20%;width:43%;height:65%;
            display:flex;align-items:center;justify-content:center;perspective:1000px}
        .brick-scene{width:360px;height:260px;position:relative;perspective:900px;
            filter:drop-shadow(0 55px 35px rgba(0,0,0,.55));
            animation:float 6s ease-in-out infinite}
        .brick3d{position:absolute;width:290px;height:130px;left:35px;top:65px;
            transform-style:preserve-3d;
            transform:rotateX(-22deg) rotateY(-35deg);
            animation:rotateBrick 16s linear infinite}
        .brick-face{position:absolute;
            background:linear-gradient(145deg,#777c77,#454b47 65%,#303632);
            border:1px solid rgba(255,255,255,.14);
            box-shadow:inset 0 0 35px rgba(0,0,0,.25)}
        .brick-face.front{width:290px;height:130px;transform:translateZ(48px);
            display:grid;place-items:center;color:rgba(255,255,255,.23);
            font:800 28px Manrope;letter-spacing:.2em}
        .brick-face.top{width:290px;height:96px;transform:rotateX(90deg) translateZ(48px);
            transform-origin:top;background:linear-gradient(120deg,#92958d,#555b55)}
        .brick-face.side{width:96px;height:130px;transform:rotateY(90deg) translateZ(242px);
            transform-origin:left;background:linear-gradient(90deg,#333a35,#606760)}
        @keyframes rotateBrick{0%{transform:rotateX(-22deg) rotateY(-35deg)}
            50%{transform:rotateX(-12deg) rotateY(145deg)}
            100%{transform:rotateX(-22deg) rotateY(325deg)}}
        @keyframes float{50%{transform:translateY(-20px)}}
        .brick-caption{position:absolute;bottom:8%;right:15%;
            font-size:9px;letter-spacing:.2em;color:#929b91}
        .brick-caption b{color:var(--gold);margin-left:12px}

        .ticker{background:var(--gold);overflow:hidden;padding:15px 0;color:#171b17}
        .ticker-track{display:flex;gap:40px;white-space:nowrap;width:max-content;
            animation:marquee 28s linear infinite;font:800 11px Manrope;letter-spacing:.18em}
        .ticker-track i{font-style:normal}
        @keyframes marquee{to{transform:translateX(-50%)}}

        .section{padding:120px 0}
        .section-title{font:800 clamp(42px,6vw,76px)/1.02 Manrope;
            letter-spacing:-.07em;margin:0 0 25px}
        .section-title em{color:#a87e25}
        .section-title.light{color:#fff}
        .section-title.light em{color:var(--gold)}
        .lead-copy{font:500 21px/1.55 Manrope;letter-spacing:-.025em}
        .body-copy{font-size:14px;line-height:1.85;color:#626b63}

        .about-image{height:420px;position:relative;margin-top:65px;
            overflow:hidden;background:#303a33}
        .about-image img{width:100%;height:100%;object-fit:cover;
            filter:brightness(.78);transition:transform 1s}
        .about-image:hover img{transform:scale(1.04)}
        .image-stamp{position:absolute;top:30px;left:30px;color:#fff;
            border:1px solid rgba(255,255,255,.5);padding:13px 18px;display:grid}
        .image-stamp b{font:800 18px Manrope;letter-spacing:.18em}
        .image-stamp small{font-size:8px;color:var(--gold);letter-spacing:.2em}
        .image-note{position:absolute;bottom:28px;right:32px;color:#fff;text-align:right;
            font:800 25px/1.1 Manrope;letter-spacing:-.04em}
        .stat-row{display:grid;grid-template-columns:repeat(4,1fr);
            border-top:1px solid var(--darkline);margin-top:55px;padding-top:28px;gap:20px}
        .stat-row>div{display:flex;flex-direction:column;gap:10px}
        .stat-row strong{font:800 clamp(30px,4vw,48px) Manrope;letter-spacing:-.06em}
        .stat-row span{font-size:9px;line-height:1.7;letter-spacing:.13em;color:#70786f}

        .products-section,.materials-section,.process-section,
        .quality-section,.contact-section{background:var(--ink);color:#fff}
        .section-intro{max-width:340px;color:#aeb6ae;font-size:14px;line-height:1.8}

        .product-card{height:100%;background:#202824;border:1px solid rgba(255,255,255,.12);
            padding:24px;display:flex;flex-direction:column;min-height:490px;transition:.4s}
        .product-card:hover{border-color:rgba(212,169,67,.65);transform:translateY(-6px)}
        .product-top{display:flex;justify-content:space-between;color:#929c92;
            font-size:9px;letter-spacing:.15em}
        .product-visual{height:280px;display:grid;place-items:center;overflow:hidden;
            background:radial-gradient(ellipse,rgba(181,183,168,.14),transparent 65%)}
        .brick-mini{width:220px;height:110px;position:relative;
            transform:rotate(-20deg) skewY(3deg);
            filter:drop-shadow(0 25px 16px #090d0b);transition:transform .5s}
        .product-card:hover .brick-mini{transform:rotate(-12deg) skewY(3deg) translateY(-10px)}
        .mini-front{position:absolute;width:220px;height:90px;top:20px;
            background:linear-gradient(140deg,#92958b,#4b514a);border:1px solid #a5a79c}
        .mini-top{position:absolute;top:0;left:0;width:220px;height:40px;
            background:#a2a598;transform:skewX(-45deg) scaleY(.8);transform-origin:bottom}
        .mini-side{position:absolute;right:-40px;top:20px;width:40px;height:90px;
            background:#343a35;transform:skewY(-45deg);transform-origin:left}
        .product-info{margin-top:auto;display:flex;justify-content:space-between;
            align-items:flex-end;gap:15px}
        .product-info h3{font:700 26px Manrope;letter-spacing:-.04em}
        .product-info p{font-size:12px;color:#aab2aa;max-width:340px}
        .size-tags{display:flex;gap:8px;flex-wrap:wrap}
        .size-tags span{font-size:10px;border:1px solid rgba(255,255,255,.2);
            padding:7px 10px;color:#d5d8d2}
        .round-arrow{width:44px;height:44px;border:1px solid var(--gold);
            border-radius:50%;display:grid;place-items:center;color:var(--gold);flex-shrink:0}
        .round-arrow:hover{background:var(--gold);color:#111}
        .product-secondary{background:#29322d}
        .block-visual{height:280px;display:grid;place-items:center;
            background:radial-gradient(ellipse,rgba(181,183,168,.14),transparent 65%)}
        .concrete-block{width:190px;height:130px;
            background:linear-gradient(145deg,#777d74,#444b44);
            transform:skewY(-12deg) rotate(-8deg);
            box-shadow:20px 30px 30px #111;
            display:flex;justify-content:space-evenly;align-items:center}
        .concrete-block i{width:48px;height:60px;background:#202722;
            box-shadow:inset 3px 3px 8px #080c0a}

        .specs-section{background:#e6e5de}
        .spec-list{border-top:1px solid var(--darkline);margin-top:32px}
        .spec-list div{display:flex;justify-content:space-between;align-items:center;
            padding:15px 0;border-bottom:1px solid var(--darkline)}
        .spec-list span{font-size:9px;letter-spacing:.18em;color:#70776f}
        .spec-list b{font:800 24px Manrope}
        .spec-list small{font-size:10px;color:#8a8e86}
        .dimension-visual{height:440px;background:#d9d8d0;position:relative;
            display:grid;place-items:center;overflow:hidden;
            background-image:linear-gradient(rgba(25,35,29,.05) 1px,transparent 1px),
                             linear-gradient(90deg,rgba(25,35,29,.05) 1px,transparent 1px);
            background-size:35px 35px}
        .dimension-brick{width:270px;height:125px;position:relative;
            transform:rotate(-20deg) skewY(3deg);
            filter:drop-shadow(0 30px 18px rgba(0,0,0,.25))}
        .dim-front{position:absolute;top:24px;width:270px;height:100px;
            background:linear-gradient(140deg,#92958b,#4b514a);
            display:grid;place-items:center;color:#ffffff32;
            font:800 22px Manrope;letter-spacing:.2em}
        .dim-top{position:absolute;top:0;width:270px;height:45px;
            background:#a2a598;transform:skewX(-45deg);transform-origin:bottom}
        .dim-side{position:absolute;right:-45px;top:24px;width:45px;height:100px;
            background:#343a35;transform:skewY(-45deg);transform-origin:left}
        .dim-line{position:absolute;border-color:#9d7829;border-style:dashed;
            color:#70551c;font-size:9px;letter-spacing:.1em}
        .dim-length{width:290px;bottom:72px;border-top:1px dashed;padding-top:8px}
        .dim-width{height:100px;right:11%;top:22%;border-left:1px dashed;
            padding-left:8px;writing-mode:vertical-rl}
        .dim-height{height:95px;left:14%;top:40%;border-left:1px dashed;
            padding-left:8px;writing-mode:vertical-rl}
        .dimension-visual>p{position:absolute;bottom:12px;right:15px;
            font-size:8px;letter-spacing:.13em;color:#7b8078}
        .texture-callout{margin-top:65px;border-top:1px solid var(--darkline);
            padding:28px 0;display:grid;grid-template-columns:1fr 3fr 1fr;
            gap:25px;align-items:start}
        .texture-callout>span,.texture-callout small{font-size:9px;
            letter-spacing:.15em;color:#7b8179}
        .texture-callout p{font:500 22px/1.5 Manrope;margin:0}

        .contact-section{background:#111815;position:relative;color:#fff}
        .contact-details{margin-top:45px;display:grid;gap:22px}
        .contact-details>a,.contact-details>div{display:flex;align-items:flex-start;
            gap:18px;color:#fff}
        .contact-details i{color:var(--gold);font-size:19px}
        .contact-details span{font-size:9px;letter-spacing:.15em;color:#9ba59b}
        .contact-details strong,.contact-details small{display:block;color:#fff;
            font:600 15px Manrope;letter-spacing:0;margin-top:5px}
        .contact-details small{font:400 11px "DM Sans";color:#aab2aa}

        .enquiry-form{background:#202824;border:1px solid var(--line);padding:35px}
        .form-heading{display:flex;flex-direction:column;gap:10px;margin-bottom:28px}
        .form-heading span{font-size:9px;letter-spacing:.18em;color:var(--gold)}
        .form-heading b{font:700 24px Manrope}
        .enquiry-form label{display:block;font-size:10px;color:#b7beb6;margin-bottom:8px}
        .enquiry-form input,.enquiry-form select,.enquiry-form textarea{
            width:100%;background:#151d19;border:1px solid rgba(255,255,255,.14);
            padding:13px;color:#fff;font:12px "DM Sans";outline:none;border-radius:0;
            transition:border-color .2s}
        .enquiry-form input:focus,.enquiry-form select:focus,
        .enquiry-form textarea:focus{border-color:var(--gold)}
        .enquiry-form input.is-invalid,.enquiry-form textarea.is-invalid,
        .enquiry-form select.is-invalid{border-color:#e05252}
        .enquiry-form select option{background:#151d19}
        .enquiry-form input::placeholder,.enquiry-form textarea::placeholder{color:#717b72}
        .form-note{display:block;text-align:center;color:#879187;font-size:9px;margin-top:12px}

        .form-alert{padding:12px 16px;font-size:13px;border-radius:4px;margin-bottom:18px}
        .form-alert.success{background:rgba(40,167,69,.12);border:1px solid rgba(40,167,69,.4);color:#7ddba1}
        .form-alert.danger{background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.4);color:#f0a0a0}

        .hp-field{position:absolute;left:-9999px;opacity:0;height:0;width:0;overflow:hidden}

        .reveal{opacity:0;transform:translateY(28px);transition:opacity .8s ease,transform .8s ease}
        .reveal.visible{opacity:1;transform:translateY(0)}

        .db-warning{padding:10px 16px;background:#fff3cd;border-bottom:1px solid #ffe69c;
            color:#664d03;font-size:12px;font-family:monospace}

        .footer{background:#0b100d;color:#fff;padding:35px 0 20px}
        .footer-top{display:flex;align-items:center;justify-content:space-between;
            padding-bottom:30px;border-bottom:1px solid var(--line)}
        .footer-top>p{font:700 11px Manrope;letter-spacing:.2em;color:var(--gold);margin:0}
        .back-top{font-size:9px;letter-spacing:.15em}
        .footer-bottom{display:flex;justify-content:space-between;gap:15px;
            padding-top:20px;color:#7f8980;font-size:8px;letter-spacing:.13em}

        .whatsapp-float{position:fixed;z-index:99;right:24px;bottom:24px;
            width:52px;height:52px;border-radius:50%;display:grid;place-items:center;
            background:#20b95a;color:#fff;font-size:24px;
            box-shadow:0 8px 25px #0004;transition:.25s}
        .whatsapp-float:hover{transform:translateY(-5px);color:#fff}

        @media(max-width:991px){
            .hero{height:auto;min-height:850px;align-items:flex-start;padding-top:180px}
            .hero-brick-wrap{right:2%;top:48%;width:55%;height:42%;opacity:.8}
            .hero-content{padding-top:15px}
            .hero-bottom{bottom:20px}
            .nav-cta{display:inline-flex;margin:10px 0}
            .navbar-collapse{padding-top:15px}
            .section{padding:85px 0}
        }
        @media(max-width:767px){
            .hero{min-height:790px;padding-top:155px}
            .hero h1{font-size:clamp(53px,15vw,82px)}
            .hero-brick-wrap{top:53%;right:-15%;width:85%;height:36%;opacity:.65}
            .brick-scene{transform:scale(.75)}
            .hero-bottom{bottom:15px;left:0;right:0;font-size:7px}
            .hero-vertical{right:12px;bottom:45px}
            .stat-row{grid-template-columns:repeat(2,1fr);row-gap:28px}
            .section-title{font-size:48px}
            .about-image{height:300px}
            .enquiry-form{padding:23px}
        }
        @media(prefers-reduced-motion:reduce){
            *,*:before,*:after{scroll-behavior:auto !important;
                animation-duration:.01ms !important;
                animation-iteration-count:1 !important;
                transition-duration:.01ms !important}
            .reveal{opacity:1;transform:none}
        }
        html,body{max-width:100%}
        img,video,svg{max-width:100%;height:auto}
        .container{padding-left:18px;padding-right:18px}
        body{word-wrap:break-word;overflow-wrap:break-word}
        .navbar-toggler{border-color:rgba(255,255,255,.4);padding:6px 10px}
        .navbar-toggler-icon{background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(255,255,255,0.85)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e")}
        .navbar-toggler:focus{box-shadow:0 0 0 2px rgba(212,169,67,.5)}
        .navbar-collapse{background:rgba(17,23,21,.98);border:1px solid rgba(255,255,255,.08);
            margin-top:12px;padding:8px 14px 16px;max-height:78vh;overflow-y:auto;border-radius:4px}
        .navbar-nav .nav-link{padding:14px 4px !important;
            border-bottom:1px solid rgba(255,255,255,.06)}
        .navbar-nav .nav-cta{width:100%;justify-content:space-between;margin-top:6px}
    </style>
</head>

<body>

    <?php if ($aboutError): ?>
        <div class="db-warning"><b>DB:</b> <?= htmlspecialchars($aboutError) ?></div>
    <?php endif; ?>

    <!-- ================= NAV ================= -->
    <nav class="navbar navbar-expand-lg fixed-top woolf-nav">
        <div class="container">
            <a class="navbar-brand" href="#home">
                <span class="brand-mark">W</span>
                <span class="brand-type">WOOLF<small>BRICKS & FLY ASH</small></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="#products">Products</a></li>
                    <li class="nav-item ms-lg-3"><a class="nav-cta" href="#contact">Enquire Now <i class="bi bi-arrow-up-right"></i></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main>

        <!-- ================= HERO ================= -->
        <section class="hero" id="home">
            <div class="hero-grid"></div>
            <div class="hero-glow"></div>
            <div class="container hero-content">
                <div class="eyebrow reveal"><span></span> ENGINEERED FOR EVERY FOUNDATION</div>
                <h1 class="reveal">BUILD WITH<br><em>CONFIDENCE.</em></h1>
                <p class="hero-copy reveal">A stronger foundation for the places where life happens. Discover WOOLF fly ash bricks and concrete blocks, manufactured for modern construction.</p>
                <div class="d-flex flex-wrap gap-3 reveal">
                    <a class="btn-gold" href="#products">Explore Products <i class="bi bi-arrow-down-right"></i></a>
                    <a class="btn-outline-light" href="#contact">Request a Quote <i class="bi bi-arrow-up-right"></i></a>
                </div>
                <div class="hero-bottom"><span>01 / 04 — WOOLF BRICKS & FLY ASH</span><span>SURAT · GUJARAT · INDIA</span></div>
            </div>
            <div class="hero-brick-wrap" aria-label="Stylized 3D fly ash brick">
                <div class="brick-scene">
                    <div class="brick3d">
                        <div class="brick-face front"><span>WOOLF</span></div>
                        <div class="brick-face top"></div>
                        <div class="brick-face side"></div>
                    </div>
                </div>
                <div class="brick-caption">MATERIAL STUDY <b>01</b></div>
            </div>
            <div class="hero-vertical">MADE FOR THE LONG RUN</div>
        </section>

        <!-- ================= TICKER ================= -->
        <section class="ticker">
            <div class="ticker-track">
                <span>STRENGTH IN EVERY BLOCK</span><i>✳</i>
                <span>SMARTER MATERIALS</span><i>✳</i>
                <span>BUILT FOR TOMORROW</span><i>✳</i>
                <span>STRENGTH IN EVERY BLOCK</span><i>✳</i>
                <span>SMARTER MATERIALS</span><i>✳</i>
            </div>
        </section>

        <!-- ================= CONTACT (form + WhatsApp redirect) ================= -->
        <section class="section contact-section" id="contact">
            <div class="container">
                <div class="row g-5">
                    <div class="col-lg-5">
                        <div class="eyebrow dark reveal">GET IN TOUCH</div>
                        <h2 class="section-title light reveal">Let's build<br>something <em>solid.</em></h2>
                        <div class="contact-details reveal">
                            <a href="tel:+919374480068">
                                <i class="bi bi-telephone"></i>
                                <div><span>CALL US</span><strong>+91 93744 80068</strong><small>Mon–Sat, 9:00 – 18:00 IST</small></div>
                            </a>
                            <a href="mailto:woolfbricks@gmail.com">
                                <i class="bi bi-envelope"></i>
                                <div><span>EMAIL</span><strong>woolfbricks@gmail.com</strong><small>We reply within one business day</small></div>
                            </a>
                            <div>
                                <i class="bi bi-geo-alt"></i>
                                <div><span>FACTORY</span><strong>Gaypagal &amp; Jiav Gam</strong><small>Surat, Gujarat, India</small></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="enquiry-form reveal">

                            <?php if ($formMsg): ?>
                                <div class="form-alert <?= $formType ?>"><?= htmlspecialchars($formMsg) ?></div>
                            <?php endif; ?>

                            <div class="form-heading">
                                <span>REQUEST A QUOTE</span>
                                <b>Tell us about your project</b>
                            </div>

                            <form id="enquiryForm" method="post" action="" novalidate>
                                <input type="hidden" name="form" value="enquiry">

                                <div class="hp-field">
                                    <label>Website (leave blank)</label>
                                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="name">Full name *</label>
                                        <input type="text" id="name" name="name" placeholder="Your name"
                                               maxlength="120" required
                                               value="<?= htmlspecialchars($old['name']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="phone">Phone *</label>
                                        <input type="tel" id="phone" name="phone" placeholder="+91 00000 00000"
                                               maxlength="20" required
                                               value="<?= htmlspecialchars($old['phone']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="email">Email</label>
                                        <input type="email" id="email" name="email" placeholder="you@company.com"
                                               maxlength="150"
                                               value="<?= htmlspecialchars($old['email']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="product">Product interest</label>
                                        <select id="product" name="product">
                                            <?php
                                                $products = ['Fly Ash Bricks','Concrete Blocks','Both / Not sure'];
                                                foreach ($products as $p) {
                                                    $sel = ($old['product'] === $p) ? ' selected' : '';
                                                    echo '<option' . $sel . '>' . htmlspecialchars($p) . '</option>';
                                                }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label for="message">Message *</label>
                                        <textarea id="message" name="message" rows="4"
                                                  placeholder="Quantity, delivery location, project timeline…"
                                                  maxlength="2000" required><?= htmlspecialchars($old['message']) ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn-gold w-100 justify-content-center">
                                            Send Enquiry <i class="bi bi-arrow-right"></i>
                                        </button>
                                    </div>
                                </div>
                                <small class="form-note">
                                    After submitting, WhatsApp will open with your details pre-filled — just hit send.
                                </small>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= ABOUT (DB-driven) ================= -->
        <?php if ($about): ?>
        <section class="section about-section" id="about">
            <div class="container">
                <div class="row align-items-end g-5">
                    <div class="col-lg-6">
                        <div class="eyebrow dark reveal"><?= htmlspecialchars($about['eyebrow']) ?></div>
                        <h2 class="section-title reveal">
                            <?= htmlspecialchars($about['title_line1']) ?><br>
                            <?= htmlspecialchars($about['title_line2']) ?>
                            <em><?= htmlspecialchars($about['title_emphasis']) ?></em>
                        </h2>
                    </div>
                    <div class="col-lg-5 offset-lg-1">
                        <p class="lead-copy reveal"><?= nl2br(htmlspecialchars($about['lead_copy'])) ?></p>
                        <p class="body-copy reveal"><?= nl2br(htmlspecialchars($about['body_copy'])) ?></p>
                    </div>
                </div>

                <div class="about-image reveal">
                    <?php if (!empty($about['image_url'])): ?>
                        <img src="<?= htmlspecialchars($about['image_url']) ?>"
                             alt="<?= htmlspecialchars($about['image_alt'] ?: 'WOOLF Manufacturing') ?>">
                    <?php else: ?>
                        <div style="width:100%;height:100%;background:linear-gradient(145deg,#3b4b3f,#1c2a1f);
                                    display:flex;align-items:center;justify-content:center;
                                    color:#fff;font-size:14px;">
                            <?= htmlspecialchars($about['image_alt'] ?: 'WOOLF Manufacturing') ?>
                        </div>
                    <?php endif; ?>
                    <div class="image-stamp"><b>WOOLF</b><small>EST. 2019</small></div>
                    <div class="image-note">BUILT FOR<br>WHAT'S NEXT</div>
                </div>

                <?php if (!empty($about['stats'])): ?>
                <div class="stat-row reveal">
                    <?php foreach ($about['stats'] as $s): ?>
                        <div>
                            <strong><?= htmlspecialchars($s['stat_value']) ?></strong>
                            <span><?= htmlspecialchars($s['stat_label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ================= PRODUCTS ================= -->
        <section class="section products-section" id="products">
            <div class="container">
                <div class="row mb-5">
                    <div class="col-lg-5">
                        <div class="eyebrow light reveal">02 / PRODUCT RANGE</div>
                        <h2 class="section-title light reveal">Materials that<br><em>perform.</em></h2>
                    </div>
                    <div class="col-lg-6 offset-lg-1 d-flex align-items-end">
                        <p class="section-intro reveal">Precision-manufactured fly ash bricks and hollow concrete blocks for structural and non-structural applications.</p>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="product-card reveal">
                            <div class="product-top"><span>01</span><span>FLY ASH BRICKS</span></div>
                            <div class="product-visual">
                                <div class="brick-mini"><div class="mini-front"></div><div class="mini-top"></div><div class="mini-side"></div></div>
                            </div>
                            <div class="product-info">
                                <div><h3>Fly Ash Bricks</h3><p>Uniform shape, high compressive strength and excellent thermal insulation — an eco-friendly alternative to traditional clay bricks.</p>
                                    <div class="size-tags"><span>230×110×75 mm</span><span>230×110×100 mm</span></div>
                                </div>
                                <a href="#contact" class="round-arrow"><i class="bi bi-arrow-up-right"></i></a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="product-card product-secondary reveal">
                            <div class="product-top"><span>02</span><span>CONCRETE BLOCKS</span></div>
                            <div class="block-visual">
                                <div class="concrete-block"><i></i><i></i></div>
                            </div>
                            <div class="product-info">
                                <div><h3>Hollow Blocks</h3><p>Lightweight, strong and quick to install — ideal for partition walls and framed structures.</p>
                                    <div class="size-tags"><span>400×200×200 mm</span><span>400×150×200 mm</span></div>
                                </div>
                                <a href="#contact" class="round-arrow"><i class="bi bi-arrow-up-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= SPECS ================= -->
        <section class="section specs-section" id="specs">
            <div class="container">
                <div class="row g-5 align-items-center">
                    <div class="col-lg-5">
                        <div class="eyebrow dark reveal">03 / TECHNICAL SPECS</div>
                        <h2 class="section-title reveal">Measured for<br><em>precision.</em></h2>
                        <div class="spec-list reveal">
                            <div><span>COMPRESSIVE STRENGTH</span><b>7.5+</b><small>N/mm²</small></div>
                            <div><span>WATER ABSORPTION</span><b>&lt;10%</b><small>by weight</small></div>
                            <div><span>DIMENSIONAL TOLERANCE</span><b>±2</b><small>mm</small></div>
                            <div><span>THERMAL CONDUCTIVITY</span><b>0.25</b><small>W/m·K</small></div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="dimension-visual reveal">
                            <div class="dimension-brick">
                                <div class="dim-front">WOOLF</div>
                                <div class="dim-top"></div>
                                <div class="dim-side"></div>
                            </div>
                            <div class="dim-line dim-length">230 mm</div>
                            <div class="dim-line dim-width">110 mm</div>
                            <div class="dim-line dim-height">75 mm</div>
                            <p>Standard brick dimensions</p>
                        </div>
                    </div>
                </div>
                <div class="texture-callout reveal">
                    <span>TEXTURE / FINISH</span>
                    <p>Sharp edges, uniform surface, consistent colour — ready for direct plastering.</p>
                    <small>WOOLF STANDARD</small>
                </div>
            </div>
        </section>

    </main>

    <!-- ================= FOOTER ================= -->
    <footer class="footer">
        <div class="container">
            <div class="footer-top">
                <a class="navbar-brand" href="#home"><span class="brand-mark">W</span><span class="brand-type">WOOLF<small>BRICKS & FLY ASH</small></span></a>
                <a class="back-top" href="#home">BACK TO TOP <i class="bi bi-arrow-up"></i></a>
                <p>BUILT FOR WHAT'S NEXT</p>
            </div>
            <div class="footer-bottom">
                <span>© 2025 WOOLF BRICKS & FLY ASH. ALL RIGHTS RESERVED.</span>
                <span>PROPRIETOR: PRITESH R PATEL · SURAT, GUJARAT</span>
            </div>
        </div>
    </footer>

    <!-- ================= WHATSAPP FLOAT ================= -->
    <a class="whatsapp-float" href="https://wa.me/919374480068" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
        <i class="bi bi-whatsapp"></i>
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Reveal on scroll
        (function () {
            const reveals = document.querySelectorAll('.reveal');
            const io = new IntersectionObserver((entries) => {
                entries.forEach(e => {
                    if (e.isIntersecting) {
                        e.target.classList.add('visible');
                        io.unobserve(e.target);
                    }
                });
            }, { threshold: 0.12 });
            reveals.forEach(el => io.observe(el));
        })();

        // Client-side validation (does NOT prevent submission — only shows UI hints)
        (function () {
            const form = document.getElementById('enquiryForm');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                let ok = true;
                ['name','phone','message'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (!el.value.trim()) {
                        el.classList.add('is-invalid');
                        ok = false;
                    } else {
                        el.classList.remove('is-invalid');
                    }
                });
                const email = document.getElementById('email');
                if (email && email.value.trim()) {
                    const okEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
                    if (!okEmail) { email.classList.add('is-invalid'); ok = false; }
                    else { email.classList.remove('is-invalid'); }
                }
                // Only block if HTML5 required would already block (i.e. don't override server)
                if (!ok && !form.checkValidity()) {
                    e.preventDefault();
                    const first = form.querySelector('.is-invalid');
                    if (first) first.focus();
                }
            });
        })();
    </script>
</body>

</html>