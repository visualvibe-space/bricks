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
   - Honeypot check runs FIRST (silent reject)
   - Then validation
   - On success: insert DB, redirect to WhatsApp
   ------------------------------------------------------------ */
$formMsg  = '';
$formType = '';
$old      = ['name'=>'', 'phone'=>'', 'email'=>'', 'product'=>'', 'message'=>''];

$WHATSAPP_NUMBER = '919374480068'; // wa.me format (no '+')

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'enquiry') {

    /* ---- HONEYPOT CHECK (run first, silent) ---- */
    $honeypot = trim($_POST['hp_field_xz'] ?? '');
    if ($honeypot !== '') {
        // Bot detected — redirect silently, no error shown
        header('Location: index.php');
        exit;
    }

    /* ---- COLLECT + VALIDATE ---- */
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

    if (empty($errors)) {
        try {
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

            $waText = "New Enquiry from WOOLF Website:\n\n"
                    . "Name: "    . $old['name']    . "\n"
                    . "Phone: "   . $old['phone']   . "\n"
                    . "Email: "   . ($old['email'] ?: '—') . "\n"
                    . "Product: " . ($old['product'] ?: '—') . "\n"
                    . "Message: " . $old['message'];

            $waUrl = 'https://wa.me/' . $WHATSAPP_NUMBER . '?text=' . rawurlencode($waText);

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

/* ------------------------------------------------------------
   CUSTOMERS SECTION FETCH
   ------------------------------------------------------------ */
   $custSection = null;
   $custClients = [];
   
   try {
       $custSection = $db->query(
           "SELECT * FROM customer_section WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
       )->fetch() ?: null;
   
       $custClients = $db->query(
           "SELECT * FROM customer_clients
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC"
       )->fetchAll();
   } catch (Throwable $e) {
       error_log('Customers fetch failed: ' . $e->getMessage());
   }
   
   // Helper: normalise image path (reuses same pattern as About)
   function normalizeImg(?string $path): ?string
   {
       if (!$path) return null;
       $p = trim($path);
       $p = str_replace('\\', '/', $p);
       $p = ltrim($p, '/');
       $p = preg_replace('#^(\./|\.\./|admin/)+#', '', $p);
       if (!preg_match('#^https?://#i', $p)) {
           if (strpos($p, 'uploads/') === false) {
               $p = 'uploads/' . $p;
           }
       }
       // Only check existence for local paths
       if (!preg_match('#^https?://#i', $p)) {
           if (!file_exists(__DIR__ . '/' . $p)) {
               return null;
           }
       }
       return $p;
   }
   /* ------------------------------------------------------------
   GALLERY SECTION FETCH
   ------------------------------------------------------------ */
$gallerySection = null;
$galleryImages  = [];

try {
    $gallerySection = $db->query(
        "SELECT * FROM gallery_section WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
    )->fetch() ?: null;

    if ($gallerySection) {
        $stmt = $db->prepare(
            "SELECT * FROM gallery_images
             WHERE section_id = ? AND is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$gallerySection['id']]);
        $galleryImages = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('Gallery fetch failed: ' . $e->getMessage());
}




/* ------------------------------------------------------------
   REELS SECTION FETCH
   ------------------------------------------------------------ */
   $reelsSection = null;
   $reelsItems   = [];
   
   try {
       $reelsSection = $db->query(
           "SELECT * FROM reels_section WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
       )->fetch() ?: null;
   
       if ($reelsSection) {
           $stmt = $db->prepare(
               "SELECT * FROM reels_items
                WHERE section_id = ? AND is_active = 1
                ORDER BY sort_order ASC, id ASC"
           );
           $stmt->execute([$reelsSection['id']]);
           $reelsItems = $stmt->fetchAll();
       }
   } catch (Throwable $e) {
       error_log('Reels fetch failed: ' . $e->getMessage());
   }
?>
<!doctype html>
<html lang="en">

<head><script async src="https://www.googletagmanager.com/gtm.js?id=GTM-NMQJG8S7"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
        content="WOOLF Bricks & Fly Ash — fly ash bricks and concrete blocks manufactured in Surat, Gujarat. Explore products, plant, specifications and enquire for supply.">
    <meta name="theme-color" content="#111715">

    <title>WOOLF — Bricks & Fly Ash | Built for What's Next</title>

    <!-- Google tag (gtag.js) --> <script async src="https://www.googletagmanager.com/gtag/js?id=GTM-KCH4T2FQ"></script> 
     <script>
      window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); 
      gtag('config', 'GTM-KCH4T2FQ'); 

     </script>


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

        /* ===== HERO (row/col based, responsive) ===== */
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

        /* brick visual — now in its own column */
        .hero-brick-wrap{position:relative;width:100%;height:100%;min-height:480px;
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

        /* if you replace the 3D brick with a real image, use this class */
        .hero-brick-img{width:100%;max-width:520px;height:auto;display:block;
            border-radius:8px;object-fit:cover;
            box-shadow:0 40px 80px rgba(0,0,0,.45)}

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

        .benefits-section{background:#efeee8}
        .benefits-grid{border-top:1px solid var(--darkline);margin-top:45px}
        .benefit{padding:35px 28px 32px 0;border-bottom:1px solid var(--darkline);min-height:230px}
        .benefit:nth-child(3n+2),.benefit:nth-child(3n){padding-left:28px;border-left:1px solid var(--darkline)}
        .benefit>span{font-size:10px;color:#9a7524;letter-spacing:.15em}
        .benefit i{display:block;font-size:25px;margin:23px 0;color:#9a7524}
        .benefit h3{font:700 20px Manrope}
        .benefit p{font-size:12px;line-height:1.8;color:#737a72;max-width:300px}

        .materials-photo{width:100%;height:390px;object-fit:cover;filter:grayscale(.25)}
        .photo-caption{font-size:9px;color:#a8afa7;letter-spacing:.18em;margin-top:12px}
        .material-list{list-style:none;padding:0;margin:40px 0 0;border-top:1px solid var(--line)}
        .material-list li{padding:17px 0;border-bottom:1px solid var(--line);font:500 17px Manrope}
        .material-list b{font-size:10px;color:var(--gold);margin-right:22px}

        .sustain-section{background:#deded5}
        .sustain-points>div{display:flex;gap:25px;border-top:1px solid var(--darkline);padding:20px 0}
        .sustain-points>div>b{font:700 13px Manrope;color:#a17a24}
        .sustain-points span{font-size:12px;line-height:1.7;color:#687067}
        .sustain-points strong{display:block;color:#17201a;font:700 16px Manrope;margin-bottom:5px}
        .sustain-banner{margin-top:70px;min-height:260px;
            background:linear-gradient(90deg,rgba(13,20,16,.88),rgba(13,20,16,.45));
            display:flex;flex-direction:column;justify-content:center;
            padding:45px 7%;color:#fff}
        .sustain-banner span{font-size:9px;letter-spacing:.2em;color:var(--gold);margin-bottom:18px}
        .sustain-banner b{font:800 clamp(28px,5vw,54px)/1.04 Manrope;letter-spacing:-.05em}

        .process-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin:60px 0}
        .process-step{border-top:1px solid var(--gold);padding-top:18px}
        .process-step>b{font:700 12px Manrope;color:var(--gold)}
        .process-step h3{font:700 19px Manrope;margin:28px 0 10px}
        .process-step p{font-size:12px;line-height:1.8;color:#a9b1a9}

        .faq-block{border-top:1px solid var(--line);padding-top:35px;max-width:850px}
        .faq-block>h3{font:700 22px Manrope;margin-bottom:20px}
        .accordion-item{background:transparent !important;color:#fff !important;
            border-bottom:1px solid var(--line) !important}
        .accordion-button{background:transparent !important;color:#fff !important;
            box-shadow:none !important;font:600 14px Manrope;padding:20px 0}
        .accordion-button:after{filter:invert(1)}
        .accordion-body{color:#adb5ad;font-size:12px;line-height:1.8;padding:0 0 20px}

        .plant-section{background:#e8e7e0}
        .plant-intro{max-width:320px}
        .plant-grid{display:grid;grid-template-columns:1.1fr 1fr 1fr;
            grid-auto-rows:220px;gap:16px;margin-top:45px}
        .plant-item{position:relative;margin:0;overflow:hidden;background:#c9cbc3}
        .plant-item.tall{grid-row:span 2}
        .plant-item.wide{grid-column:span 2}
        .plant-item img{width:100%;height:100%;object-fit:cover;
            transition:transform .8s;filter:saturate(.75)}
        .plant-item:hover img{transform:scale(1.06)}
        .plant-item:after{content:"";position:absolute;inset:40% 0 0;
            background:linear-gradient(transparent,rgba(0,0,0,.8))}
        .plant-item figcaption{position:absolute;z-index:1;bottom:18px;
            left:20px;right:20px;color:#fff;display:flex;flex-direction:column;gap:6px}
        .plant-item figcaption b{font-size:8px;letter-spacing:.2em;color:var(--gold)}
        .plant-item figcaption span{font:600 14px Manrope}

        .delivery-panel{background:#17201b;color:#fff;margin-top:35px;padding:35px;
            display:grid;grid-template-columns:2fr 1fr 1fr;gap:25px;align-items:center}
        .delivery-panel>div:first-child>span{font-size:9px;letter-spacing:.16em;color:var(--gold)}
        .delivery-panel h3{font:700 27px Manrope;margin:15px 0 0}
        .fleet-detail{border-left:1px solid var(--line);padding-left:22px}
        .fleet-detail b,.fleet-detail span{display:block}
        .fleet-detail b{font-size:11px;letter-spacing:.1em}
        .fleet-detail span{font-size:12px;color:#abb2ab;margin-top:7px}
        .delivery-panel .small-note{grid-column:1/-1;margin:0}

        .quality-section{background:#121916}
        .document-card{background:#202824;border:1px solid var(--line);padding:18px;height:100%}
        .doc-head{display:flex;justify-content:space-between;color:var(--gold);
            font-size:9px;letter-spacing:.15em;margin-bottom:15px}
        .doc-head i{font-size:18px}
        .document-card>img{width:100%;height:330px;object-fit:contain;background:#f4f4f1}
        .doc-foot{display:flex;justify-content:space-between;align-items:center;
            padding-top:18px;gap:15px}
        .doc-foot h3{font:700 18px Manrope}
        .doc-foot p{font-size:11px;color:#aab2aa;margin:0}
        .doc-link{color:var(--gold);font-size:11px;letter-spacing:.12em;
            text-transform:uppercase;white-space:nowrap}
        .report-data{margin-top:50px;border-top:1px solid var(--line);padding-top:30px}
        .report-data h3{font:700 20px Manrope;margin-bottom:25px}
        .report-data .row b{font:800 30px Manrope;color:var(--gold);display:block}
        .report-data .row span{display:block;color:#aab2aa;font-size:11px;line-height:1.5;margin-top:5px}
        .report-data .small-note{margin-top:25px}

        .customers-section{background:#efeee8}
        .customer-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:45px 0}
        .customer-grid>div{min-height:130px;background:#fff;border:1px solid #deded5;
            display:flex;align-items:center;justify-content:center;text-align:center;
            padding:15px;font:800 14px Manrope;color:#34423a;letter-spacing:.02em}
        .customer-grid small{font-size:8px;letter-spacing:.15em}
        .customer-grid .lt-logo{font-style:italic;color:#1b6093}
        .customer-photo{height:230px;overflow:hidden}
        .customer-photo img{width:100%;height:100%;object-fit:contain;background:#fff}

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

        .reels-section{background:var(--ink);color:#fff;overflow:hidden}
        .reels-intro{display:flex;justify-content:space-between;align-items:flex-end;
            gap:30px;flex-wrap:wrap;margin-bottom:45px}
        .reels-intro .section-intro{max-width:400px}
        .reels-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
        .reel-card{position:relative;margin:0;aspect-ratio:9/16;overflow:hidden;
            background:#202824;border:1px solid rgba(255,255,255,.1);
            cursor:pointer;transition:border-color .4s,transform .4s}
        .reel-card:hover{border-color:rgba(212,169,67,.7);transform:translateY(-6px)}
        .reel-card video,.reel-card img{position:absolute;inset:0;width:100%;height:100%;
            object-fit:cover;transition:transform .8s;filter:saturate(.9) brightness(.85)}
        .reel-card:hover video,.reel-card:hover img{transform:scale(1.05)}
        .reel-card:after{content:"";position:absolute;inset:35% 0 0;
            background:linear-gradient(transparent,rgba(0,0,0,.9));pointer-events:none;z-index:1}
        .reel-tag{position:absolute;z-index:2;top:14px;left:14px;
            background:rgba(17,23,21,.85);backdrop-filter:blur(6px);
            border:1px solid rgba(212,169,67,.5);color:var(--gold);
            font:700 8px Manrope;letter-spacing:.15em;padding:6px 10px;text-transform:uppercase}
        .reel-meta{position:absolute;z-index:2;bottom:0;left:0;right:0;padding:18px;
            display:flex;flex-direction:column;gap:5px}
        .reel-meta span{font-size:8px;letter-spacing:.18em;color:var(--gold);text-transform:uppercase}
        .reel-meta b{font:700 13px Manrope;line-height:1.3}
        .reel-meta small{font-size:10px;color:#aab2aa;letter-spacing:.04em}
        .reels-footer{display:flex;justify-content:center;margin-top:45px}
        .reels-footer a{display:inline-flex;align-items:center;gap:14px;
            padding:15px 26px;background:var(--gold);color:#161a17;
            font:700 11px Manrope;letter-spacing:.14em;text-transform:uppercase;transition:.3s}
        .reels-footer a:hover{background:#f0c766;transform:translateY(-3px);color:#111}

        /* ===== RESPONSIVE ===== */
        @media(max-width:991px){
            .hero{height:auto;min-height:auto;align-items:flex-start;padding-top:150px;padding-bottom:60px}
            .hero-content{padding-top:15px}
            .hero-bottom{position:relative;left:auto;right:auto;bottom:auto;
                margin-top:50px;padding:0 12px}
            .hero-vertical{display:none}
            .hero-brick-wrap{min-height:380px;margin-top:40px}
            .brick-scene{transform:scale(.85)}
            .nav-cta{display:inline-flex;margin:10px 0}
            .navbar-collapse{padding-top:15px}
            .section{padding:85px 0}
            .delivery-panel{grid-template-columns:1fr 1fr}
            .delivery-panel>div:first-child{grid-column:1/-1}
            .customer-grid{grid-template-columns:repeat(3,1fr)}
            .reels-grid{grid-template-columns:repeat(2,1fr)}
        }
        @media(max-width:767px){
            .hero{min-height:auto;padding-top:130px;padding-bottom:50px}
            .hero h1{font-size:clamp(53px,15vw,82px)}
            .hero-brick-wrap{min-height:320px;margin-top:30px}
            .brick-scene{transform:scale(.7)}
            .hero-bottom{font-size:7px}
            .stat-row{grid-template-columns:repeat(2,1fr);row-gap:28px}
            .section-title{font-size:48px}
            .about-image{height:300px}
            .benefit,.benefit:nth-child(3n+2),.benefit:nth-child(3n){
                padding:25px 10px;border-left:0}
            .process-steps{grid-template-columns:repeat(2,1fr);row-gap:35px}
            .plant-grid{grid-template-columns:1fr 1fr;grid-auto-rows:190px}
            .plant-item.tall{grid-row:span 1}
            .plant-item.wide{grid-column:span 2}
            .delivery-panel{grid-template-columns:1fr}
            .delivery-panel>div:first-child{grid-column:auto}
            .fleet-detail{border-left:0;border-top:1px solid var(--line);padding:15px 0 0}
            .customer-grid{grid-template-columns:repeat(2,1fr)}
            .customer-grid>div{min-height:105px;font-size:11px}
            .footer-top{flex-wrap:wrap;gap:22px}
            .footer-top>p{order:3;width:100%}
            .footer-bottom{flex-direction:column}
            .enquiry-form{padding:23px}
            .dimension-visual{height:350px}
            .dimension-brick{transform:scale(.78) rotate(-20deg) skewY(3deg)}
            .dim-width{right:4%}
            .dim-height{left:5%}
            .reels-grid{grid-template-columns:1fr;max-width:340px;margin:0 auto}
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



        /* ============================================================
   CLIENTS / CUSTOMERS SECTION — Redesigned (.cl-*)
   ============================================================ */
.cl-showcase{
    position:relative;
    padding:120px 0 130px;
    background:#0e1411;
    color:#fff;
    overflow:hidden;
    isolation:isolate;
}
.cl-bg-grid{
    position:absolute;inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);
    background-size:70px 70px;
    mask-image:radial-gradient(ellipse at center,#000 30%,transparent 75%);
    z-index:0;
    pointer-events:none;
}
.cl-bg-glow{
    position:absolute;
    width:680px;height:680px;border-radius:50%;
    top:-180px;right:-180px;
    background:radial-gradient(circle,rgba(212,169,67,.18),transparent 65%);
    filter:blur(30px);
    z-index:0;
    pointer-events:none;
}
.cl-wrap{position:relative;z-index:1}

/* ---------- HEADER ---------- */
.cl-head{
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:50px;
    align-items:end;
    margin-bottom:70px;
}
.cl-tag{
    display:inline-flex;align-items:center;gap:10px;
    font-size:10px;letter-spacing:.22em;font-weight:700;
    color:var(--gold);
    padding:8px 14px;
    border:1px solid rgba(212,169,67,.35);
    background:rgba(212,169,67,.06);
    border-radius:100px;
    margin-bottom:22px;
}
.cl-tag i{font-size:12px}

.cl-title{
    font:800 clamp(38px,5.5vw,68px)/1.02 Manrope;
    letter-spacing:-.07em;
    margin:0;
    color:#fff;
}
.cl-title em{
    font-family:Georgia,serif;font-weight:400;
    font-style:italic;
    color:var(--gold);
    letter-spacing:-.04em;
}
.cl-head-right{padding-bottom:10px}
.cl-lead{
    font-size:14px;line-height:1.85;
    color:#a9b1a9;
    margin:0 0 22px;
    max-width:420px;
}
.cl-cta{
    display:inline-flex;align-items:center;gap:12px;
    padding:12px 22px;
    font-size:11px;font-weight:700;
    letter-spacing:.14em;text-transform:uppercase;
    color:#141a16;
    background:var(--gold);
    border-radius:2px;
    transition:.25s;
}
.cl-cta:hover{
    background:#f0c766;color:#111;
    transform:translateY(-2px);
}

/* ---------- STATS STRIP ---------- */
.cl-stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:0;
    border-top:1px solid rgba(255,255,255,.1);
    border-bottom:1px solid rgba(255,255,255,.1);
    margin-bottom:70px;
}
.cl-stat{
    padding:26px 24px;
    display:flex;flex-direction:column;gap:6px;
    border-right:1px solid rgba(255,255,255,.1);
}
.cl-stat:last-child{border-right:0}
.cl-stat b{
    font:800 clamp(26px,3vw,38px)/1 Manrope;
    color:var(--gold);
    letter-spacing:-.05em;
}
.cl-stat span{
    font-size:9px;letter-spacing:.18em;color:#8c948c;
    font-weight:600;
}

/* ---------- CARD GRID ---------- */
.cl-grid{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:14px;
    margin-bottom:70px;
}
.cl-card{
    position:relative;
    padding:24px 22px 22px;
    background:linear-gradient(160deg,rgba(32,40,36,.85),rgba(20,27,23,.85));
    border:1px solid rgba(255,255,255,.08);
    border-radius:6px;
    overflow:hidden;
    transition:transform .35s, border-color .35s, background .35s;
    display:flex;flex-direction:column;gap:16px;
    min-height:180px;
}
.cl-card:before{
    content:"";
    position:absolute;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,transparent,var(--gold),transparent);
    opacity:0;
    transition:opacity .35s;
}
.cl-card:hover{
    transform:translateY(-6px);
    border-color:rgba(212,169,67,.45);
    background:linear-gradient(160deg,rgba(38,48,42,.9),rgba(24,32,27,.9));
}
.cl-card:hover:before{opacity:1}

.cl-card--featured{
    border-color:rgba(212,169,67,.35);
    background:linear-gradient(160deg,rgba(50,58,48,.9),rgba(28,36,30,.9));
}
.cl-card--featured:before{opacity:1}

.cl-card-icon{
    width:38px;height:38px;
    display:grid;place-items:center;
    background:rgba(212,169,67,.12);
    border:1px solid rgba(212,169,67,.3);
    border-radius:6px;
    color:var(--gold);
    font-size:16px;
}
.cl-card-meta{margin-top:auto}
.cl-card-type{
    font-size:8px;letter-spacing:.22em;font-weight:700;
    color:var(--gold);
    display:block;margin-bottom:6px;
}
.cl-card-name{
    font:700 15px/1.3 Manrope;
    color:#fff;
    margin:0 0 4px;
    letter-spacing:-.01em;
}
.cl-card-note{
    font-size:10.5px;
    color:#8c948c;
    margin:0;
    line-height:1.5;
}
.cl-card-arrow{
    position:absolute;top:18px;right:18px;
    width:24px;height:24px;border-radius:50%;
    display:grid;place-items:center;
    background:rgba(255,255,255,.05);
    border:1px solid rgba(255,255,255,.1);
    color:#a9b1a9;
    font-size:10px;
    transition:.3s;
}
.cl-card:hover .cl-card-arrow{
    background:var(--gold);
    border-color:var(--gold);
    color:#111;
    transform:rotate(45deg);
}

/* ---------- MARQUEE ---------- */
.cl-marquee{
    overflow:hidden;
    border-top:1px solid rgba(255,255,255,.08);
    border-bottom:1px solid rgba(255,255,255,.08);
    padding:20px 0;
    margin-bottom:70px;
}
.cl-marquee-track{
    display:flex;gap:36px;
    white-space:nowrap;
    width:max-content;
    animation:clMarquee 32s linear infinite;
    font:800 11px Manrope;
    letter-spacing:.22em;
    color:rgba(255,255,255,.35);
}
.cl-marquee-track i{
    font-style:normal;
    color:var(--gold);
    opacity:.55;
}
@keyframes clMarquee{to{transform:translateX(-50%)}}

/* ---------- PHOTO STRIP ---------- */
.cl-photo-strip{
    display:grid;
    grid-template-columns:2fr 1fr 1fr;
    gap:14px;
}
.cl-photo{
    position:relative;
    margin:0;
    border-radius:6px;
    overflow:hidden;
    background:#202824;
    border:1px solid rgba(255,255,255,.08);
    aspect-ratio:16/10;
    transition:.4s;
}
.cl-photo--wide{aspect-ratio:auto}
.cl-photo:hover{
    border-color:rgba(212,169,67,.4);
    transform:translateY(-3px);
}
.cl-photo-fallback{
    width:100%;height:100%;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    gap:10px;
    color:rgba(255,255,255,.55);
    font-size:10px;
    letter-spacing:.18em;
    font-weight:600;
    transition:transform .6s;
}
.cl-photo-fallback i{font-size:24px;opacity:.7}
.cl-photo:hover .cl-photo-fallback{transform:scale(1.04)}
.cl-photo:after{
    content:"";
    position:absolute;inset:45% 0 0;
    background:linear-gradient(transparent,rgba(0,0,0,.75));
    pointer-events:none;
}
.cl-photo figcaption{
    position:absolute;bottom:16px;left:18px;right:18px;
    z-index:1;display:flex;flex-direction:column;gap:3px;
}
.cl-photo figcaption b{
    font-size:8px;letter-spacing:.22em;font-weight:700;
    color:var(--gold);
}
.cl-photo figcaption span{
    font-size:12px;color:#fff;font-weight:500;
}

/* ---------- RESPONSIVE ---------- */
@media (max-width:1100px){
    .cl-grid{grid-template-columns:repeat(3,1fr)}
    .cl-head{grid-template-columns:1fr;gap:26px}
    .cl-head-right{padding-bottom:0}
}
@media (max-width:900px){
    .cl-showcase{padding:90px 0 100px}
    .cl-stats{grid-template-columns:repeat(2,1fr)}
    .cl-stat:nth-child(2){border-right:0}
    .cl-stat:nth-child(1),.cl-stat:nth-child(2){border-bottom:1px solid rgba(255,255,255,.1)}
    .cl-photo-strip{grid-template-columns:1fr 1fr}
    .cl-photo--wide{grid-column:span 2;aspect-ratio:16/9}
}
@media (max-width:640px){
    .cl-showcase{padding:70px 0 80px}
    .cl-title{font-size:clamp(32px,9vw,44px)}
    .cl-grid{grid-template-columns:1fr}
    .cl-card{min-height:auto;padding:20px}
    .cl-marquee-track{font-size:9px;gap:24px}
    .cl-photo-strip{grid-template-columns:1fr;gap:10px}
    .cl-photo--wide{grid-column:auto}
    .cl-photo{aspect-ratio:16/10}
}
.cl-card-icon--logo{
    background:#0f1512;
    border:1px solid rgba(255,255,255,.08);
    padding:6px;
    display:flex;align-items:center;justify-content:center;
}
.cl-card-icon--logo img{
    width:100%;height:100%;
    object-fit:contain;
    filter:brightness(.95);
    transition:transform .3s;
}
.cl-card:hover .cl-card-icon--logo img{transform:scale(1.06)}
.cl-photo img{
    width:100%;height:100%;
    object-fit:cover;
    transition:transform .6s;
}
.cl-photo:hover img{transform:scale(1.04)}
/* reels: ensure the <a> wrapper behaves like the old card */
a.reel-card{display:block;color:inherit;text-decoration:none}
a.reel-card:hover{color:inherit}
.reel-fallback{position:absolute;inset:0;
    background:linear-gradient(145deg,#3b4b3f,#1c2a1f)}




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
                    <li class="nav-item"><a class="nav-link" href="#process">Process</a></li>
                    <li class="nav-item"><a class="nav-link" href="#plant">Our Plant</a></li>
                    <li class="nav-item"><a class="nav-link" href="#quality">Quality</a></li>
                    <li class="nav-item ms-lg-3"><a class="nav-cta" href="#contact">Enquire Now <i class="bi bi-arrow-up-right"></i></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main>

        <!-- ================= HERO (row/col layout, image stacks below on mobile) ================= -->
        <section class="hero" id="home">
            <div class="hero-grid"></div>
            <div class="hero-glow"></div>
            <div class="container hero-content">
                <div class="row align-items-center g-4 g-lg-5">

                    <!-- Text column -->
                    <div class="col-12 col-lg-7">
                        <div class="eyebrow reveal"><span></span> ENGINEERED FOR EVERY FOUNDATION</div>
                        <h1 class="reveal">BUILD WITH<br><em>CONFIDENCE.</em></h1>
                        <p class="hero-copy reveal">A stronger foundation for the places where life happens. Discover WOOLF fly ash bricks and concrete blocks, manufactured for modern construction.</p>
                        <div class="d-flex flex-wrap gap-3 reveal">
                            <a class="btn-gold" href="#products">Explore Products <i class="bi bi-arrow-down-right"></i></a>
                            <a class="btn-outline-light" href="#contact">Request a Quote <i class="bi bi-arrow-up-right"></i></a>
                        </div>
                    </div>

                    <!-- Brick image / visual column -->
                    <div class="col-12 col-lg-5">
                        <div class="hero-brick-wrap reveal" aria-label="WOOLF fly ash brick">
                            
                                <!-- Replace the 3D brick below with your image, e.g.: -->
                                <img src="uploads/Carved Granite Stone Sign.png" alt="WOOLF fly ash brick" class="hero-brick-img">
                           
                            <!-- <div class="brick-scene">
                                <div class="brick3d">
                                    <div class="brick-face front"><span>WOOLF</span></div>
                                    <div class="brick-face top"></div>
                                    <div class="brick-face side"></div>
                                </div>
                            </div>
                            <div class="brick-caption">MATERIAL STUDY <b>01</b></div>
                        </div> -->
                    </div>

                </div>

                <div class="hero-bottom"><span>01 / 04 — WOOLF BRICKS & FLY ASH</span><span>SURAT · GUJARAT · INDIA</span></div>
            </div>
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

        <!-- ================= CONTACT ================= -->
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

                                <!-- HONEYPOT — obscured name so browsers won't autofill -->
                                <div class="hp-field" aria-hidden="true">
                                    <label for="hp_field_xz">Leave this empty</label>
                                    <input type="text" id="hp_field_xz" name="hp_field_xz"
                                           tabindex="-1" autocomplete="new-password">
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
            <!-- FLY ASH BRICKS -->
            <div class="col-12 col-lg-7">
                <div class="product-card reveal">
                    <div class="product-top"><span>01</span><span>FLY ASH BRICKS</span></div>
                    <div class="product-visual">
                        <img src="uploads/flyash.jpeg"
                             alt="WOOLF fly ash brick"
                             class="product-img"
                             loading="lazy">
                    </div>
                    <div class="product-info">
                        <div>
                            <h3>Fly Ash Bricks</h3>
                            <p>Uniform shape, high compressive strength and excellent thermal insulation — an eco-friendly alternative to traditional clay bricks.</p>
                            <div class="size-tags"><span>230×110×75 mm</span><span>230×110×100 mm</span></div>
                        </div>
                        <a href="#contact" class="round-arrow"><i class="bi bi-arrow-up-right"></i></a>
                    </div>
                </div>
            </div>
            <!-- CONCRETE BLOCKS -->
            <div class="col-12 col-lg-5">
                <div class="product-card product-secondary reveal">
                    <div class="product-top"><span>02</span><span>CONCRETE BLOCKS</span></div>
                    <div class="block-visual">
                        <img src="uploads/hollow.jpeg"
                             alt="WOOLF hollow concrete block"
                             class="product-img"
                             loading="lazy">
                    </div>
                    <div class="product-info">
                        <div>
                            <h3 style="margin-top:14% !important;">Hollow Blocks</h3>
                            <p>Lightweight, strong and quick to install — ideal for partition walls and framed structures.</p>
                            <div class="size-tags"><span>230×110×75 mm</div>
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
            <div class="col-12 col-lg-5">
                <div class="eyebrow dark reveal">03 / TECHNICAL SPECS</div>
                <h2 class="section-title reveal">Measured for<br><em>precision.</em></h2>
                <div class="spec-list reveal">
                    <div><span>COMPRESSIVE STRENGTH</span><b>8.5+</b><small>N/mm²</small></div>
                    <div><span>WATER ABSORPTION</span><b>&lt;20%</b><small>by weight</small></div>
                    <div><span>DIMENSIONAL TOLERANCE</span><b>±2</b><small>mm</small></div>
                    <div><span>THERMAL CONDUCTIVITY</span><b>0.25</b><small>W/m·K</small></div>
                </div>
            </div>
            <div class="col-12 col-lg-7">
                <div class="dimension-visual reveal">
                    <!-- Replace with your own brick image -->
                    <img src="uploads/Carved Granite Stone Sign.png"
                         alt="WOOLF fly ash brick with dimensions 230×110×75 mm"
                         class="dimension-img"
                         loading="lazy">

                    <!-- Dimension overlay labels -->
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

        <!-- ================= BENEFITS ================= -->
        <section class="section benefits-section" id="benefits">
            <div class="container">
                <div class="eyebrow dark reveal">04 / WHY WOOLF</div>
                <h2 class="section-title reveal">Built for<br><em>better living.</em></h2>
                <div class="benefits-grid row g-0 reveal">
                    <div class="col-md-4 benefit"><span>01</span><i class="bi bi-shield-check"></i><h3>High strength</h3><p>Compressive strength exceeding industry norms for load-bearing walls.</p></div>
                    <div class="col-md-4 benefit"><span>02</span><i class="bi bi-droplet"></i><h3>Low absorption</h3><p>Dense composition reduces water penetration and efflorescence.</p></div>
                    <div class="col-md-4 benefit"><span>03</span><i class="bi bi-rulers"></i><h3>Accurate size</h3><p>Uniform dimensions reduce mortar consumption and speed up construction.</p></div>
                    <div class="col-md-4 benefit"><span>04</span><i class="bi bi-thermometer-sun"></i><h3>Thermal insulation</h3><p>Keeps interiors cooler, reducing energy costs over the building's life.</p></div>
                    <div class="col-md-4 benefit"><span>05</span><i class="bi bi-tree"></i><h3>Eco-friendly</h3><p>Manufactured from recycled fly ash, reducing landfill waste.</p></div>
                    <div class="col-md-4 benefit"><span>06</span><i class="bi bi-clock-history"></i><h3>Durable</h3><p>Engineered for long-term performance in demanding climates.</p></div>
                </div>
            </div>
        </section>

        <!-- ================= MATERIALS ================= -->
        <section class="section materials-section" id="materials">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-12 col-lg-6">
                <div class="eyebrow light reveal">05 / MATERIALS</div>
                <h2 class="section-title light reveal">Sourced with<br><em>purpose.</em></h2>
                <p class="section-intro reveal">Every input is selected for performance and sustainability.</p>
                <ul class="material-list reveal">
                    <li><b>01</b> Fly ash from thermal power plants</li>
                    <li><b>02</b> Cement (OPC / PPC)</li>
                    <li><b>03</b> Fine aggregates and stone dust</li>
                    <li><b>04</b> Gypsum and lime additives</li>
                    <li><b>05</b> Water (potable quality)</li>
                </ul>
            </div>
            <div class="col-12 col-lg-6">
                <div class="materials-visual reveal">
                    <!-- Replace with your own materials / sourcing image -->
                    <img src="uploads/flyash.jpeg"
                         alt="Fly ash and raw materials used at the WOOLF plant"
                         class="materials-img"
                         loading="lazy">
                </div>
                <p class="photo-caption reveal">FLY ASH RECYCLING · SUSTAINABLE INPUTS</p>
            </div>
        </div>
    </div>
</section>
        <!-- ================= SUSTAINABILITY ================= -->
        <section class="section sustain-section" id="sustainability">
            <div class="container">
                <div class="row g-5">
                    <div class="col-lg-5">
                        <div class="eyebrow dark reveal">06 / SUSTAINABILITY</div>
                        <h2 class="section-title reveal">Less waste.<br><em>More future.</em></h2>
                        <p class="lead-copy reveal">Fly ash bricks divert waste from landfills and reduce the demand for clay, sand and energy-intensive materials.</p>
                    </div>
                    <div class="col-lg-6 offset-lg-1">
                        <div class="sustain-points reveal">
                            <div><b>01</b><span><strong>Recycled content</strong>Up to 60% fly ash by weight, turning a byproduct into a building resource.</span></div>
                            <div><b>02</b><span><strong>Lower carbon footprint</strong>No kiln firing required — less energy and CO₂ compared to clay bricks.</span></div>
                            <div><b>03</b><span><strong>Resource efficiency</strong>Conserves topsoil and reduces pressure on natural sand reserves.</span></div>
                        </div>
                    </div>
                </div>
                <div class="sustain-banner reveal">
                    <span>WOOLF COMMITMENT</span>
                    <b>Building performance<br>without compromising the planet.</b>
                </div>
            </div>
        </section>

        <!-- ================= PROCESS ================= -->
        <section class="section process-section" id="process">
            <div class="container">
                <div class="row">
                    <div class="col-lg-6">
                        <div class="eyebrow light reveal">07 / PROCESS</div>
                        <h2 class="section-title light reveal">From ash<br>to <em>architecture.</em></h2>
                    </div>
                </div>
                <div class="process-steps">
                    <div class="process-step reveal"><b>01</b><h3>Material prep</h3><p>Fly ash, cement and aggregates are weighed and blended to precise ratios.</p></div>
                    <div class="process-step reveal"><b>02</b><h3>Mixing</h3><p>Controlled water addition and thorough mixing ensure consistent workability.</p></div>
                    <div class="process-step reveal"><b>03</b><h3>Moulding</h3><p>High-pressure pressing forms dense, accurately dimensioned bricks.</p></div>
                    <div class="process-step reveal"><b>04</b><h3>Curing</h3><p>Water curing for 14–21 days develops full compressive strength.</p></div>
                </div>
                <div class="faq-block reveal">
                    <h3>Frequently asked questions</h3>
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">What is the minimum order quantity?</button></h2><div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Minimum order varies by product and location. Please contact us with your requirements for a tailored quote.</div></div></div>
                        <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">Do you deliver outside Surat?</button></h2><div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Yes, we deliver across Gujarat and nearby states. Delivery charges depend on distance and quantity.</div></div></div>
                        <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">Are the bricks suitable for load-bearing walls?</button></h2><div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body">Yes, our fly ash bricks are designed for structural applications and meet relevant strength requirements.</div></div></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================= PLANT ================= -->
        <!-- <section class="section plant-section" id="plant">
            <div class="container">
                <div class="row">
                    <div class="col-lg-5">
                        <div class="eyebrow dark reveal">08 / OUR PLANT</div>
                        <h2 class="section-title reveal">Where it<br><em>all takes shape.</em></h2>
                    </div>
                    <div class="col-lg-5 offset-lg-2 d-flex align-items-end">
                        <p class="section-intro plant-intro reveal">Two facilities in Surat, equipped for consistent, high-volume production with strict quality checks.</p>
                    </div>
                </div>
                <div class="plant-grid">
                    <div class="plant-item tall reveal" style="background:linear-gradient(145deg,#5d6b5f,#2b382e);display:flex;align-items:center;justify-content:center;color:#fff;font-size:35px;">Production Hall</div>
                    <div class="plant-item wide reveal" style="background:linear-gradient(145deg,#7b8b7b,#3b4b3d);display:flex;align-items:center;justify-content:center;color:#fff;font-size:35px;">Brick Press</div>
                    <div class="plant-item reveal" style="background:linear-gradient(145deg,#4b5b4f,#1f2e23);display:flex;align-items:center;justify-content:center;color:#fff;font-size:35px;">Curing Area</div>
                    <div class="plant-item reveal" style="background:linear-gradient(145deg,#6b7b6b,#2b3b2d);display:flex;align-items:center;justify-content:center;color:#fff;font-size:35px;">Storage</div>
                </div>
                <div class="delivery-panel reveal">
                    <div><span>LOGISTICS</span><h3>Direct from our plant to your site.</h3></div>
                    <div class="fleet-detail"><b>FLEET</b><span>Trucks &amp; trailers</span></div>
                    <div class="fleet-detail"><b>COVERAGE</b><span>Gujarat &amp; nearby</span></div>
                    <p class="small-note">Delivery scheduling and unloading support can be arranged on request.</p>
                </div>
            </div>
        </section> -->

        <!-- ================= GALLERY / PLANT (DB-driven) ================= -->
<?php if ($gallerySection): ?>
<section class="section plant-section" id="plant">
    <div class="container">
        <div class="row">
            <div class="col-12 col-lg-5">
                <div class="eyebrow dark reveal"><?= htmlspecialchars($gallerySection['eyebrow']) ?></div>
                <h2 class="section-title reveal">
                    <?= htmlspecialchars($gallerySection['title_line1']) ?><br>
                    <em><?= htmlspecialchars($gallerySection['title_emphasis']) ?></em>
                </h2>
            </div>
            <div class="col-12 col-lg-5 offset-lg-2 d-flex align-items-end">
                <p class="section-intro plant-intro reveal"><?= nl2br(htmlspecialchars($gallerySection['intro_copy'])) ?></p>
            </div>
        </div>

        <?php if (!empty($galleryImages)): ?>
        <div class="plant-grid">
            <?php foreach ($galleryImages as $g):
                $layoutClass = in_array($g['layout'], ['tall','wide'], true) ? ' ' . $g['layout'] : '';
                $imgUrl = htmlspecialchars($g['image_url']);
                $alt    = htmlspecialchars($g['image_alt'] ?: 'WOOLF plant photo');
            ?>
                <figure class="plant-item<?= $layoutClass ?> reveal">
                    <img src="<?= $imgUrl ?>" alt="<?= $alt ?>" loading="lazy">
                    <?php if (!empty($g['caption'])): ?>
                        <figcaption>
                            <b>GALLERY</b>
                            <span><?= htmlspecialchars($g['caption']) ?></span>
                        </figcaption>
                    <?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="delivery-panel reveal">
            <div><span>LOGISTICS</span><h3>Direct from our plant to your site.</h3></div>
            <div class="fleet-detail"><b>FLEET</b><span>Trucks &amp; trailers</span></div>
            <div class="fleet-detail"><b>COVERAGE</b><span>Gujarat &amp; nearby</span></div>
            <p class="small-note">Delivery scheduling and unloading support can be arranged on request.</p>
        </div>
    </div>
</section>
<?php endif; ?>

        <!-- ================= QUALITY ================= -->
        <section class="section quality-section" id="quality">
    <div class="container">
        <div class="row g-5">
            <div class="col-12 col-lg-5">
                <div class="eyebrow light reveal">09 / QUALITY</div>
                <h2 class="section-title light reveal">Tested.<br><em>Trusted.</em></h2>
                <p class="section-intro reveal">Every batch is checked for strength, dimensions and absorption before dispatch.</p>
            </div>
            <div class="col-12 col-lg-6 offset-lg-1">
                <div class="document-card reveal">
                    <div class="doc-head"><span>TEST REPORT</span><i class="bi bi-file-earmark-text"></i></div>

                    <!-- Replace with your own quality / test-report image -->
                    <img src="uploads/Carved Granite Stone Sign.png"
                         alt="WOOLF compressive strength test report"
                         class="document-img"
                         loading="lazy">

                    <div class="doc-foot">
                        <div>
                            <h3>Compressive strength</h3>
                            <p>7-day and 28-day results</p>
                        </div>
                        <a href="#contact" class="doc-link">Request report <i class="bi bi-arrow-up-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="report-data reveal">
            <h3>Latest batch results</h3>
            <div class="row g-4">
                <div class="col-4"><b>8.2</b><span>N/mm² average 28-day strength</span></div>
                <div class="col-4"><b>6%</b><span>Water absorption</span></div>
                <div class="col-4"><b>±1.5</b><span>mm dimensional deviation</span></div>
            </div>
            <p class="small-note">Data shown is representative. Batch-specific reports available on request.</p>
        </div>
    </div>
</section>

       <!-- ================= CLIENTS / CUSTOMERS (REDESIGNED) ================= -->
<!-- ================= CUSTOMERS / PARTNERS (DB-driven) ================= -->
<?php if ($custSection): ?>
<section class="cl-showcase" id="clients">
    <div class="cl-bg-grid" aria-hidden="true"></div>
    <div class="cl-bg-glow" aria-hidden="true"></div>

    <div class="container cl-wrap">

        <!-- Header -->
        <div class="cl-head">
            <div class="cl-head-left">
                <span class="cl-tag reveal">
                    <i class="bi bi-patch-check-fill"></i>
                    <?= htmlspecialchars($custSection['tagline']) ?>
                </span>
                <h2 class="cl-title reveal">
                    <?= htmlspecialchars($custSection['title_line1']) ?><br>
                    <em><?= htmlspecialchars($custSection['title_emphasis']) ?></em>
                </h2>
            </div>
            <div class="cl-head-right">
                <p class="cl-lead reveal">
                    <?= nl2br(htmlspecialchars($custSection['lead_copy'])) ?>
                </p>
                <a href="<?= htmlspecialchars($custSection['cta_link']) ?>" class="cl-cta reveal">
                    <?= htmlspecialchars($custSection['cta_text']) ?>
                    <i class="bi bi-arrow-up-right"></i>
                </a>
            </div>
        </div>

        <!-- Stats strip -->
        <div class="cl-stats reveal">
            <?php for ($i = 1; $i <= 4; $i++):
                $v = $custSection["stat{$i}_value"] ?? '';
                $l = $custSection["stat{$i}_label"] ?? '';
                if ($v === '' && $l === '') continue;
            ?>
                <div class="cl-stat">
                    <b><?= htmlspecialchars($v) ?></b>
                    <span><?= htmlspecialchars($l) ?></span>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Client cards -->
        <?php if (!empty($custClients)): ?>
        <div class="cl-grid reveal">
            <?php foreach ($custClients as $c):
                $logo   = normalizeImg($c['logo_url'] ?? null);
                $icon   = $c['icon_class'] ?: 'bi-building';
                $isFeat = !empty($c['is_featured']);
                $type   = $c['client_type'] ?: 'CONTRACTOR';
            ?>
                <article class="cl-card <?= $isFeat ? 'cl-card--featured' : '' ?>">
                    <?php if ($logo): ?>
                        <div class="cl-card-icon cl-card-icon--logo">
                            <img src="<?= htmlspecialchars($logo) ?>"
                                 alt="<?= htmlspecialchars($c['client_name']) ?>"
                                 loading="lazy">
                        </div>
                    <?php else: ?>
                        <div class="cl-card-icon"><i class="bi <?= htmlspecialchars($icon) ?>"></i></div>
                    <?php endif; ?>

                    <div class="cl-card-meta">
                        <span class="cl-card-type">
                            <?= htmlspecialchars($type) ?><?= $isFeat ? ' · FEATURED' : '' ?>
                        </span>
                        <h3 class="cl-card-name"><?= htmlspecialchars($c['client_name']) ?></h3>
                        <?php if (!empty($c['client_note'])): ?>
                            <p class="cl-card-note"><?= htmlspecialchars($c['client_note']) ?></p>
                        <?php endif; ?>
                    </div>

                    <span class="cl-card-arrow"><i class="bi bi-arrow-up-right"></i></span>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Trust marquee -->
        <?php
        $marqueeItems = array_filter($custClients, fn($c) => !empty($c['show_marquee']));
        if (!empty($marqueeItems)):
            // Repeat twice for seamless loop
            $marqueeItems = array_merge($marqueeItems, $marqueeItems);
        ?>
        <div class="cl-marquee reveal" aria-hidden="true">
            <div class="cl-marquee-track">
                <?php foreach ($marqueeItems as $m):
                    $txt = $m['marquee_text'] ?: strtoupper($m['client_name']);
                ?>
                    <span><?= htmlspecialchars($txt) ?></span><i>✦</i>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Photo strip -->
        <?php
        $photos = [];
        for ($i = 1; $i <= 3; $i++) {
            $url = normalizeImg($custSection["photo{$i}_url"] ?? null);
            $tag = $custSection["photo{$i}_tag"] ?? '';
            $cap = $custSection["photo{$i}_caption"] ?? '';
            if ($url || $tag || $cap) {
                $photos[] = ['url'=>$url, 'tag'=>$tag, 'caption'=>$cap, 'num'=>$i];
            }
        }
        if (!empty($photos)):
        ?>
        <div class="cl-photo-strip reveal">
            <?php foreach ($photos as $idx => $p):
                $wide = ($idx === 0) ? 'cl-photo--wide' : '';
                $fallbackBg = [
                    1 => 'linear-gradient(135deg,#3b4b3f,#1c2a1f)',
                    2 => 'linear-gradient(135deg,#4b5b4f,#1f2e23)',
                    3 => 'linear-gradient(135deg,#5d6b5f,#2b382e)',
                ][$p['num']] ?? 'linear-gradient(135deg,#3b4b3f,#1c2a1f)';
            ?>
                <figure class="cl-photo <?= $wide ?>">
                    <?php if ($p['url']): ?>
                        <img src="<?= htmlspecialchars($p['url']) ?>"
                             alt="<?= htmlspecialchars($p['caption'] ?: $p['tag']) ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div class="cl-photo-fallback" style="background:<?= $fallbackBg ?>;">
                            <i class="bi bi-image"></i>
                            <span><?= htmlspecialchars($p['tag']) ?></span>
                        </div>
                    <?php endif; ?>
                    <figcaption>
                        <b><?= htmlspecialchars($p['tag']) ?></b>
                        <span><?= htmlspecialchars($p['caption']) ?></span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>
<?php endif; ?>
        <!-- ================= REELS ================= -->
     <!-- ================= REELS (DB-driven) ================= -->
<?php if ($reelsSection && !empty($reelsItems)): ?>
<section class="section reels-section" id="reels">
    <div class="container">
        <div class="reels-intro">
            <div>
                <div class="eyebrow light"><?= htmlspecialchars($reelsSection['eyebrow']) ?></div>
                <h2 class="section-title light">
                    <?= htmlspecialchars($reelsSection['title_line1']) ?><br>
                    <em><?= htmlspecialchars($reelsSection['title_emphasis']) ?></em>
                </h2>
            </div>
            <p class="section-intro"><?= nl2br(htmlspecialchars($reelsSection['intro_copy'])) ?></p>
        </div>

        <div class="reels-grid">
            <?php foreach ($reelsItems as $r):
                $hasVideo  = !empty($r['video_url']);
                $hasPoster = !empty($r['poster_url']);
                $hasLink   = !empty($r['link_url']);
                $href      = $hasLink ? htmlspecialchars($r['link_url']) : '#contact';
                $target    = $hasLink ? ' target="_blank" rel="noopener noreferrer"' : '';
            ?>
                <a class="reel-card reveal"
                   href="<?= $href ?>"<?= $target ?>
                   aria-label="<?= htmlspecialchars($r['title'] ?: 'Watch reel') ?>">

                    <?php if ($hasVideo): ?>
                        <video
                            src="<?= htmlspecialchars($r['video_url']) ?>"
                            <?php if ($hasPoster): ?>poster="<?= htmlspecialchars($r['poster_url']) ?>"<?php endif; ?>
                            autoplay muted loop playsinline preload="metadata"></video>
                    <?php elseif ($hasPoster): ?>
                        <img src="<?= htmlspecialchars($r['poster_url']) ?>" alt="<?= htmlspecialchars($r['title'] ?: '') ?>" loading="lazy">
                    <?php else: ?>
                        <div class="reel-fallback"></div>
                    <?php endif; ?>

                    <?php if (!empty($r['tag'])): ?>
                        <div class="reel-tag"><?= htmlspecialchars($r['tag']) ?></div>
                    <?php endif; ?>

                    <div class="reel-meta">
                        <?php if (!empty($r['meta_label'])): ?>
                            <span><?= htmlspecialchars($r['meta_label']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($r['title'])): ?>
                            <b><?= htmlspecialchars($r['title']) ?></b>
                        <?php endif; ?>
                        <?php if (!empty($r['subtitle'])): ?>
                            <small><?= htmlspecialchars($r['subtitle']) ?></small>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($reelsSection['cta_text'])): ?>
        <div class="reels-footer">
            <a href="<?= htmlspecialchars($reelsSection['cta_link'] ?: '#contact') ?>">
                <?= htmlspecialchars($reelsSection['cta_text']) ?>
                <i class="bi bi-arrow-up-right"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
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

        // Client-side validation — does NOT block submission
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
                    const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
                    if (!valid) { email.classList.add('is-invalid'); ok = false; }
                    else { email.classList.remove('is-invalid'); }
                }
                // Only block if the browser would block anyway (missing required fields)
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
