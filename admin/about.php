<?php
// admin/about.php — About + Gallery + Reels + Enquiries + Customers CRUD
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

$db  = getPDO();
$msg = '';
$err = '';

/* ============================================================
   UPLOAD HELPERS
   ============================================================ */
function handleImageUpload(string $fieldName, string $currentUrl = ''): string
{
    if (empty($_FILES[$fieldName]['name'])) return $currentUrl;
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) return $currentUrl;
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return $currentUrl;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'about_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = __DIR__ . '/../uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'uploads/' . $filename;
    }
    return $currentUrl;
}

function handleCustomerUpload(string $field, string $current = '', string $prefix = 'img'): string
{
    if (empty($_FILES[$field]['name'])) return $current;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return $current;
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml'];
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, $allowed, true)) return $current;
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = __DIR__ . '/../uploads/customers/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;
    if (move_uploaded_file($f['tmp_name'], $dest)) {
        if ($current && str_starts_with($current, 'uploads/customers/')) {
            @unlink(__DIR__ . '/../' . $current);
        }
        return 'uploads/customers/' . $filename;
    }
    return $current;
}

function handleGalleryUpload(string $field, string $current = ''): string
{
    if (empty($_FILES[$field]['name'])) return $current;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return $current;
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, $allowed, true)) return $current;
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $filename = 'gallery_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = __DIR__ . '/../uploads/gallery/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;
    if (move_uploaded_file($f['tmp_name'], $dest)) {
        if ($current && str_starts_with($current, 'uploads/gallery/')) {
            @unlink(__DIR__ . '/../' . $current);
        }
        return 'uploads/gallery/' . $filename;
    }
    return $current;
}

function handleReelUpload(string $field, string $current = '', string $kind = 'video'): string
{
    if (empty($_FILES[$field]['name'])) return $current;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return $current;

    if ($kind === 'video') {
        $allowed = ['video/mp4','video/webm','video/quicktime','video/x-m4v'];
    } else {
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    }
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, $allowed, true)) return $current;

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $prefix = $kind === 'video' ? 'reel_vid_' : 'reel_poster_';
    $filename = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    $dir = __DIR__ . '/../uploads/reels/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;

    if (move_uploaded_file($f['tmp_name'], $dest)) {
        if ($current && str_starts_with($current, 'uploads/reels/')) {
            @unlink(__DIR__ . '/../' . $current);
        }
        return 'uploads/reels/' . $filename;
    }
    return $current;
}

/* ============================================================
   SAFETY — status column on enquiries
   ============================================================ */
try {
    $db->query("SELECT status FROM enquiries LIMIT 0");
} catch (Throwable $e) {
    try {
        $db->exec("ALTER TABLE enquiries ADD COLUMN status ENUM('pending','contacted','archived') NOT NULL DEFAULT 'pending'");
    } catch (Throwable $e2) {}
}

/* ============================================================
   ACTIONS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---------- ABOUT: DELETE ---------- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare("SELECT image_url FROM about_section WHERE id = ?");
            $row->execute([$id]);
            $img = $row->fetchColumn();
            if ($img && str_starts_with($img, 'uploads/')) {
                @unlink(__DIR__ . '/../' . $img);
            }
            $db->prepare("DELETE FROM about_section WHERE id = ?")->execute([$id]);
            $msg = "Section #$id deleted.";
        }
        header('Location: about.php?msg=' . urlencode($msg));
        exit;
    }

    /* ---------- ABOUT: SAVE ---------- */
    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $eyebrow        = trim($_POST['eyebrow'] ?? '');
        $title_line1    = trim($_POST['title_line1'] ?? '');
        $title_line2    = trim($_POST['title_line2'] ?? '');
        $title_emphasis = trim($_POST['title_emphasis'] ?? '');
        $lead_copy      = trim($_POST['lead_copy'] ?? '');
        $body_copy      = trim($_POST['body_copy'] ?? '');
        $image_alt      = trim($_POST['image_alt'] ?? '');
        $is_active      = isset($_POST['is_active']) ? 1 : 0;
        $existingImage  = trim($_POST['existing_image'] ?? '');

        if ($eyebrow === '' || $title_line1 === '' || $title_emphasis === ''
            || $lead_copy === '' || $body_copy === '') {
            $err = 'Please fill all required fields.';
        } else {
            $image_url = handleImageUpload('image_file', $existingImage);
            $urlOverride = trim($_POST['image_url'] ?? '');
            if ($urlOverride !== '') $image_url = $urlOverride;

            $stats = [];
            $statValues = $_POST['stat_value'] ?? [];
            $statLabels = $_POST['stat_label'] ?? [];
            $statOrders = $_POST['stat_order'] ?? [];
            foreach ($statValues as $i => $v) {
                $v = trim($v); $l = trim($statLabels[$i] ?? '');
                if ($v === '' && $l === '') continue;
                $stats[] = ['value'=>$v,'label'=>$l,'sort_order'=>(int)($statOrders[$i] ?? ($i + 1))];
            }

            try {
                $db->beginTransaction();
                if ($id > 0) {
                    $stmt = $db->prepare(
                        "UPDATE about_section SET
                            eyebrow=?, title_line1=?, title_line2=?, title_emphasis=?,
                            lead_copy=?, body_copy=?, image_url=?, image_alt=?, is_active=?
                         WHERE id=?"
                    );
                    $stmt->execute([$eyebrow,$title_line1,$title_line2,$title_emphasis,
                                    $lead_copy,$body_copy,$image_url ?: null,
                                    $image_alt ?: 'WOOLF Manufacturing',$is_active,$id]);
                    $db->prepare("DELETE FROM about_stats WHERE section_id=?")->execute([$id]);
                    $msg = "Section #$id updated.";
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO about_section
                            (eyebrow, title_line1, title_line2, title_emphasis,
                             lead_copy, body_copy, image_url, image_alt, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([$eyebrow,$title_line1,$title_line2,$title_emphasis,
                                    $lead_copy,$body_copy,$image_url ?: null,
                                    $image_alt ?: 'WOOLF Manufacturing',$is_active]);
                    $id = (int)$db->lastInsertId();
                    $msg = "Section #$id created.";
                }
                if (!empty($stats)) {
                    $ins = $db->prepare("INSERT INTO about_stats (section_id, stat_value, stat_label, sort_order) VALUES (?,?,?,?)");
                    foreach ($stats as $s) $ins->execute([$id,$s['value'],$s['label'],$s['sort_order']]);
                }
                $db->commit();
                header('Location: about.php?msg=' . urlencode($msg) . '&edit=' . $id);
                exit;
            } catch (Throwable $e) {
                $db->rollBack();
                $err = 'Database error: ' . $e->getMessage();
            }
        }
    }

    /* ---------- ENQUIRY: STATUS ---------- */
    if ($action === 'enquiry_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($id > 0 && in_array($status, ['pending','contacted','archived'], true)) {
            $db->prepare("UPDATE enquiries SET status = ? WHERE id = ?")->execute([$status, $id]);
            header('Location: about.php?tab=enquiries&msg=' . urlencode("Enquiry #$id marked as $status."));
            exit;
        }
    }

    /* ---------- ENQUIRY: DELETE ---------- */
    if ($action === 'enquiry_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM enquiries WHERE id = ?")->execute([$id]);
            header('Location: about.php?tab=enquiries&msg=' . urlencode("Enquiry #$id deleted."));
            exit;
        }
    }

    /* ---------- CUSTOMERS: DELETE CLIENT ---------- */
    if ($action === 'delete_client') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $db->prepare("SELECT logo_url FROM customer_clients WHERE id = ?");
            $row->execute([$id]);
            $logo = $row->fetchColumn();
            if ($logo && str_starts_with($logo, 'uploads/customers/')) {
                @unlink(__DIR__ . '/../' . $logo);
            }
            $db->prepare("DELETE FROM customer_clients WHERE id = ?")->execute([$id]);
            header('Location: about.php?tab=customers&msg=' . urlencode("Client #$id deleted."));
            exit;
        }
    }

    /* ---------- CUSTOMERS: SAVE CLIENT ---------- */
    if ($action === 'save_client') {
        $id       = (int)($_POST['client_id'] ?? 0);
        $name     = trim($_POST['client_name'] ?? '');
        $type     = trim($_POST['client_type'] ?? 'CONTRACTOR');
        $note     = trim($_POST['client_note'] ?? '');
        $icon     = trim($_POST['icon_class'] ?? 'bi-building');
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $featured = isset($_POST['is_featured']) ? 1 : 0;
        $marquee  = isset($_POST['show_marquee']) ? 1 : 0;
        $mq_text  = trim($_POST['marquee_text'] ?? '');
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $existing = trim($_POST['existing_logo'] ?? '');

        if ($name === '') {
            $err = 'Client name is required.';
        } else {
            $logo = handleCustomerUpload('logo_file', $existing, 'client');
            $urlOverride = trim($_POST['logo_url'] ?? '');
            if ($urlOverride !== '') $logo = $urlOverride;

            try {
                if ($id > 0) {
                    $stmt = $db->prepare(
                        "UPDATE customer_clients SET
                            client_name=?, client_type=?, client_note=?, logo_url=?,
                            icon_class=?, is_featured=?, show_marquee=?, marquee_text=?,
                            is_active=?, sort_order=?
                         WHERE id=?"
                    );
                    $stmt->execute([$name,$type,$note ?: null,$logo ?: null,
                                    $icon,$featured,$marquee,$mq_text ?: null,
                                    $active,$sort,$id]);
                    $msg = "Client #$id updated.";
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO customer_clients
                            (client_name, client_type, client_note, logo_url,
                             icon_class, is_featured, show_marquee, marquee_text,
                             is_active, sort_order)
                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([$name,$type,$note ?: null,$logo ?: null,
                                    $icon,$featured,$marquee,$mq_text ?: null,
                                    $active,$sort]);
                    $id = (int)$db->lastInsertId();
                    $msg = "Client #$id created.";
                }
                header('Location: about.php?tab=customers&msg=' . urlencode($msg) . '&edit_client=' . $id);
                exit;
            } catch (Throwable $e) {
                $err = 'DB error: ' . $e->getMessage();
            }
        }
    }

    /* ---------- CUSTOMERS: SAVE SECTION ---------- */
    if ($action === 'save_section') {
        $sid = (int)($_POST['section_id'] ?? 0);
        $cur = $db->prepare("SELECT * FROM customer_section WHERE id = ?");
        $cur->execute([$sid]);
        $cs = $cur->fetch() ?: [];

        $p1 = handleCustomerUpload('photo1_file', $cs['photo1_url'] ?? '', 'photo1');
        $p2 = handleCustomerUpload('photo2_file', $cs['photo2_url'] ?? '', 'photo2');
        $p3 = handleCustomerUpload('photo3_file', $cs['photo3_url'] ?? '', 'photo3');

        if (!empty($_POST['photo1_url'])) $p1 = trim($_POST['photo1_url']);
        if (!empty($_POST['photo2_url'])) $p2 = trim($_POST['photo2_url']);
        if (!empty($_POST['photo3_url'])) $p3 = trim($_POST['photo3_url']);

        $stmt = $db->prepare(
            "UPDATE customer_section SET
                tagline=?, title_line1=?, title_emphasis=?, lead_copy=?,
                cta_text=?, cta_link=?,
                stat1_value=?, stat1_label=?, stat2_value=?, stat2_label=?,
                stat3_value=?, stat3_label=?, stat4_value=?, stat4_label=?,
                photo1_url=?, photo1_tag=?, photo1_caption=?,
                photo2_url=?, photo2_tag=?, photo2_caption=?,
                photo3_url=?, photo3_tag=?, photo3_caption=?,
                is_active=?
             WHERE id=?"
        );
        $stmt->execute([
            trim($_POST['tagline'] ?? ''),
            trim($_POST['title_line1'] ?? ''),
            trim($_POST['title_emphasis'] ?? ''),
            trim($_POST['lead_copy'] ?? ''),
            trim($_POST['cta_text'] ?? ''),
            trim($_POST['cta_link'] ?? ''),
            trim($_POST['stat1_value'] ?? ''), trim($_POST['stat1_label'] ?? ''),
            trim($_POST['stat2_value'] ?? ''), trim($_POST['stat2_label'] ?? ''),
            trim($_POST['stat3_value'] ?? ''), trim($_POST['stat3_label'] ?? ''),
            trim($_POST['stat4_value'] ?? ''), trim($_POST['stat4_label'] ?? ''),
            $p1 ?: null, trim($_POST['photo1_tag'] ?? ''), trim($_POST['photo1_caption'] ?? ''),
            $p2 ?: null, trim($_POST['photo2_tag'] ?? ''), trim($_POST['photo2_caption'] ?? ''),
            $p3 ?: null, trim($_POST['photo3_tag'] ?? ''), trim($_POST['photo3_caption'] ?? ''),
            isset($_POST['section_active']) ? 1 : 0,
            $sid,
        ]);
        header('Location: about.php?tab=customers&msg=' . urlencode('Customers section updated.'));
        exit;
    }

    /* ---------- GALLERY: SAVE SECTION HEADER ---------- */
    if ($action === 'save_gallery_section') {
        $sid = (int)($_POST['section_id'] ?? 0);
        $stmt = $db->prepare(
            "UPDATE gallery_section SET
                eyebrow=?, title_line1=?, title_emphasis=?, intro_copy=?, is_active=?
             WHERE id=?"
        );
        $stmt->execute([
            trim($_POST['eyebrow'] ?? ''),
            trim($_POST['title_line1'] ?? ''),
            trim($_POST['title_emphasis'] ?? ''),
            trim($_POST['intro_copy'] ?? ''),
            isset($_POST['section_active']) ? 1 : 0,
            $sid,
        ]);
        header('Location: about.php?tab=gallery&msg=' . urlencode('Gallery header updated.'));
        exit;
    }

    /* ---------- GALLERY: SAVE IMAGE ---------- */
    if ($action === 'save_gallery_image') {
        $sid      = (int)($_POST['section_id'] ?? 1);
        $imgId    = (int)($_POST['image_id'] ?? 0);
        $alt      = trim($_POST['image_alt'] ?? '');
        $caption  = trim($_POST['caption'] ?? '');
        $layout   = in_array($_POST['layout'] ?? '', ['normal','tall','wide'], true)
                        ? $_POST['layout'] : 'normal';
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $existing = trim($_POST['existing_image'] ?? '');

        if ($imgId > 0) {
            $row = $db->prepare("SELECT image_url FROM gallery_images WHERE id = ?");
            $row->execute([$imgId]);
            $existing = (string)$row->fetchColumn();
        }

        $image_url = handleGalleryUpload('image_file', $existing);
        $urlOverride = trim($_POST['image_url'] ?? '');
        if ($urlOverride !== '') $image_url = $urlOverride;

        if ($image_url === '') {
            $err = 'Please upload an image or paste an image URL.';
        } else {
            try {
                if ($imgId > 0) {
                    $stmt = $db->prepare(
                        "UPDATE gallery_images SET
                            image_url=?, image_alt=?, caption=?, layout=?, sort_order=?, is_active=?
                         WHERE id=? AND section_id=?"
                    );
                    $stmt->execute([$image_url, $alt, $caption, $layout, $sort, $active, $imgId, $sid]);
                    $msg = "Image #$imgId updated.";
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO gallery_images
                            (section_id, image_url, image_alt, caption, layout, sort_order, is_active)
                         VALUES (?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([$sid, $image_url, $alt, $caption, $layout, $sort, $active]);
                    $imgId = (int)$db->lastInsertId();
                    $msg = "Image #$imgId added.";
                }
                header('Location: about.php?tab=gallery&msg=' . urlencode($msg) . '&edit_image=' . $imgId);
                exit;
            } catch (Throwable $e) {
                $err = 'DB error: ' . $e->getMessage();
            }
        }
    }

    /* ---------- GALLERY: DELETE IMAGE ---------- */
    if ($action === 'delete_gallery_image') {
        $imgId = (int)($_POST['id'] ?? 0);
        if ($imgId > 0) {
            $row = $db->prepare("SELECT image_url FROM gallery_images WHERE id = ?");
            $row->execute([$imgId]);
            $img = (string)$row->fetchColumn();
            if ($img && str_starts_with($img, 'uploads/gallery/')) {
                @unlink(__DIR__ . '/../' . $img);
            }
            $db->prepare("DELETE FROM gallery_images WHERE id = ?")->execute([$imgId]);
            header('Location: about.php?tab=gallery&msg=' . urlencode("Image #$imgId deleted."));
            exit;
        }
    }

    /* ---------- REELS: SAVE SECTION HEADER ---------- */
    if ($action === 'save_reels_section') {
        $sid = (int)($_POST['section_id'] ?? 0);
        $stmt = $db->prepare(
            "UPDATE reels_section SET
                eyebrow=?, title_line1=?, title_emphasis=?, intro_copy=?,
                cta_text=?, cta_link=?, is_active=?
             WHERE id=?"
        );
        $stmt->execute([
            trim($_POST['eyebrow'] ?? ''),
            trim($_POST['title_line1'] ?? ''),
            trim($_POST['title_emphasis'] ?? ''),
            trim($_POST['intro_copy'] ?? ''),
            trim($_POST['cta_text'] ?? ''),
            trim($_POST['cta_link'] ?? ''),
            isset($_POST['section_active']) ? 1 : 0,
            $sid,
        ]);
        header('Location: about.php?tab=reels&msg=' . urlencode('Reels header updated.'));
        exit;
    }

    /* ---------- REELS: SAVE ITEM ---------- */
    if ($action === 'save_reel') {
        $sid       = (int)($_POST['section_id'] ?? 1);
        $reelId    = (int)($_POST['reel_id'] ?? 0);

        // Load existing values if editing
        $existingVideo  = '';
        $existingPoster = '';
        if ($reelId > 0) {
            $row = $db->prepare("SELECT video_url, poster_url FROM reels_items WHERE id = ?");
            $row->execute([$reelId]);
            $r = $row->fetch() ?: [];
            $existingVideo  = $r['video_url'] ?? '';
            $existingPoster = $r['poster_url'] ?? '';
        }

        // Uploads
        $video_url  = handleReelUpload('video_file',  $existingVideo,  'video');
        $poster_url = handleReelUpload('poster_file', $existingPoster, 'poster');

        // URL overrides
        if (!empty($_POST['video_url']))  $video_url  = trim($_POST['video_url']);
        if (!empty($_POST['poster_url'])) $poster_url = trim($_POST['poster_url']);

        $link_url   = trim($_POST['link_url']   ?? '');
        $tag        = trim($_POST['tag']        ?? '');
        $meta_label = trim($_POST['meta_label'] ?? '');
        $title      = trim($_POST['title']      ?? '');
        $subtitle   = trim($_POST['subtitle']   ?? '');
        $sort       = (int)($_POST['sort_order'] ?? 0);
        $active     = isset($_POST['is_active']) ? 1 : 0;

        if ($video_url === '' && $poster_url === '') {
            $err = 'Please upload a video, poster image, or paste a video URL.';
        } else {
            try {
                if ($reelId > 0) {
                    $stmt = $db->prepare(
                        "UPDATE reels_items SET
                            video_url=?, poster_url=?, link_url=?,
                            tag=?, meta_label=?, title=?, subtitle=?,
                            sort_order=?, is_active=?
                         WHERE id=? AND section_id=?"
                    );
                    $stmt->execute([
                        $video_url ?: '', $poster_url ?: '', $link_url,
                        $tag, $meta_label, $title, $subtitle,
                        $sort, $active, $reelId, $sid
                    ]);
                    $msg = "Reel #$reelId updated.";
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO reels_items
                            (section_id, video_url, poster_url, link_url,
                             tag, meta_label, title, subtitle, sort_order, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->execute([
                        $sid, $video_url ?: '', $poster_url ?: '', $link_url,
                        $tag, $meta_label, $title, $subtitle, $sort, $active
                    ]);
                    $reelId = (int)$db->lastInsertId();
                    $msg = "Reel #$reelId created.";
                }
                header('Location: about.php?tab=reels&msg=' . urlencode($msg) . '&edit_reel=' . $reelId);
                exit;
            } catch (Throwable $e) {
                $err = 'DB error: ' . $e->getMessage();
            }
        }
    }

    /* ---------- REELS: DELETE ITEM ---------- */
    if ($action === 'delete_reel') {
        $reelId = (int)($_POST['id'] ?? 0);
        if ($reelId > 0) {
            $row = $db->prepare("SELECT video_url, poster_url FROM reels_items WHERE id = ?");
            $row->execute([$reelId]);
            $r = $row->fetch() ?: [];
            foreach (['video_url','poster_url'] as $col) {
                $p = $r[$col] ?? '';
                if ($p && str_starts_with($p, 'uploads/reels/')) {
                    @unlink(__DIR__ . '/../' . $p);
                }
            }
            $db->prepare("DELETE FROM reels_items WHERE id = ?")->execute([$reelId]);
            header('Location: about.php?tab=reels&msg=' . urlencode("Reel #$reelId deleted."));
            exit;
        }
    }
}

/* ============================================================
   LOAD DATA
   ============================================================ */
if (!empty($_GET['msg'])) $msg = $_GET['msg'];

$tab = $_GET['tab'] ?? 'about'; // about | gallery | reels | enquiries | customers

/* ---- About ---- */
$editing = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM about_section WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $s2 = $db->prepare("SELECT * FROM about_stats WHERE section_id = ? ORDER BY sort_order ASC, id ASC");
        $s2->execute([$editing['id']]);
        $editing['stats'] = $s2->fetchAll();
    }
}
$sections = $db->query("SELECT * FROM about_section ORDER BY id DESC")->fetchAll();

$defaultStats = [
    ['stat_value'=>'2019','stat_label'=>'R&D FOUNDATION','sort_order'=>1],
    ['stat_value'=>'2022','stat_label'=>'PRODUCTION STARTED','sort_order'=>2],
    ['stat_value'=>'2','stat_label'=>'FACTORY LOCATIONS','sort_order'=>3],
    ['stat_value'=>'100%','stat_label'=>'RECYCLED FLY ASH','sort_order'=>4],
];
$formStats = $editing['stats'] ?? $defaultStats;

/* ---- Enquiries ---- */
$filterStatus = $_GET['status'] ?? 'all';
try {
    $counts = ['pending'=>0,'contacted'=>0,'archived'=>0,'all'=>0];
    $cnt = $db->query("SELECT status, COUNT(*) c FROM enquiries GROUP BY status")->fetchAll();
    foreach ($cnt as $row) {
        $counts[$row['status']] = (int)$row['c'];
        $counts['all'] += (int)$row['c'];
    }
    if (in_array($filterStatus, ['pending','contacted','archived'], true)) {
        $stmt = $db->prepare("SELECT * FROM enquiries WHERE status = ? ORDER BY id DESC LIMIT 500");
        $stmt->execute([$filterStatus]);
        $enquiries = $stmt->fetchAll();
    } else {
        $enquiries = $db->query("SELECT * FROM enquiries ORDER BY id DESC LIMIT 500")->fetchAll();
    }
} catch (Throwable $e) {
    $counts = ['pending'=>0,'contacted'=>0,'archived'=>0,'all'=>0];
    $enquiries = [];
}

/* ---- Customers ---- */
$custSection = $db->query("SELECT * FROM customer_section ORDER BY id ASC LIMIT 1")->fetch();

$editingClient = null;
if (!empty($_GET['edit_client'])) {
    $stmt = $db->prepare("SELECT * FROM customer_clients WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_client']]);
    $editingClient = $stmt->fetch() ?: null;
}
$clients = $db->query("SELECT * FROM customer_clients ORDER BY sort_order ASC, id ASC")->fetchAll();

$clientTypes  = ['CONTRACTOR','ARCHITECT','BUILDER','GOVERNMENT','SUPPLIER','OTHER'];
$iconChoices  = [
    'bi-building'   => 'Building (Contractor)',
    'bi-buildings'  => 'Buildings (Builder)',
    'bi-rulers'     => 'Rulers (Architect)',
    'bi-bank'       => 'Bank (Government)',
    'bi-truck'      => 'Truck (Logistics)',
    'bi-briefcase'  => 'Briefcase',
    'bi-diagram-3'  => 'Diagram',
    'bi-house-door' => 'House',
    'bi-gear'       => 'Gear',
    'bi-star'       => 'Star',
];

/* ---- Gallery ---- */
$gallerySection = $db->query("SELECT * FROM gallery_section ORDER BY id ASC LIMIT 1")->fetch() ?: null;

$editingImage = null;
if (!empty($_GET['edit_image'])) {
    $stmt = $db->prepare("SELECT * FROM gallery_images WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_image']]);
    $editingImage = $stmt->fetch() ?: null;
}
$galleryImages = $db->query(
    "SELECT * FROM gallery_images
     WHERE section_id = " . (int)($gallerySection['id'] ?? 1) . "
     ORDER BY sort_order ASC, id ASC"
)->fetchAll();

/* ---- Reels ---- */
$reelsSection = $db->query("SELECT * FROM reels_section ORDER BY id ASC LIMIT 1")->fetch() ?: null;

$editingReel = null;
if (!empty($_GET['edit_reel'])) {
    $stmt = $db->prepare("SELECT * FROM reels_items WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_reel']]);
    $editingReel = $stmt->fetch() ?: null;
}
$reelsItems = $db->query(
    "SELECT * FROM reels_items
     WHERE section_id = " . (int)($reelsSection['id'] ?? 1) . "
     ORDER BY sort_order ASC, id ASC"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $tab === 'enquiries' ? 'Enquiries' : ($tab === 'customers' ? 'Customers' : ($tab === 'gallery' ? 'Gallery' : ($tab === 'reels' ? 'Reels' : 'About Section'))) ?> — WOOLF Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--ink:#111715;--ink2:#1a211e;--panel:#202824;--input:#151d19;
      --gold:#d4a943;--gold-h:#f0c766;--line:rgba(255,255,255,.1);--muted:#9aa39a}
*{box-sizing:border-box}
body{margin:0;background:#0d1310;color:#e7e9e5;
     font-family:'DM Sans',system-ui,-apple-system,sans-serif;font-size:14px}
a{color:var(--gold);text-decoration:none}
a:hover{color:var(--gold-h)}
.admin-wrap{display:flex;min-height:100vh}
.sidebar{width:240px;background:var(--ink);border-right:1px solid var(--line);
    padding:22px 0;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.sidebar .brand{display:flex;align-items:center;gap:10px;padding:0 22px 22px;
    border-bottom:1px solid var(--line);margin-bottom:16px}
.sidebar .brand-mark{font:800 26px/1 Manrope;color:var(--gold)}
.sidebar .brand-txt{font:800 13px/1.2 Manrope;letter-spacing:.12em;color:#fff}
.sidebar .brand-txt small{display:block;font-size:8px;color:var(--gold);letter-spacing:.2em;margin-top:4px}
.sidebar nav a{display:flex;align-items:center;gap:12px;
    padding:11px 22px;color:#b7beb6;font-size:13px;font-weight:500;
    border-left:3px solid transparent;transition:.2s}
.sidebar nav a:hover{background:rgba(212,169,67,.06);color:#fff}
.sidebar nav a.active{background:rgba(212,169,67,.12);color:var(--gold);
    border-left-color:var(--gold);font-weight:600}
.sidebar nav a i{font-size:16px;width:20px;text-align:center}
.sidebar nav a .count-badge{margin-left:auto;background:var(--gold);color:#111;
    font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px}
.sidebar .nav-label{padding:16px 22px 6px;font-size:10px;letter-spacing:.15em;
    text-transform:uppercase;color:#5e6a60}
.sidebar .sidebar-footer{padding:16px 22px 0;margin-top:20px;
    border-top:1px solid var(--line);font-size:11px;color:#5e6a60}
.main{flex:1;min-width:0;padding:24px 28px 40px}
.topbar{display:flex;justify-content:space-between;align-items:center;
    margin-bottom:22px;flex-wrap:wrap;gap:12px}
.topbar h1{font:700 20px/1.2 Manrope;letter-spacing:-.01em;margin:0;color:#fff}
.topbar .crumb{font-size:12px;color:var(--muted);margin-top:4px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:6px;
    padding:22px;margin-bottom:22px}
.card h2{font:700 15px/1.3 Manrope;letter-spacing:.02em;margin:0 0 16px;color:#fff}
.form-label{font-size:11px;letter-spacing:.08em;text-transform:uppercase;
    color:#b7beb6;margin-bottom:5px;font-weight:600}
.form-control,.form-select{background:var(--input);border:1px solid rgba(255,255,255,.12);
    color:#fff;font-size:13px;padding:9px 12px;border-radius:4px}
.form-control:focus,.form-select:focus{background:var(--input);color:#fff;
    border-color:var(--gold);box-shadow:none}
.form-control::placeholder{color:#5e6a60}
.form-control[type=file]::file-selector-button{background:var(--ink2);color:#d6d9d4;
    border:1px solid var(--line);padding:6px 10px;border-radius:3px;margin-right:10px;
    font-size:12px;cursor:pointer}
textarea.form-control{resize:vertical;min-height:60px}
.btn{font-size:12px;padding:8px 16px;border-radius:4px;font-weight:600;letter-spacing:.02em}
.btn-gold{background:var(--gold);color:#111;border:1px solid var(--gold)}
.btn-gold:hover{background:var(--gold-h);color:#111;border-color:var(--gold-h)}
.btn-outline{background:transparent;color:#d6d9d4;border:1px solid var(--line)}
.btn-outline:hover{background:rgba(255,255,255,.05);color:#fff;border-color:rgba(255,255,255,.3)}
.btn-del{background:transparent;color:#e77;border:1px solid rgba(220,80,80,.3)}
.btn-del:hover{background:rgba(220,80,80,.1);color:#f88;border-color:rgba(220,80,80,.5)}
.btn-success-soft{background:rgba(60,160,80,.12);color:#8fd6a0;border:1px solid rgba(60,160,80,.35)}
.btn-success-soft:hover{background:rgba(60,160,80,.25);color:#c0f0d0}
.btn-muted-soft{background:rgba(255,255,255,.06);color:#b7beb6;border:1px solid rgba(255,255,255,.12)}
.btn-muted-soft:hover{background:rgba(255,255,255,.12);color:#fff}
.btn-sm{padding:5px 10px;font-size:11px}
.stat-row{background:var(--input);border:1px solid var(--line);border-radius:4px;
    padding:12px;margin-bottom:10px}
.img-preview{width:100%;max-width:280px;height:160px;object-fit:cover;
    border:1px solid var(--line);border-radius:4px;background:var(--input);
    display:block;margin-bottom:10px}
.table{color:#d6d9d4;font-size:13px;margin:0}
.table thead th{background:var(--ink2);border-bottom:1px solid var(--line);
    font-size:11px;letter-spacing:.08em;text-transform:uppercase;
    color:#8f9990;font-weight:600;padding:11px 12px}
.table tbody td{border-bottom:1px solid var(--line);padding:12px;vertical-align:middle}
.table tbody tr:hover{background:rgba(255,255,255,.02)}
.table img.thumb{width:56px;height:38px;object-fit:cover;border-radius:3px;
    border:1px solid var(--line)}
.logo-thumb{width:56px;height:42px;object-fit:contain;border-radius:4px;
    border:1px solid rgba(255,255,255,.14);background:#0f1512;padding:4px}
.logo-placeholder{width:56px;height:42px;display:grid;place-items:center;
    border:1px dashed rgba(255,255,255,.14);border-radius:4px;color:#8a9389;font-size:16px}
.logo-preview{width:100%;max-width:180px;height:100px;object-fit:contain;
    border:1px solid rgba(255,255,255,.14);border-radius:4px;background:#0f1512;
    padding:8px;display:block;margin-bottom:10px}
.photo-preview{width:100%;max-width:260px;height:140px;object-fit:cover;
    border:1px solid rgba(255,255,255,.14);border-radius:4px;background:#0f1512;
    display:block;margin-bottom:10px}
.photo-cell{background:var(--input);border:1px solid var(--line);
    border-radius:4px;padding:12px;margin-bottom:10px}
.g-thumb{width:80px;height:56px;object-fit:cover;border-radius:4px;
    border:1px solid var(--line);background:#0f1512}
.g-preview{width:100%;max-width:260px;height:150px;object-fit:cover;
    border:1px solid var(--line);border-radius:4px;background:var(--input);
    display:block;margin-bottom:10px}

/* Reels admin styles */
.reel-thumb{width:56px;height:90px;object-fit:cover;border-radius:4px;
    border:1px solid var(--line);background:#0f1512}
.reel-video-preview{width:100%;max-width:180px;height:280px;object-fit:cover;
    border:1px solid var(--line);border-radius:4px;background:#0f1512;
    display:block;margin-bottom:10px}

.alert{font-size:13px;padding:10px 14px;border-radius:4px;border:1px solid transparent}
.alert-success{background:rgba(60,160,80,.12);border-color:rgba(60,160,80,.3);color:#8fd6a0}
.alert-danger{background:rgba(220,80,80,.12);border-color:rgba(220,80,80,.3);color:#f0a0a0}
.enq-filters{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.enq-filter-btn{display:inline-flex;align-items:center;gap:8px;
    padding:8px 14px;font-size:12px;font-weight:600;
    background:var(--ink2);color:#b7beb6;border:1px solid var(--line);
    border-radius:20px;transition:.2s;text-decoration:none}
.enq-filter-btn:hover{color:#fff;border-color:rgba(255,255,255,.25)}
.enq-filter-btn.active{background:rgba(212,169,67,.15);color:var(--gold);
    border-color:rgba(212,169,67,.4)}
.enq-filter-btn .badge-count{background:rgba(255,255,255,.12);color:inherit;
    font-size:10px;padding:2px 7px;border-radius:10px}
.enq-filter-btn.active .badge-count{background:var(--gold);color:#111}
.status-badge{display:inline-flex;align-items:center;gap:5px;
    font-size:10.5px;font-weight:600;letter-spacing:.04em;
    padding:3px 9px;border-radius:20px;text-transform:uppercase}
.status-badge.pending{background:rgba(230,160,40,.15);color:#f0c766;
    border:1px solid rgba(230,160,40,.35)}
.status-badge.contacted{background:rgba(60,160,80,.15);color:#8fd6a0;
    border:1px solid rgba(60,160,80,.35)}
.status-badge.archived{background:rgba(255,255,255,.06);color:#8f9990;
    border:1px solid rgba(255,255,255,.12)}
.enq-msg{max-width:340px;font-size:12.5px;color:#c0c8c0;line-height:1.6;
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.enq-name{font-weight:600;color:#fff}
.enq-sub{font-size:11.5px;color:#8f9990;margin-top:2px}
.enq-actions{display:flex;flex-wrap:wrap;gap:5px}
.empty-state{text-align:center;padding:50px 20px;color:#8f9990}
.empty-state i{font-size:36px;opacity:.4;display:block;margin-bottom:10px}
@media(max-width:820px){
  .admin-wrap{flex-direction:column}
  .sidebar{width:100%;height:auto;position:static;padding:14px 0}
  .sidebar .brand{padding:0 18px 14px;margin-bottom:8px}
  .sidebar nav{display:flex;overflow-x:auto;gap:2px;padding:0 10px}
  .sidebar nav a{padding:8px 12px;border-left:0;border-bottom:2px solid transparent;
      white-space:nowrap;font-size:12px}
  .sidebar nav a.active{border-left:0;border-bottom-color:var(--gold)}
  .sidebar .nav-label,.sidebar .sidebar-footer{display:none}
  .sidebar nav a .count-badge{display:none}
  .main{padding:18px}
}
</style>
</head>
<body>

<div class="admin-wrap">

  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">W</span>
      <div class="brand-txt">WOOLF<small>ADMIN PANEL</small></div>
    </div>

    <nav>
      <div class="nav-label">Content</div>
      <a href="about.php?tab=about" class="<?= $tab === 'about' ? 'active' : '' ?>">
        <i class="bi bi-info-circle"></i> About Section
      </a>
      <a href="about.php?tab=gallery" class="<?= $tab === 'gallery' ? 'active' : '' ?>">
        <i class="bi bi-images"></i> Gallery
      </a>
      <a href="about.php?tab=reels" class="<?= $tab === 'reels' ? 'active' : '' ?>">
        <i class="bi bi-play-btn"></i> Reels
      </a>
      <a href="about.php?tab=enquiries" class="<?= $tab === 'enquiries' ? 'active' : '' ?>">
        <i class="bi bi-inbox"></i> Enquiries
        <?php if (!empty($counts['pending'])): ?>
          <span class="count-badge"><?= (int)$counts['pending'] ?></span>
        <?php endif; ?>
      </a>
      <a href="about.php?tab=customers" class="<?= $tab === 'customers' ? 'active' : '' ?>">
        <i class="bi bi-people"></i> Customers
      </a>
      <a href="#"><i class="bi bi-box-seam"></i> Products</a>
      <div class="nav-label">Settings</div>
      <a href="#"><i class="bi bi-gear"></i> General</a>
      <a href="../index.php" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View Site</a>
    </nav>
    <div class="sidebar-footer">v1.0 · WOOLF Admin</div>
  </aside>

  <main class="main">

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <?php if ($tab === 'about'): ?>
    <!-- ================= ABOUT TAB ================= -->
    <div class="topbar">
      <div><h1>About Section</h1><div class="crumb">Content / About / Manage</div></div>
      <?php if ($editing): ?>
        <a href="about.php?tab=about" class="btn btn-outline"><i class="bi bi-plus-lg me-1"></i> New Section</a>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2><i class="bi bi-pencil-square me-2"></i><?= $editing ? 'Edit Section #' . (int)$editing['id'] : 'Create New Section' ?></h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editing['image_url'] ?? '') ?>">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Eyebrow *</label>
            <input name="eyebrow" class="form-control" required value="<?= htmlspecialchars($editing['eyebrow'] ?? '01 / THE WOOLF STORY') ?>"></div>
          <div class="col-md-6"><label class="form-label">Image Alt Text</label>
            <input name="image_alt" class="form-control" value="<?= htmlspecialchars($editing['image_alt'] ?? 'WOOLF Manufacturing') ?>"></div>
          <div class="col-md-4"><label class="form-label">Title Line 1 *</label>
            <input name="title_line1" class="form-control" required value="<?= htmlspecialchars($editing['title_line1'] ?? 'A better way') ?>"></div>
          <div class="col-md-4"><label class="form-label">Title Line 2</label>
            <input name="title_line2" class="form-control" value="<?= htmlspecialchars($editing['title_line2'] ?? 'to') ?>"></div>
          <div class="col-md-4"><label class="form-label">Emphasis Word *</label>
            <input name="title_emphasis" class="form-control" required value="<?= htmlspecialchars($editing['title_emphasis'] ?? 'build.') ?>"></div>
          <div class="col-12"><label class="form-label">Lead Copy *</label>
            <textarea name="lead_copy" rows="2" class="form-control" required><?= htmlspecialchars($editing['lead_copy'] ?? 'WOOLF turns fly ash into a practical building material.') ?></textarea></div>
          <div class="col-12"><label class="form-label">Body Copy *</label>
            <textarea name="body_copy" rows="3" class="form-control" required><?= htmlspecialchars($editing['body_copy'] ?? 'R&D began in 2019, production October 2022. Proprietor: Pritesh R Patel. Factory: Gaypagal & Jiav Gam.') ?></textarea></div>
          <div class="col-md-7"><label class="form-label">About Image (upload)</label>
            <?php if (!empty($editing['image_url'])): ?><img src="../<?= htmlspecialchars($editing['image_url']) ?>" class="img-preview" alt=""><?php endif; ?>
            <input type="file" name="image_file" class="form-control" accept="image/*"></div>
          <div class="col-md-5"><label class="form-label">Or paste Image URL</label>
            <input name="image_url" class="form-control" value="<?= htmlspecialchars($editing['image_url'] ?? '') ?>">
            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= (!$editing || !empty($editing['is_active'])) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="is_active">Active</label>
            </div></div>
        </div>
        <hr style="border-color:var(--line);margin:22px 0 16px">
        <h2 style="font-size:13px"><i class="bi bi-bar-chart me-2"></i>Stat Cards</h2>
        <div id="statsWrap">
          <?php foreach ($formStats as $s): ?>
            <div class="stat-row row g-2 align-items-center">
              <div class="col-md-3"><input name="stat_value[]" class="form-control" placeholder="Value" required value="<?= htmlspecialchars($s['stat_value']) ?>"></div>
              <div class="col-md-5"><input name="stat_label[]" class="form-control" placeholder="Label" required value="<?= htmlspecialchars($s['stat_label']) ?>"></div>
              <div class="col-md-2"><input name="stat_order[]" type="number" class="form-control" value="<?= htmlspecialchars((string)$s['sort_order']) ?>"></div>
              <div class="col-md-2"><button type="button" class="btn btn-del w-100" onclick="this.closest('.stat-row').remove()">Remove</button></div>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline btn-sm mt-2" onclick="addStat()"><i class="bi bi-plus-lg me-1"></i> Add Stat</button>
        <hr style="border-color:var(--line);margin:22px 0 16px">
        <div class="d-flex gap-2">
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> <?= $editing ? 'Update Section' : 'Create Section' ?></button>
          <?php if ($editing): ?><a href="about.php?tab=about" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2><i class="bi bi-list-ul me-2"></i>All Sections</h2>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th style="width:60px">ID</th><th style="width:80px">Image</th><th>Title</th><th style="width:90px">Status</th><th style="width:160px">Updated</th><th style="width:150px">Actions</th></tr></thead>
          <tbody>
            <?php if (empty($sections)): ?>
              <tr><td colspan="6" class="text-center text-secondary py-4">No sections yet.</td></tr>
            <?php else: foreach ($sections as $s): ?>
              <tr>
                <td>#<?= (int)$s['id'] ?></td>
                <td><?php if (!empty($s['image_url'])): ?><img src="../<?= htmlspecialchars($s['image_url']) ?>" class="thumb" alt=""><?php else: ?><span class="text-secondary small">—</span><?php endif; ?></td>
                <td><div class="fw-semibold"><?= htmlspecialchars($s['title_line1'] . ' ' . $s['title_line2']) ?></div><div class="small text-secondary"><?= htmlspecialchars($s['title_emphasis']) ?></div></td>
                <td><?php if ($s['is_active']): ?><span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25">Active</span><?php else: ?><span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25">Inactive</span><?php endif; ?></td>
                <td class="small text-secondary"><?= htmlspecialchars($s['updated_at']) ?></td>
                <td>
                  <a href="?tab=about&edit=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete section #<?= (int)$s['id'] ?>?');">
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-sm btn-del">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($tab === 'gallery'): ?>
    <!-- ================= GALLERY TAB ================= -->
    <div class="topbar">
      <div><h1>Gallery</h1><div class="crumb">Content / Gallery / Manage</div></div>
      <?php if ($editingImage): ?><a href="about.php?tab=gallery" class="btn btn-outline"><i class="bi bi-plus-lg me-1"></i> Add New Image</a><?php endif; ?>
    </div>

    <?php if ($gallerySection): ?>
    <div class="card">
      <h2><i class="bi bi-layout-text-window me-2"></i>Gallery Section Header</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_gallery_section">
        <input type="hidden" name="section_id" value="<?= (int)$gallerySection['id'] ?>">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Eyebrow</label>
            <input name="eyebrow" class="form-control" required value="<?= htmlspecialchars($gallerySection['eyebrow']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Title Line 1</label>
            <input name="title_line1" class="form-control" required value="<?= htmlspecialchars($gallerySection['title_line1']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Emphasis Word</label>
            <input name="title_emphasis" class="form-control" required value="<?= htmlspecialchars($gallerySection['title_emphasis']) ?>"></div>
          <div class="col-12"><label class="form-label">Intro Copy</label>
            <textarea name="intro_copy" rows="2" class="form-control" required><?= htmlspecialchars($gallerySection['intro_copy']) ?></textarea></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="section_active" id="gallery_active" <?= !empty($gallerySection['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="gallery_active">Section visible on site</label></div>
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> Save Header</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2><i class="bi bi-image me-2"></i><?= $editingImage ? 'Edit Image #' . (int)$editingImage['id'] : 'Add New Image' ?></h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_gallery_image">
        <input type="hidden" name="section_id" value="<?= (int)($gallerySection['id'] ?? 1) ?>">
        <input type="hidden" name="image_id" value="<?= $editingImage ? (int)$editingImage['id'] : '' ?>">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Upload Image *</label>
            <?php if (!empty($editingImage['image_url'])): ?><img src="../<?= htmlspecialchars($editingImage['image_url']) ?>" class="g-preview" alt=""><?php endif; ?>
            <input type="file" name="image_file" class="form-control" accept="image/*">
            <div class="form-text text-secondary mt-1">Uploads → <code>uploads/gallery/</code></div></div>
          <div class="col-md-6"><label class="form-label">Or paste Image URL</label>
            <input name="image_url" class="form-control" value="<?= htmlspecialchars($editingImage['image_url'] ?? '') ?>">
            <label class="form-label mt-2">Alt Text</label>
            <input name="image_alt" class="form-control" value="<?= htmlspecialchars($editingImage['image_alt'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Caption</label>
            <input name="caption" class="form-control" value="<?= htmlspecialchars($editingImage['caption'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="form-label">Layout</label>
            <select name="layout" class="form-select">
              <?php foreach (['normal'=>'Normal','tall'=>'Tall (2 rows)','wide'=>'Wide (2 cols)'] as $v => $lbl): ?>
                <option value="<?= $v ?>" <?= ($editingImage['layout'] ?? 'normal') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-3"><label class="form-label">Sort Order</label>
            <input name="sort_order" type="number" class="form-control" value="<?= htmlspecialchars((string)($editingImage['sort_order'] ?? count($galleryImages) + 1)) ?>"></div>
          <div class="col-12"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" id="img_active" <?= (!$editingImage || !empty($editingImage['is_active'])) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="img_active">Show on site</label></div></div>
        </div>
        <div class="d-flex gap-2 mt-4">
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> <?= $editingImage ? 'Update Image' : 'Add Image' ?></button>
          <?php if ($editingImage): ?><a href="about.php?tab=gallery" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2><i class="bi bi-list-ul me-2"></i>Gallery Images (<?= count($galleryImages) ?>)</h2>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th style="width:60px">ID</th><th style="width:100px">Image</th><th>Caption / Alt</th><th style="width:100px">Layout</th><th style="width:80px">Order</th><th style="width:90px">Status</th><th style="width:150px">Actions</th></tr></thead>
          <tbody>
            <?php if (empty($galleryImages)): ?>
              <tr><td colspan="7" class="text-center text-secondary py-4">No images yet. Add your first one above.</td></tr>
            <?php else: foreach ($galleryImages as $img): ?>
              <tr>
                <td>#<?= (int)$img['id'] ?></td>
                <td><img src="../<?= htmlspecialchars($img['image_url']) ?>" class="g-thumb" alt=""></td>
                <td><div class="fw-semibold" style="color:#fff"><?= htmlspecialchars($img['caption'] ?: '—') ?></div><div class="small text-secondary"><?= htmlspecialchars($img['image_alt'] ?: '') ?></div></td>
                <td><span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25"><?= htmlspecialchars($img['layout']) ?></span></td>
                <td><?= (int)$img['sort_order'] ?></td>
                <td><?php if ($img['is_active']): ?><span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25">Active</span><?php else: ?><span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25">Hidden</span><?php endif; ?></td>
                <td>
                  <a href="?tab=gallery&edit_image=<?= (int)$img['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete image #<?= (int)$img['id'] ?>?');">
                    <input type="hidden" name="action" value="delete_gallery_image"><input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
                    <button class="btn btn-sm btn-del">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($tab === 'reels'): ?>
    <!-- ================= REELS TAB ================= -->
    <div class="topbar">
      <div><h1>Reels</h1><div class="crumb">Content / Reels / Manage</div></div>
      <?php if ($editingReel): ?><a href="about.php?tab=reels" class="btn btn-outline"><i class="bi bi-plus-lg me-1"></i> Add New Reel</a><?php endif; ?>
    </div>

    <?php if ($reelsSection): ?>
    <div class="card">
      <h2><i class="bi bi-layout-text-window me-2"></i>Reels Section Header</h2>
      <form method="post">
        <input type="hidden" name="action" value="save_reels_section">
        <input type="hidden" name="section_id" value="<?= (int)$reelsSection['id'] ?>">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Eyebrow</label>
            <input name="eyebrow" class="form-control" required value="<?= htmlspecialchars($reelsSection['eyebrow']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Title Line 1</label>
            <input name="title_line1" class="form-control" required value="<?= htmlspecialchars($reelsSection['title_line1']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Emphasis Word</label>
            <input name="title_emphasis" class="form-control" required value="<?= htmlspecialchars($reelsSection['title_emphasis']) ?>"></div>
          <div class="col-md-8"><label class="form-label">Intro Copy</label>
            <textarea name="intro_copy" rows="2" class="form-control" required><?= htmlspecialchars($reelsSection['intro_copy']) ?></textarea></div>
          <div class="col-md-2"><label class="form-label">CTA Text</label>
            <input name="cta_text" class="form-control" value="<?= htmlspecialchars($reelsSection['cta_text']) ?>"></div>
          <div class="col-md-2"><label class="form-label">CTA Link</label>
            <input name="cta_link" class="form-control" value="<?= htmlspecialchars($reelsSection['cta_link']) ?>"></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="section_active" id="reels_active" <?= !empty($reelsSection['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="reels_active">Section visible on site</label></div>
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> Save Header</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2><i class="bi bi-play-btn me-2"></i><?= $editingReel ? 'Edit Reel #' . (int)$editingReel['id'] : 'Add New Reel' ?></h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_reel">
        <input type="hidden" name="section_id" value="<?= (int)($reelsSection['id'] ?? 1) ?>">
        <input type="hidden" name="reel_id" value="<?= $editingReel ? (int)$editingReel['id'] : '' ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Upload Video *</label>
            <?php if (!empty($editingReel['video_url'])): ?>
              <video class="reel-video-preview" src="../<?= htmlspecialchars($editingReel['video_url']) ?>"
                     muted playsinline preload="metadata"></video>
            <?php endif; ?>
            <input type="file" name="video_file" class="form-control" accept="video/mp4,video/webm,video/quicktime">
            <div class="form-text text-secondary mt-1">MP4/WebM/MOV. Uploads → <code>uploads/reels/</code></div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Or Video URL</label>
            <input name="video_url" class="form-control"
                   placeholder="https://… or uploads/reels/…"
                   value="<?= htmlspecialchars($editingReel['video_url'] ?? '') ?>">

            <label class="form-label mt-2">Poster Image (optional)</label>
            <?php if (!empty($editingReel['poster_url'])): ?>
              <img class="g-preview" src="../<?= htmlspecialchars($editingReel['poster_url']) ?>" alt="">
            <?php endif; ?>
            <input type="file" name="poster_file" class="form-control" accept="image/*">
            <input name="poster_url" class="form-control mt-2" placeholder="Or paste poster URL"
                   value="<?= htmlspecialchars($editingReel['poster_url'] ?? '') ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">Click Link (Reel URL) *</label>
            <input name="link_url" class="form-control"
                   placeholder="https://www.instagram.com/reel/… or /contact"
                   value="<?= htmlspecialchars($editingReel['link_url'] ?? '') ?>">
            <div class="form-text text-secondary mt-1">Where the card sends users when clicked.</div>

            <label class="form-label mt-2">Tag</label>
            <input name="tag" class="form-control" placeholder="e.g. SITE"
                   value="<?= htmlspecialchars($editingReel['tag'] ?? '') ?>">

            <label class="form-label mt-2">Meta Label</label>
            <input name="meta_label" class="form-control" placeholder="e.g. APPLICATION"
                   value="<?= htmlspecialchars($editingReel['meta_label'] ?? '') ?>">
          </div>

          <div class="col-md-5">
            <label class="form-label">Title</label>
            <input name="title" class="form-control" placeholder="e.g. Fly ash brick wall"
                   value="<?= htmlspecialchars($editingReel['title'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Subtitle</label>
            <input name="subtitle" class="form-control" placeholder="e.g. Surat, Gujarat"
                   value="<?= htmlspecialchars($editingReel['subtitle'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Sort Order</label>
            <input name="sort_order" type="number" class="form-control"
                   value="<?= htmlspecialchars((string)($editingReel['sort_order'] ?? count($reelsItems) + 1)) ?>">
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_active" id="reel_active"
                     <?= (!$editingReel || !empty($editingReel['is_active'])) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="reel_active">Show on site</label>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-4">
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> <?= $editingReel ? 'Update Reel' : 'Add Reel' ?></button>
          <?php if ($editingReel): ?><a href="about.php?tab=reels" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2><i class="bi bi-list-ul me-2"></i>All Reels (<?= count($reelsItems) ?>)</h2>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr>
            <th style="width:60px">ID</th>
            <th style="width:80px">Preview</th>
            <th>Title / Tag</th>
            <th style="width:160px">Link</th>
            <th style="width:80px">Order</th>
            <th style="width:90px">Status</th>
            <th style="width:150px">Actions</th>
          </tr></thead>
          <tbody>
            <?php if (empty($reelsItems)): ?>
              <tr><td colspan="7" class="text-center text-secondary py-4">No reels yet. Add your first one above.</td></tr>
            <?php else: foreach ($reelsItems as $r): ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td>
                  <?php if (!empty($r['video_url'])): ?>
                    <video class="reel-thumb" src="../<?= htmlspecialchars($r['video_url']) ?>"
                           muted playsinline preload="metadata"
                           <?php if (!empty($r['poster_url'])): ?>poster="../<?= htmlspecialchars($r['poster_url']) ?>"<?php endif; ?>></video>
                  <?php elseif (!empty($r['poster_url'])): ?>
                    <img class="reel-thumb" src="../<?= htmlspecialchars($r['poster_url']) ?>" alt="">
                  <?php else: ?>
                    <div class="logo-placeholder"><i class="bi bi-play-btn"></i></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="fw-semibold" style="color:#fff"><?= htmlspecialchars($r['title'] ?: '—') ?></div>
                  <div class="small text-secondary">
                    <?= htmlspecialchars($r['tag'] ?: '') ?>
                    <?= $r['meta_label'] ? ' · ' . htmlspecialchars($r['meta_label']) : '' ?>
                    <?= $r['subtitle'] ? ' · ' . htmlspecialchars($r['subtitle']) : '' ?>
                  </div>
                </td>
                <td class="small" style="color:#a9b1a9;word-break:break-all">
                  <?= $r['link_url'] ? '<a href="' . htmlspecialchars($r['link_url']) . '" target="_blank">Open ↗</a>' : '<span class="text-secondary">—</span>' ?>
                </td>
                <td><?= (int)$r['sort_order'] ?></td>
                <td>
                  <?php if ($r['is_active']): ?>
                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25">Active</span>
                  <?php else: ?>
                    <span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25">Hidden</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="?tab=reels&edit_reel=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete reel #<?= (int)$r['id'] ?>?');">
                    <input type="hidden" name="action" value="delete_reel">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-del">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($tab === 'enquiries'): ?>
    <!-- ================= ENQUIRIES TAB ================= -->
    <div class="topbar">
      <div><h1>Enquiries</h1><div class="crumb">Inbox / Customer Enquiries / <?= ucfirst($filterStatus) ?></div></div>
      <a href="../index.php#contact" target="_blank" class="btn btn-outline"><i class="bi bi-box-arrow-up-right me-1"></i> Open Form</a>
    </div>
    <div class="enq-filters">
      <?php $tabs = ['all'=>'All','pending'=>'Pending','contacted'=>'Contacted','archived'=>'Archived'];
      foreach ($tabs as $key => $label):
          $active = $filterStatus === $key ? 'active' : '';
          $count = $counts[$key] ?? 0; ?>
        <a href="about.php?tab=enquiries&status=<?= $key ?>" class="enq-filter-btn <?= $active ?>"><?= $label ?> <span class="badge-count"><?= (int)$count ?></span></a>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h2><i class="bi bi-inbox me-2"></i><?= ucfirst($filterStatus) ?> Enquiries <span class="text-secondary" style="font-weight:400;font-size:12px">(<?= count($enquiries) ?>)</span></h2>
      <?php if (empty($enquiries)): ?>
        <div class="empty-state"><i class="bi bi-inbox"></i><p>No enquiries found.</p></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th style="width:60px">ID</th><th>From</th><th>Contact</th><th>Message</th><th style="width:120px">Status</th><th style="width:130px">Received</th><th style="width:260px">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($enquiries as $e): ?>
                <tr>
                  <td>#<?= (int)$e['id'] ?></td>
                  <td><div class="enq-name"><?= htmlspecialchars($e['name'] ?? '—') ?></div><div class="enq-sub"><?= htmlspecialchars($e['product'] ?? '—') ?></div></td>
                  <td>
                    <div style="font-size:12.5px"><i class="bi bi-telephone me-1" style="color:var(--gold)"></i><?= htmlspecialchars($e['phone'] ?? '—') ?></div>
                    <?php if (!empty($e['email'])): ?><div class="enq-sub" style="margin-top:4px"><i class="bi bi-envelope me-1" style="color:var(--gold)"></i><?= htmlspecialchars($e['email']) ?></div><?php endif; ?>
                  </td>
                  <td><div class="enq-msg" title="<?= htmlspecialchars($e['message'] ?? '') ?>"><?= nl2br(htmlspecialchars($e['message'] ?? '')) ?></div></td>
                  <td><?php $st = $e['status'] ?? 'pending'; ?><span class="status-badge <?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></span></td>
                  <td class="small text-secondary"><?= htmlspecialchars($e['created_at'] ?? '—') ?></td>
                  <td>
                    <div class="enq-actions">
                      <?php if (($e['status'] ?? 'pending') !== 'contacted'): ?>
                        <form method="post" class="d-inline"><input type="hidden" name="action" value="enquiry_status"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="status" value="contacted"><button class="btn btn-sm btn-success-soft"><i class="bi bi-check-lg me-1"></i> Contacted</button></form>
                      <?php endif; ?>
                      <?php if (($e['status'] ?? 'pending') !== 'pending'): ?>
                        <form method="post" class="d-inline"><input type="hidden" name="action" value="enquiry_status"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="status" value="pending"><button class="btn btn-sm btn-muted-soft"><i class="bi bi-arrow-counterclockwise me-1"></i> Pending</button></form>
                      <?php endif; ?>
                      <?php if (($e['status'] ?? 'pending') !== 'archived'): ?>
                        <form method="post" class="d-inline"><input type="hidden" name="action" value="enquiry_status"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="status" value="archived"><button class="btn btn-sm btn-outline"><i class="bi bi-archive me-1"></i> Archive</button></form>
                      <?php endif; ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete enquiry #<?= (int)$e['id'] ?> permanently?');"><input type="hidden" name="action" value="enquiry_delete"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><button class="btn btn-sm btn-del"><i class="bi bi-trash"></i></button></form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ================= CUSTOMERS TAB ================= -->
    <div class="topbar">
      <div><h1>Customers</h1><div class="crumb">Content / Customers / Manage</div></div>
      <?php if ($editingClient): ?><a href="about.php?tab=customers" class="btn btn-outline"><i class="bi bi-plus-lg me-1"></i> New Client</a><?php endif; ?>
    </div>

    <?php if ($custSection): ?>
    <div class="card">
      <h2><i class="bi bi-layout-text-window me-2"></i>Section Header, Stats &amp; Photo Strip</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_section">
        <input type="hidden" name="section_id" value="<?= (int)$custSection['id'] ?>">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label">Tagline</label><input name="tagline" class="form-control" required value="<?= htmlspecialchars($custSection['tagline']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Title Line 1</label><input name="title_line1" class="form-control" required value="<?= htmlspecialchars($custSection['title_line1']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Emphasis Word</label><input name="title_emphasis" class="form-control" required value="<?= htmlspecialchars($custSection['title_emphasis']) ?>"></div>
          <div class="col-md-8"><label class="form-label">Lead Copy</label><textarea name="lead_copy" rows="2" class="form-control" required><?= htmlspecialchars($custSection['lead_copy']) ?></textarea></div>
          <div class="col-md-2"><label class="form-label">CTA Text</label><input name="cta_text" class="form-control" value="<?= htmlspecialchars($custSection['cta_text']) ?>"></div>
          <div class="col-md-2"><label class="form-label">CTA Link</label><input name="cta_link" class="form-control" value="<?= htmlspecialchars($custSection['cta_link']) ?>"></div>
        </div>
        <h2 style="font-size:13px"><i class="bi bi-bar-chart me-2"></i>Stats Strip</h2>
        <div class="row g-2 mb-3">
          <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="col-md-6"><div class="photo-cell" style="padding:10px"><div class="row g-2">
              <div class="col-5"><label class="form-label">Value <?= $i ?></label><input name="stat<?= $i ?>_value" class="form-control" value="<?= htmlspecialchars($custSection["stat{$i}_value"]) ?>"></div>
              <div class="col-7"><label class="form-label">Label <?= $i ?></label><input name="stat<?= $i ?>_label" class="form-control" value="<?= htmlspecialchars($custSection["stat{$i}_label"]) ?>"></div>
            </div></div></div>
          <?php endfor; ?>
        </div>
        <h2 style="font-size:13px"><i class="bi bi-images me-2"></i>Photo Strip (3 photos)</h2>
        <?php for ($i = 1; $i <= 3; $i++):
            $pu = $custSection["photo{$i}_url"]; $pt = $custSection["photo{$i}_tag"]; $pc = $custSection["photo{$i}_caption"]; ?>
          <div class="photo-cell"><div class="row g-2">
            <div class="col-md-4"><label class="form-label">Photo <?= $i ?> (upload)</label>
              <?php if ($pu): ?><img src="../<?= htmlspecialchars($pu) ?>" class="photo-preview" alt=""><?php endif; ?>
              <input type="file" name="photo<?= $i ?>_file" class="form-control" accept="image/*"></div>
            <div class="col-md-4"><label class="form-label">Or URL</label>
              <input name="photo<?= $i ?>_url" class="form-control" value="<?= htmlspecialchars($pu ?? '') ?>">
              <label class="form-label mt-2">Tag</label><input name="photo<?= $i ?>_tag" class="form-control" value="<?= htmlspecialchars($pt) ?>"></div>
            <div class="col-md-4"><label class="form-label">Caption</label><input name="photo<?= $i ?>_caption" class="form-control" value="<?= htmlspecialchars($pc) ?>"></div>
          </div></div>
        <?php endfor; ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="section_active" id="section_active" <?= !empty($custSection['is_active']) ? 'checked' : '' ?>><label class="form-check-label small" for="section_active">Section visible on site</label></div>
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> Save Section</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2><i class="bi bi-person-plus me-2"></i><?= $editingClient ? 'Edit Client #' . (int)$editingClient['id'] : 'Add New Client' ?></h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_client">
        <input type="hidden" name="client_id" value="<?= $editingClient ? (int)$editingClient['id'] : '' ?>">
        <input type="hidden" name="existing_logo" value="<?= htmlspecialchars($editingClient['logo_url'] ?? '') ?>">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Client Name *</label><input name="client_name" class="form-control" required value="<?= htmlspecialchars($editingClient['client_name'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="form-label">Type *</label><select name="client_type" class="form-select" required>
            <?php foreach ($clientTypes as $t): ?><option value="<?= $t ?>" <?= ($editingClient['client_type'] ?? 'CONTRACTOR') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
          </select></div>
          <div class="col-md-3"><label class="form-label">Sort Order</label><input name="sort_order" type="number" class="form-control" value="<?= htmlspecialchars((string)($editingClient['sort_order'] ?? count($clients) + 1)) ?>"></div>
          <div class="col-md-8"><label class="form-label">Short Note</label><input name="client_note" class="form-control" value="<?= htmlspecialchars($editingClient['client_note'] ?? '') ?>"></div>
          <div class="col-md-4"><label class="form-label">Icon</label><select name="icon_class" class="form-select">
            <?php foreach ($iconChoices as $val => $lbl): ?><option value="<?= $val ?>" <?= ($editingClient['icon_class'] ?? 'bi-building') === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
          </select></div>
          <div class="col-md-6"><label class="form-label">Upload Logo</label>
            <?php if (!empty($editingClient['logo_url'])): ?><img src="../<?= htmlspecialchars($editingClient['logo_url']) ?>" class="logo-preview" alt=""><?php endif; ?>
            <input type="file" name="logo_file" class="form-control" accept="image/*"></div>
          <div class="col-md-6"><label class="form-label">Or paste Logo URL</label>
            <input name="logo_url" class="form-control" value="<?= htmlspecialchars($editingClient['logo_url'] ?? '') ?>">
            <label class="form-label mt-2">Marquee Text (optional)</label>
            <input name="marquee_text" class="form-control" value="<?= htmlspecialchars($editingClient['marquee_text'] ?? '') ?>">
            <div class="d-flex flex-wrap gap-3 mt-3">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" <?= !empty($editingClient['is_featured']) ? 'checked' : '' ?>><label class="form-check-label small" for="is_featured">Featured</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="show_marquee" id="show_marquee" <?= (!$editingClient || !empty($editingClient['show_marquee'])) ? 'checked' : '' ?>><label class="form-check-label small" for="show_marquee">Marquee</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="client_active" <?= (!$editingClient || !empty($editingClient['is_active'])) ? 'checked' : '' ?>><label class="form-check-label small" for="client_active">Active</label></div>
            </div></div>
        </div>
        <div class="d-flex gap-2 mt-4">
          <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i> <?= $editingClient ? 'Update Client' : 'Create Client' ?></button>
          <?php if ($editingClient): ?><a href="about.php?tab=customers" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2><i class="bi bi-list-ul me-2"></i>All Clients (<?= count($clients) ?>)</h2>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th style="width:60px">ID</th><th style="width:80px">Logo</th><th>Name / Type</th><th style="width:80px">Order</th><th style="width:90px">Status</th><th style="width:150px">Actions</th></tr></thead>
          <tbody>
            <?php if (empty($clients)): ?>
              <tr><td colspan="6" class="text-center text-secondary py-4">No clients yet.</td></tr>
            <?php else: foreach ($clients as $c): ?>
              <tr>
                <td>#<?= (int)$c['id'] ?></td>
                <td><?php if (!empty($c['logo_url'])): ?><img src="../<?= htmlspecialchars($c['logo_url']) ?>" class="logo-thumb" alt=""><?php else: ?><div class="logo-placeholder"><i class="bi bi-image"></i></div><?php endif; ?></td>
                <td><div class="fw-semibold" style="color:#fff"><?= htmlspecialchars($c['client_name']) ?></div><div class="small text-secondary"><?= htmlspecialchars($c['client_type']) ?><?php if (!empty($c['is_featured'])): ?> · <span style="color:var(--gold)">★ Featured</span><?php endif; ?></div></td>
                <td><?= (int)$c['sort_order'] ?></td>
                <td><?php if ($c['is_active']): ?><span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25">Active</span><?php else: ?><span class="badge bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-25">Hidden</span><?php endif; ?></td>
                <td>
                  <a href="?tab=customers&edit_client=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete client?');"><input type="hidden" name="action" value="delete_client"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-del">Delete</button></form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </main>
</div>

<script>
function addStat() {
  const wrap = document.getElementById('statsWrap');
  if (!wrap) return;
  const div = document.createElement('div');
  div.className = 'stat-row row g-2 align-items-center';
  div.innerHTML = `
    <div class="col-md-3"><input name="stat_value[]" class="form-control" placeholder="Value" required></div>
    <div class="col-md-5"><input name="stat_label[]" class="form-control" placeholder="Label" required></div>
    <div class="col-md-2"><input name="stat_order[]" type="number" class="form-control" placeholder="Order"></div>
    <div class="col-md-2"><button type="button" class="btn btn-del w-100" onclick="this.closest('.stat-row').remove()">Remove</button></div>`;
  wrap.appendChild(div);
}
</script>
</body>
</html>