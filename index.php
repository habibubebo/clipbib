<?php
$notesDir = __DIR__ . '/notes';
if (!is_dir($notesDir)) mkdir($notesDir, 0777, true);

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$messages = [];

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'create' || $act === 'update') {
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $tags = trim($_POST['tags'] ?? '');

        if ($act === 'create') {
            $filename = date('Ymd_His') . '.md';
            $id = $filename;
        } else {
            $id = $_POST['id'] ?? '';
        }

        $file = $notesDir . '/' . basename($id);
        $title = str_replace('"', '\"', $title);
        $tags = str_replace('"', '\"', $tags);
        $pinned = 'false';
        if ($act === 'update') {
            $old = parseNote($file);
            $pinned = $old['pinned'] ? 'true' : 'false';
        }
        $frontmatter = "---\ntitle: \"" . $title . "\"\ntags: \"" . $tags . "\"\npinned: \"" . $pinned . "\"\ndate: \"" . date('Y-m-d H:i') . "\"\n---\n\n";
        file_put_contents($file, $frontmatter . $content);
        header('Location: ?action=list&saved=1');
        exit;
    }

    if ($act === 'pin' && ($id = $_POST['id'] ?? ($id ?? null))) {
        $file = $notesDir . '/' . basename($id);
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $pinned = preg_match('/^pinned:[ \t]*"(true)"[ \t]*$/m', $raw) ? 'true' : 'false';
            $newVal = $pinned === 'true' ? 'false' : 'true';
            if (preg_match('/^pinned:[ \t]*"[^"]*"[ \t]*$/m', $raw)) {
                $raw = preg_replace('/^pinned:[ \t]*"[^"]*"[ \t]*$/m', 'pinned: "' . $newVal . '"', $raw, 1);
            } else {
                $raw = preg_replace('/^(---\r?\n)/', '$1pinned: "' . $newVal . '"' . "\n", $raw, 1);
            }
            file_put_contents($file, $raw);
        }
        header('Location: ?action=view&id=' . urlencode($id));
        exit;
    }

    if ($act === 'delete' && ($id = $_POST['id'] ?? ($id ?? null))) {
        $file = $notesDir . '/' . basename($id);
        if (file_exists($file)) unlink($file);
        header('Location: ?action=list');
        exit;
    }
}

// --- Helper: parse frontmatter ---
function parseNote($filepath) {
    $raw = file_get_contents($filepath);
    $meta = ['title' => '', 'tags' => '', 'date' => date('Y-m-d H:i', filemtime($filepath)), 'pinned' => false, 'content' => $raw];
    if (preg_match('/^---\n(.+?)\n---\n(.*)/s', $raw, $m)) {
        $yaml = $m[1];
        $meta['content'] = trim($m[2]);
        if (preg_match('/^title:[ \t]*"(.*)"[ \t]*$/m', $yaml, $t)) $meta['title'] = str_replace('\\"', '"', trim($t[1]));
        elseif (preg_match('/^title:[ \t]*(.*)$/m', $yaml, $t)) $meta['title'] = trim($t[1]);
        if (preg_match('/^tags:[ \t]*"(.*)"[ \t]*$/m', $yaml, $t)) $meta['tags'] = str_replace('\\"', '"', trim($t[1]));
        elseif (preg_match('/^tags:[ \t]*(.*)$/m', $yaml, $t)) $meta['tags'] = trim($t[1]);
        if (preg_match('/^date:[ \t]*"(.*)"[ \t]*$/m', $yaml, $t)) $meta['date'] = trim($t[1]);
        elseif (preg_match('/^date:[ \t]*(.*)$/m', $yaml, $t)) $meta['date'] = trim($t[1]);
        if (preg_match('/^pinned:[ \t]*"(.*)"[ \t]*$/m', $yaml, $t)) $meta['pinned'] = trim($t[1]) === 'true';
    }
    $meta['id'] = basename($filepath);
    $contentText = strip_tags($meta['content']);
    $meta['preview'] = mb_substr($contentText, 0, 120) . (mb_strlen($contentText) > 120 ? '...' : '');
    return $meta;
}

// --- Relative time in Indonesian ---
function relTime($datetime) {
    if (!$datetime || !strtotime($datetime)) return '';
    $now = time();
    $ts = strtotime($datetime);
    $diff = $now - $ts;
    if ($diff < 60) return 'Baru saja';
    if ($diff < 3600) return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400) return floor($diff / 3600) . ' jam lalu';
    if ($diff < 172800) return 'Kemarin';
    if ($diff < 604800) return floor($diff / 86400) . ' hari lalu';
    return date('d M Y', $ts);
}

// --- Simple Markdown to HTML ---
function md2html($text) {
    $text = htmlspecialchars($text);
    $text = preg_replace('/^###\s+(.+)/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^##\s+(.+)/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^#\s+(.+)/m', '<h1>$1</h1>', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    $text = preg_replace('/^- (.+)/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $text);
    $text = preg_replace('/(https?:\/\/[^\s<]+)/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
    $text = nl2br($text);
    return $text;
}

// --- Gather notes ---
$notes = [];
foreach (glob($notesDir . '/*.md') as $f) {
    $notes[] = parseNote($f);
}
usort($notes, function($a, $b) {
    if ($a['pinned'] !== $b['pinned']) return $a['pinned'] ? -1 : 1;
    return strcmp($b['date'], $a['date']);
});

// Search
$query = $_GET['q'] ?? '';
if ($query) {
    $q = strtolower($query);
    $notes = array_filter($notes, fn($n) => str_contains(strtolower($n['title'] . $n['content'] . $n['tags']), $q));
}

// --- Render ---
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="ClipBib">
<meta name="theme-color" content="#111111">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" href="favicon.png">
<link rel="apple-touch-icon" href="favicon.png">
<title>ClipBib</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#111111;--surface:#1a1a1a;--surface2:#242424;--surface3:#2e2e2e;
    --text:#e8e8e8;--text2:#999;--text3:#666;
    --accent:#6ee7b7;--accent2:#34d399;--danger:#f87171;
    --radius:14px;--radius-sm:10px;
  }
  html{font-size:16px;-webkit-tap-highlight-color:transparent}
  body{
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;
    background:var(--bg);color:var(--text);
    min-height:100dvh;
    display:flex;justify-content:center;
  }
  .app{width:100%;max-width:430px;min-height:100dvh;position:relative;
    display:flex;flex-direction:column;background:var(--bg);
    padding-bottom:env(safe-area-inset-bottom,0);
  }

  /* --- Header --- */
  .header{
    position:sticky;top:0;z-index:100;
    background:rgba(17,17,17,0.85);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
    padding:16px 20px 12px;
    border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .header-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
  .header h1{font-size:1.5rem;font-weight:700;letter-spacing:-0.5px}
  .header h1 span{color:var(--accent)}
  .header-count{font-size:0.72rem;color:var(--accent2);font-weight:600;
    padding:4px 10px;background:rgba(110,231,183,0.08);border-radius:20px}
  .search-wrap{position:relative}
  .search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text3);pointer-events:none}
  .search{
    width:100%;padding:10px 12px 10px 38px;
    background:var(--surface2);border:1px solid rgba(255,255,255,0.06);
    border-radius:var(--radius-sm);color:var(--text);font-size:0.9rem;
    outline:none;transition:border-color .2s;
  }
  .search:focus{border-color:var(--accent)}
  .search::placeholder{color:var(--text3)}

  /* --- Content --- */
  .content{flex:1;overflow-y:auto;padding:12px 16px 100px}
  .empty{text-align:center;padding:60px 20px;color:var(--text3)}
  .empty svg{margin:0 auto 16px;display:block;color:var(--surface3)}
  .empty p{font-size:0.95rem;margin-bottom:4px}
  .empty small{font-size:0.8rem;color:var(--text3)}

  /* --- Note Card --- */
  .note-card{
    background:var(--surface);border:1px solid rgba(255,255,255,0.05);
    border-radius:var(--radius);padding:18px 16px 14px;margin-bottom:10px;
    transition:transform .15s,border-color .2s,background .15s;cursor:pointer;
    text-decoration:none;color:inherit;display:block;position:relative;
    -webkit-touch-callout:none;-webkit-user-select:none;user-select:none;
    touch-action:manipulation;
  }
  .note-card *{-webkit-touch-callout:none;-webkit-user-select:none;-webkit-user-drag:none;user-select:none}
  .note-card:hover{border-color:rgba(110,231,183,0.25)}
  .note-card:active{transform:scale(0.98);background:var(--surface2)}
  .note-card-title{font-size:1.02rem;font-weight:600;margin-bottom:5px;line-height:1.35;
    letter-spacing:-0.2px;padding-right:22px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .card-pin{position:absolute;top:14px;right:14px;color:var(--accent);
    display:flex;align-items:center;justify-content:center}
  .note-card:hover .card-pin{transform:scale(1.1);transition:transform .15s}
  .note-card-preview{font-size:0.82rem;color:var(--text2);line-height:1.55;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .note-card-preview:not(:empty){margin-bottom:14px;min-height:2.5em}
  .note-card-preview:empty{display:none}
  .note-card-footer{display:flex;align-items:center;justify-content:space-between;gap:8px}
  .note-card-date{font-size:0.72rem;color:var(--text3);white-space:nowrap}
  .note-card-tags{display:flex;align-items:center;gap:6px;justify-content:flex-end;flex-wrap:wrap}
  .note-tag{font-size:0.68rem;padding:3px 9px;background:var(--surface3);border-radius:20px;color:var(--accent2);font-weight:500}

  /* --- Context menu (right-click / long-press) --- */
  .note-menu{
    position:fixed;z-index:200;min-width:190px;
    background:var(--surface2);border:1px solid rgba(255,255,255,0.09);
    border-radius:14px;padding:6px;
    box-shadow:0 14px 36px rgba(0,0,0,0.55);
    animation:menuIn .13s ease;
  }
  .note-menu[hidden]{display:none}
  .note-menu-item{
    display:flex;align-items:center;gap:11px;width:100%;
    padding:10px 12px;border:none;background:none;color:var(--text);
    font-size:0.88rem;font-weight:500;border-radius:9px;cursor:pointer;
    text-decoration:none;font-family:inherit;text-align:left;
  }
  .note-menu-item:active{background:var(--surface3)}
  .note-menu-item.danger{color:var(--danger)}
  .note-menu-sep{height:1px;background:rgba(255,255,255,0.06);margin:6px 2px}
  @keyframes menuIn{from{opacity:0;transform:scale(.95) translateY(-6px)}to{opacity:1;transform:none}}

  /* --- PWA install toast --- */
  .pwa-toast{
    position:fixed;left:50%;bottom:92px;transform:translateX(-50%);
    width:min(100% - 32px, 430px);box-sizing:border-box;
    background:var(--surface2);border:1px solid rgba(255,255,255,0.09);
    border-radius:16px;padding:14px;z-index:300;
    display:flex;align-items:center;gap:12px;
    box-shadow:0 14px 36px rgba(0,0,0,0.55);
    animation:toastIn .25s ease;
  }
  .pwa-toast[hidden]{display:none}
  .pwa-toast-icon{width:38px;height:38px;border-radius:10px;
    background:rgba(110,231,183,0.15);color:var(--accent);
    display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .pwa-toast-text{flex:1;min-width:0}
  .pwa-toast-text strong{display:block;font-size:.9rem;font-weight:600;color:var(--text)}
  .pwa-toast-text span{font-size:.75rem;color:var(--text2)}
  .pwa-toast-actions{display:flex;gap:8px;align-items:center;flex-shrink:0}
  .btn-ghost,.btn-accent{border:none;border-radius:20px;padding:9px 16px;
    font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer}
  .btn-ghost{background:var(--surface3);color:var(--text2)}
  .btn-ghost:active{background:var(--surface3);opacity:.8}
  .btn-accent{background:var(--accent);color:var(--bg)}
  .btn-accent:active{opacity:.9}
  @keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(14px)}
    to{opacity:1;transform:translateX(-50%) translateY(0)}}

  /* --- FAB --- */
  .fab{
    position:fixed;bottom:24px;right:calc(50% - 195px);
    width:56px;height:56px;border-radius:50%;border:none;
    background:var(--accent);color:var(--bg);
    font-size:1.8rem;font-weight:300;line-height:1;
    text-decoration:none;outline:none;-webkit-tap-highlight-color:transparent;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 0 0 rgba(110,231,183,0) !important;
    cursor:pointer;transition:transform .15s;
    z-index:90;
  }
  .fab:active{transform:scale(0.9)}

  /* --- Editor (seamless sheet) --- */
  .editor{padding:0 16px 100px}
  .editor-topbar{display:flex;align-items:center;gap:10px}
  .topbar-title{flex:1;font-size:0.95rem;font-weight:600;color:var(--text2);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .btn-back{
    width:36px;height:36px;border-radius:50%;border:none;
    background:var(--surface2);color:var(--text);cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:background .15s;
  }
  .btn-back:active{background:var(--surface3)}
  .btn-icon{
    width:32px;height:32px;border-radius:50%;border:none;
    background:var(--surface2);color:var(--text2);cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:background .15s,color .15s;
  }
  .btn-icon:active{background:var(--surface3)}
  .btn-icon.active{background:rgba(110,231,183,0.15);color:var(--accent)}
  .btn-icon.active svg{fill:currentColor}
  .editor-topbar form{display:flex}
  .btn-tag{font-size:1.1rem;font-weight:700;line-height:1}
  .btn-icon-danger{color:var(--danger)}
  .btn-icon-danger:active{background:rgba(248,113,113,0.15)}

  .tag-panel{max-height:0;overflow:hidden;opacity:0;
    transition:max-height .25s ease,opacity .2s ease;margin-bottom:0}
  .tag-panel.open{max-height:64px;opacity:1;margin-bottom:14px}
  .tag-input{
    width:100%;padding:10px 14px;border:none;border-radius:var(--radius-sm);
    background:var(--surface2);color:var(--accent2);font-size:0.85rem;outline:none;
  }
  .tag-input::placeholder{color:var(--text3)}

  .note-sheet{
    background:var(--surface);border:1px solid rgba(255,255,255,0.05);
    border-radius:var(--radius);padding:2px 16px 18px;margin-bottom:16px;
    transition:border-color .2s;
  }
  .note-sheet:focus-within{border-color:rgba(110,231,183,0.25)}
  .note-title{
    width:100%;background:transparent;border:none;outline:none;padding:18px 0 10px;
    font-size:1.35rem;font-weight:700;letter-spacing:-0.3px;color:var(--text);
    font-family:inherit;line-height:1.3;
  }
  .note-title::placeholder{color:var(--text3)}
  .sheet-divider{height:1px;background:rgba(255,255,255,0.06);margin-bottom:6px}
  .note-body{
    width:100%;background:transparent;border:none;outline:none;resize:none;
    padding:8px 0 6px;min-height:40dvh;color:var(--text);
    font-family:'SF Mono',ui-monospace,monospace;font-size:0.88rem;line-height:1.75;
  }
  .note-body::placeholder{color:var(--text3)}
  .btn-save-top{
    height:32px;padding:0 16px;border:none;border-radius:16px;
    background:var(--accent);color:var(--bg);
    font-size:0.82rem;font-weight:700;cursor:pointer;
    transition:transform .15s,background .15s;
  }
  .btn-save-top:active{transform:scale(0.94)}

  /* --- Viewer --- */
  .viewer{padding:0 16px 60px}
  .viewer-title{font-size:1.4rem;font-weight:700;letter-spacing:-0.3px;margin-bottom:6px;line-height:1.3}
  .viewer-meta{display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap}
  .viewer-date{font-size:0.78rem;color:var(--text3)}
  .viewer-body{font-size:0.95rem;line-height:1.8;color:var(--text)}
  .viewer-body h1{font-size:1.3rem;font-weight:700;margin:24px 0 10px}
  .viewer-body h2{font-size:1.15rem;font-weight:600;margin:20px 0 8px}
  .viewer-body h3{font-size:1.05rem;font-weight:600;margin:16px 0 6px}
  .viewer-body strong{color:var(--accent)}
  .viewer-body em{color:var(--text2)}
  .viewer-body code{
    background:var(--surface2);padding:2px 6px;border-radius:5px;
    font-family:'SF Mono',ui-monospace,monospace;font-size:0.85rem;color:var(--accent2)
  }
  .viewer-body a{color:var(--accent);text-decoration:underline;text-underline-offset:2px}
  .viewer-body ul{padding-left:20px;margin:10px 0}
  .viewer-body li{margin-bottom:4px}
  .viewer-body br{content:'';margin:6px 0;display:block}

  /* --- Scrollbar --- */
  ::-webkit-scrollbar{width:0}

  /* --- Transitions --- */
  .fade-in{animation:fadeIn .25s ease}
  @keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

  @media(min-width:768px){
    .app{border-left:1px solid rgba(255,255,255,0.04);border-right:1px solid rgba(255,255,255,0.04)}
  }
</style>
</head>
<body>
<div class="app">

<?php if ($action === 'list'): ?>
<!-- === LIST VIEW === -->
<header class="header">
  <div class="header-top">
    <h1>Clip<span>Bib</span></h1>
    <span class="header-count"><?= count($notes) ?> catatan</span>
  </div>
  <div class="search-wrap">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <form method="get" action="">
      <input type="hidden" name="action" value="list">
      <input class="search" type="search" name="q" placeholder="Cari catatan..." value="<?= htmlspecialchars($query) ?>" autocomplete="off">
    </form>
  </div>
</header>

<main class="content fade-in">
  <?php if (empty($notes)): ?>
    <div class="empty">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
      <p>Belum ada catatan</p>
      <small>Ketuk + untuk membuat catatan baru</small>
    </div>
  <?php else: ?>
    <?php foreach ($notes as $n): ?>
      <a class="note-card" href="?action=view&id=<?= urlencode($n['id']) ?>" data-id="<?= htmlspecialchars($n['id']) ?>" data-pinned="<?= $n['pinned'] ? '1' : '0' ?>">
        <?php if ($n['pinned']): ?>
          <span class="card-pin" aria-label="Disematkan">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M19 9.7 17.7 8.5 13.4 4l-.4-1.9-1.5 1.4-.7 3.2-5.6 2.6-1.2 1.9 3.7 3.7-4.7 4.7-1.4 2.7a2 2 0 0 0 .6 2.3 2 2 0 0 0 2.3.6l2.7-1.4 4.7-4.7 3.7 3.7 1.9-1.2 2.6-5.6L18 8.5l3.7-3.2-3.6-.9L19 9.7Z"/></svg>
          </span>
        <?php endif; ?>
        <?php if (!empty($n['title'])): ?>
          <div class="note-card-title"><?= htmlspecialchars($n['title']) ?></div>
        <?php endif; ?>
        <div class="note-card-preview"><?= htmlspecialchars($n['preview']) ?></div>
        <div class="note-card-footer">
          <span class="note-card-date"><?= relTime($n['date']) ?></span>
          <?php $tags = array_filter(array_map('trim', explode(',', $n['tags']))); if ($tags): ?>
            <div class="note-card-tags">
              <?php foreach ($tags as $tag): ?>
                <span class="note-tag"><?= htmlspecialchars($tag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<a class="fab" href="?action=create" aria-label="Tambah catatan">+</a>

<div id="noteMenu" class="note-menu" hidden role="menu">
  <a class="note-menu-item" data-act="edit" role="menuitem">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
    Edit
  </a>
  <button type="button" class="note-menu-item" data-act="pin" role="menuitem">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
    <span class="menu-label">Sematkan</span>
  </button>
  <div class="note-menu-sep"></div>
  <button type="button" class="note-menu-item danger" data-act="delete" role="menuitem">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
    Hapus
  </button>
</div>

<div id="pwaToast" class="pwa-toast" hidden>
  <div class="pwa-toast-icon">
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
  </div>
  <div class="pwa-toast-text">
    <strong>Menyukai ClipBib?</strong>
    <span>Install sebagai PWA agar makin cepat dibuka.</span>
  </div>
  <div class="pwa-toast-actions">
    <button class="btn-ghost" data-act="later" type="button">Nanti</button>
    <button class="btn-accent" data-act="install" type="button">Install</button>
  </div>
</div>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<!-- === EDITOR VIEW === -->
<?php
$note = null;
if ($action === 'edit' && $id) {
    $file = $notesDir . '/' . basename($id);
    if (file_exists($file)) $note = parseNote($file);
}
?>
<header class="header">
  <div class="editor-topbar">
    <button class="btn-back" onclick="history.back()" aria-label="Kembali">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <span class="topbar-title"><?= $note ? 'Edit Catatan' : 'Catatan Baru' ?></span>
    <button class="btn-icon btn-tag<?= !empty(trim($note['tags'] ?? '')) ? ' active' : '' ?>" id="tagToggle" type="button" aria-label="Tags">#</button>
    <button class="btn-save-top" type="submit" form="noteForm">Simpan</button>
    <?php if ($note): ?>
      <form method="post" onsubmit="return confirm('Hapus catatan ini?')">
        <input type="hidden" name="act" value="delete">
        <input type="hidden" name="id" value="<?= htmlspecialchars($note['id']) ?>">
        <button class="btn-icon btn-icon-danger" type="submit" aria-label="Hapus">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        </button>
      </form>
    <?php endif; ?>
  </div>
</header>

<main class="editor fade-in">
  <form method="post" id="noteForm">
    <input type="hidden" name="act" value="<?= $note ? 'update' : 'create' ?>">
    <?php if ($note): ?>
      <input type="hidden" name="id" value="<?= htmlspecialchars($note['id']) ?>">
    <?php endif; ?>

    <div class="tag-panel<?= !empty(trim($note['tags'] ?? '')) ? ' open' : '' ?>" id="tagPanel">
      <input class="tag-input" type="text" name="tags" id="tags"
        value="<?= htmlspecialchars($note['tags'] ?? '') ?>" placeholder="Tag, pisahkan koma..." autocomplete="off">
    </div>

    <div class="note-sheet">
      <input class="note-title" type="text" name="title" id="title"
        value="<?= htmlspecialchars($note['title'] ?? '') ?>" placeholder="Judul" autofocus>
      <div class="sheet-divider"></div>
      <textarea class="note-body" name="content" id="content"
        placeholder="Mulai mengetik..."><?= htmlspecialchars($note['content'] ?? '') ?></textarea>
    </div>
  </form>
</main>

<?php elseif ($action === 'view' && $id): ?>
<!-- === VIEWER === -->
<?php
$file = $notesDir . '/' . basename($id);
$note = file_exists($file) ? parseNote($file) : null;
if (!$note) { header('Location: ?action=list'); exit; }
?>
<header class="header">
  <div class="editor-topbar">
    <button class="btn-back" onclick="history.back()" aria-label="Kembali">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
    </button>
    <span class="topbar-title"></span>
    <form method="post" action="?action=view&id=<?= urlencode($id) ?>">
      <input type="hidden" name="act" value="pin">
      <button type="submit" class="btn-icon <?= $note['pinned'] ? 'active' : '' ?>" aria-label="Sematkan">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
      </button>
    </form>
    <a class="btn-icon" href="?action=edit&id=<?= urlencode($id) ?>" aria-label="Edit" style="text-decoration:none">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
    </a>
  </div>
</header>

<main class="viewer fade-in">
  <?php if (!empty($note['title'])): ?>
    <h1 class="viewer-title"><?= htmlspecialchars($note['title']) ?></h1>
  <?php endif; ?>
  <div class="viewer-meta">
    <span class="viewer-date"><?= $note['date'] ?></span>
    <?php foreach (array_filter(array_map('trim', explode(',', $note['tags']))) as $tag): ?>
      <span class="note-tag"><?= htmlspecialchars($tag) ?></span>
    <?php endforeach; ?>
  </div>
  <div class="viewer-body">
    <?= md2html($note['content']) ?>
  </div>
</main>

<?php endif; ?>

</div>

<script>
let deferredPrompt = null;
window.addEventListener('beforeinstallprompt', e => {
  e.preventDefault();
  deferredPrompt = e;
});
window.addEventListener('appinstalled', () => { deferredPrompt = null; });
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}
const isPwaActive = () =>
  window.matchMedia && window.matchMedia('(display-mode: standalone)').matches ||
  navigator.standalone === true;

const pwaToast = document.getElementById('pwaToast');
if (pwaToast && !isPwaActive()) {
  const pwaWatch = () => { if (deferredPrompt && !isPwaActive()) pwaToast.hidden = false; };
  <?php if ($action === 'list' && isset($_GET['saved'])): ?>
  setTimeout(pwaWatch, 2000);
  window.addEventListener('beforeinstallprompt', () => {
    setTimeout(() => { if (deferredPrompt && !isPwaActive()) pwaToast.hidden = false; }, 0);
  });
  <?php endif; ?>
  const installBtn = pwaToast.querySelector('[data-act="install"]');
  const laterBtn = pwaToast.querySelector('[data-act="later"]');
  if (installBtn) installBtn.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    pwaToast.hidden = true;
    deferredPrompt.prompt();
    deferredPrompt = null;
  });
  if (laterBtn) laterBtn.addEventListener('click', () => { pwaToast.hidden = true; });
}

const tagToggle = document.getElementById('tagToggle');
const tagPanel = document.getElementById('tagPanel');
if (tagToggle && tagPanel) {
  tagToggle.addEventListener('click', () => {
    tagPanel.classList.toggle('open');
    tagToggle.classList.toggle('active');
    if (tagPanel.classList.contains('open')) tagPanel.querySelector('input').focus();
  });
}
const noteBody = document.querySelector('.note-body');
if (noteBody) {
  const grow = () => { noteBody.style.height = 'auto'; noteBody.style.height = noteBody.scrollHeight + 'px'; };
  noteBody.addEventListener('input', grow);
  grow();
}
document.querySelectorAll('.search').forEach(s => {
  let t; s.addEventListener('input', e => {
    clearTimeout(t);
    t = setTimeout(() => e.target.form.submit(), 400);
  });
});

const noteMenu = document.getElementById('noteMenu');
if (noteMenu) {
  let currentId = null;
  let longPressed = false;
  let openedByTouch = false;

  const positionMenu = (x, y) => {
    noteMenu.hidden = false;
    const r = noteMenu.getBoundingClientRect();
    let left = x, top = y;
    if (left + r.width > innerWidth - 8) left = Math.max(8, innerWidth - r.width - 8);
    if (top + r.height > innerHeight - 8) top = Math.max(8, innerHeight - r.height - 8);
    noteMenu.style.left = left + 'px';
    noteMenu.style.top = top + 'px';
  };

  const openMenu = (card, x, y, fromTouch) => {
    currentId = card.dataset.id;
    const edit = noteMenu.querySelector('[data-act="edit"]');
    if (edit) edit.href = '?action=edit&id=' + encodeURIComponent(currentId);
    const label = noteMenu.querySelector('.menu-label');
    if (label) label.textContent = (card.dataset.pinned === '1') ? 'Lepas sematan' : 'Sematkan';
    openedByTouch = fromTouch;
    if (fromTouch) setTimeout(() => { openedByTouch = false; }, 700);
    positionMenu(x, y);
  };

  const closeMenu = () => { noteMenu.hidden = true; };

  const killSelection = () => {
    const sel = window.getSelection ? window.getSelection() : null;
    if (sel && sel.removeAllRanges) sel.removeAllRanges();
    if (document.execCommand) { try { document.execCommand('unselect'); } catch (_) {} }
    const ae = document.activeElement;
    if (ae && ae.blur) ae.blur();
  };

  const suppressSelection = e => {
    if (e.target.closest && e.target.closest('.note-card')) e.preventDefault();
  };
  document.addEventListener('selectstart', suppressSelection);
  document.addEventListener('dragstart', suppressSelection);

  document.querySelectorAll('.note-card').forEach(card => {
    card.addEventListener('contextmenu', e => {
      e.preventDefault();
      if (openedByTouch) return;
      openMenu(card, e.clientX, e.clientY, false);
    });

    let lp = null;
    card.addEventListener('touchstart', e => {
      const t = e.touches[0];
      lp = { x: t.clientX, y: t.clientY, timer: setTimeout(() => {
        longPressed = true;
        killSelection();
        openMenu(card, t.clientX, t.clientY, true);
      }, 480) };
    });
    card.addEventListener('touchmove', () => { if (lp) { clearTimeout(lp.timer); lp = null; } });
    card.addEventListener('touchend', () => {
      if (lp) { clearTimeout(lp.timer); lp = null; }
      if (longPressed) setTimeout(killSelection, 0);
    });
    card.addEventListener('click', e => {
      if (longPressed) { e.preventDefault(); longPressed = false; }
    });
  });

  const postAction = (act, id) => {
    const f = document.createElement('form');
    f.method = 'post';
    f.action = '?action=view&id=' + encodeURIComponent(id);
    const a = document.createElement('input');
    a.type = 'hidden'; a.name = 'act'; a.value = act;
    const i = document.createElement('input');
    i.type = 'hidden'; i.name = 'id'; i.value = id;
    f.appendChild(a); f.appendChild(i);
    document.body.appendChild(f);
    f.submit();
  };

  noteMenu.querySelector('[data-act="pin"]').addEventListener('click', () => {
    if (currentId) postAction('pin', currentId);
  });
  noteMenu.querySelector('[data-act="delete"]').addEventListener('click', () => {
    if (!currentId) return;
    if (!confirm('Hapus catatan ini?')) return;
    postAction('delete', currentId);
  });

  document.addEventListener('click', e => {
    if (!noteMenu.hidden && !e.target.closest('.note-card') && !noteMenu.contains(e.target)) closeMenu();
  });
  document.addEventListener('contextmenu', e => {
    if (!noteMenu.hidden && !e.target.closest('.note-card') && !noteMenu.contains(e.target)) closeMenu();
  });
  document.addEventListener('scroll', closeMenu, true);
  window.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
  window.addEventListener('resize', closeMenu);
}
</script>
</body>
</html>
