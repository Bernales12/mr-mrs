<?php
// =========================================================================
// Shared server-side score storage.
// All judges' scores are pooled into ONE JSON file on the server, instead
// of living only in each judge's own browser localStorage. This is what
// lets the Admin's Overall Aggregate / Rank reflect a true server-wide
// total across every judge, on every device.
// =========================================================================

$pageantScoresFile = __DIR__ . '/pageant_scores_store.json';

function pageant_read_scores($file) {
    if (!file_exists($file)) {
        return [];
    }
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $contents = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($contents, true);
    return is_array($data) ? $data : [];
}

function pageant_write_scores($file, $scores) {
    $fp = fopen($file, 'c');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($scores));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'get_scores' && $method === 'GET') {
        echo json_encode(pageant_read_scores($pageantScoresFile));
        exit;
    }

    if ($action === 'save_score' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $key = isset($input['key']) ? $input['key'] : null;
        $value = array_key_exists('value', $input) ? $input['value'] : null;

        if ($key === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing key']);
            exit;
        }

        $scores = pageant_read_scores($pageantScoresFile);
        if ($value === null) {
            unset($scores[$key]);
        } else {
            $scores[$key] = $value;
        }
        pageant_write_scores($pageantScoresFile, $scores);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save_all_scores' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $scores = isset($input['scores']) && is_array($input['scores']) ? $input['scores'] : [];
        pageant_write_scores($pageantScoresFile, $scores);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reset_scores' && $method === 'POST') {
        pageant_write_scores($pageantScoresFile, []);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mr. & Mrs. Smitian - Official Tabulation Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;900&family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #1c1330;
      --primary-header: #2c1a4d;
      --gold: #cfa53c;
      --gold-light: #e8cd82;
      --gold-deep: #a9812a;
      --bg: #0f0a1a;
      --card-bg: #fffdf7;
      --ivory: #faf5e9;
      --border: #e6d4a3;
      --text: #2a1e3f;
      --error: #b3213b;
      --success: #1f6e46;
      --maroon: #6d1330;
    }

    * { box-sizing: border-box; }

    body {
      font-family: "Montserrat", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background:
        radial-gradient(circle at 15% 10%, rgba(207,165,60,0.10), transparent 40%),
        radial-gradient(circle at 85% 90%, rgba(109,19,48,0.18), transparent 45%),
        linear-gradient(160deg, #150c26 0%, #1c1330 45%, #24173f 100%);
      background-attachment: fixed;
      color: var(--text);
      margin: 0;
      padding: 32px;
      line-height: 1.5;
    }
    .container {
      max-width: 1200px;
      margin: 0 auto;
    }

    h1, h2, h3, h4, .section-title, .role-btn, .btn, .btn-submit, .btn-add, .btn-logout {
      font-family: "Playfair Display", Georgia, serif;
    }

    /* Auth Screens Side-by-Side Layout with Smooth Animation */
    .login-wrapper {
      max-width: 880px;
      margin: 70px auto;
      background:
        linear-gradient(var(--card-bg), var(--card-bg)) padding-box,
        linear-gradient(135deg, var(--gold-light), var(--gold-deep) 45%, var(--gold-light)) border-box;
      padding: 44px;
      border-radius: 14px;
      box-shadow: 0 20px 60px -15px rgba(0,0,0,0.6), 0 0 0 1px rgba(207,165,60,0.15);
      border: 2px solid transparent;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
    }

    .login-wrapper::before {
      content: "✦";
      position: absolute;
      top: -18px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--gold);
      color: var(--primary);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      box-shadow: 0 4px 12px rgba(0,0,0,0.35);
    }

    .login-body {
      display: flex;
      gap: 40px;
      align-items: center;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Judge Mode: Logo Left, Form Right */
    .login-body.judge-mode {
      flex-direction: row;
    }

    /* Admin/Server Mode: Form Left, Logo Right with Slide Animation Motion */
    .login-body.admin-mode {
      flex-direction: row-reverse;
    }

    .login-logo-container, .login-form-container {
      flex: 1;
      transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
      transform: translateX(0);
      opacity: 1;
    }

    /* Motion effect during role switch transition */
    .login-wrapper.animating .login-logo-container,
    .login-wrapper.animating .login-form-container {
      opacity: 0.2;
      transform: translateY(8px);
    }

    .login-logo-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 28px 20px;
      background: linear-gradient(160deg, var(--ivory), #f3e6c4 120%);
      border-radius: 10px;
      border: 1px solid var(--border);
    }

    .login-logo-container img {
      max-width: 160px;
      max-height: 160px;
      width: auto;
      height: auto;
      object-fit: contain;
      margin-bottom: 16px;
      display: block;
      transition: transform 0.4s ease;
      filter: drop-shadow(0 6px 14px rgba(169,129,42,0.35));
    }

    .login-logo-container:hover img {
      transform: scale(1.04);
    }

    .login-wrapper h2 {
      margin-top: 0;
      color: var(--maroon);
      text-align: left;
      font-size: 1.6rem;
      font-weight: 700;
      border-bottom: 2px solid var(--gold);
      padding-bottom: 10px;
      margin-bottom: 22px;
      transition: color 0.3s ease;
      letter-spacing: 0.02em;
    }

    .role-toggle {
      display: flex;
      gap: 4px;
      margin-bottom: 28px;
      background: var(--primary);
      padding: 5px;
      border-radius: 999px;
      box-shadow: inset 0 0 0 1px rgba(207,165,60,0.4);
    }
    .role-btn {
      flex: 1;
      padding: 10px 16px;
      border: none;
      background: transparent;
      border-radius: 999px;
      cursor: pointer;
      font-weight: 600;
      font-size: 0.88rem;
      color: var(--gold-light);
      letter-spacing: 0.04em;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .role-btn.active {
      background: linear-gradient(135deg, var(--gold-light), var(--gold-deep));
      color: var(--primary);
      font-weight: 700;
      box-shadow: 0 3px 10px rgba(0,0,0,0.3);
      transform: scale(1.02);
    }
    .form-group {
      margin-bottom: 16px;
    }
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.09em;
      color: var(--maroon);
      font-family: "Montserrat", sans-serif;
    }
    .form-group input, .form-group select {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid var(--border);
      border-radius: 6px;
      box-sizing: border-box;
      font-size: 0.95rem;
      background-color: #fffef9;
      transition: all 0.2s ease;
      font-family: "Montserrat", sans-serif;
    }
    .form-group input:focus, .form-group select:focus {
      outline: none;
      border-color: var(--gold-deep);
      box-shadow: 0 0 0 3px rgba(207, 165, 60, 0.22);
    }
    .btn-submit {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, var(--primary-header), var(--primary));
      color: var(--gold-light);
      border: 1px solid var(--gold-deep);
      border-radius: 6px;
      font-weight: 700;
      font-size: 0.98rem;
      letter-spacing: 0.04em;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-submit:hover {
      background: linear-gradient(135deg, var(--primary), #150c26);
      color: var(--gold);
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.3);
    }
    .btn-submit:active {
      transform: translateY(0);
    }
    .btn-submit.admin-btn {
      background: linear-gradient(135deg, var(--maroon), #4a0d21);
      border-color: var(--gold-deep);
      color: var(--gold-light);
    }
    .btn-submit.admin-btn:hover {
      background: linear-gradient(135deg, #4a0d21, #38091a);
      color: var(--gold);
    }
    .error-msg {
      color: var(--error);
      font-size: 0.85rem;
      margin-top: 10px;
      display: none;
      text-align: center;
      font-weight: 600;
      font-family: "Montserrat", sans-serif;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Responsive Mobile layout for Login */
    @media (max-width: 768px) {
      .login-body.judge-mode, .login-body.admin-mode {
        flex-direction: column-reverse;
      }
      body {
        padding: 16px;
      }
    }

    /* Responsive scoring: keep score boxes large and legible on small screens */
    @media (max-width: 768px) {
      table { min-width: 640px; font-size: 0.95rem; }
      th, td { padding: 14px 14px; }
      td.left, th.left { min-width: 150px; }
      input[type="number"] {
        width: 78px;
        padding: 12px 8px;
        font-size: 1.15rem;
        border-width: 2px;
      }
      .overall-score { font-size: 1.05rem; }
      .notice-box { font-size: 0.82rem; }
    }

    @media (max-width: 480px) {
      table { min-width: 680px; }
      input[type="number"] {
        width: 86px;
        font-size: 1.25rem;
        padding: 14px 8px;
      }
    }

    /* Dashboard Layout */
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(120deg, var(--primary) 0%, var(--primary-header) 60%, var(--maroon) 130%);
      color: var(--gold-light);
      padding: 24px 28px;
      border-radius: 10px;
      margin-bottom: 28px;
      box-shadow: 0 10px 30px -10px rgba(0,0,0,0.5);
      border: 1px solid rgba(207,165,60,0.35);
    }
    header h1 {
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      color: var(--gold);
    }
    .badge-role {
      background: rgba(207, 165, 60, 0.18);
      border: 1px solid rgba(207,165,60,0.5);
      color: var(--gold-light);
      padding: 7px 14px;
      border-radius: 999px;
      font-size: 0.72rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-weight: 700;
      font-family: "Montserrat", sans-serif;
    }
    .btn-logout {
      background: transparent;
      color: var(--gold-light);
      border: 1px solid var(--gold-deep);
      padding: 9px 16px;
      border-radius: 999px;
      cursor: pointer;
      font-weight: 600;
      font-size: 0.85rem;
      letter-spacing: 0.03em;
      transition: all 0.2s;
    }
    .btn-logout:hover {
      background: var(--maroon);
      border-color: var(--maroon);
      color: #fff;
    }

    /* Table Styling */
    .section-title {
      font-size: 1.3rem;
      font-weight: 700;
      color: var(--gold-light);
      margin: 36px 0 14px 0;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 2px solid var(--gold-deep);
      padding-bottom: 8px;
      letter-spacing: 0.01em;
    }
    .table-container {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      box-shadow: 0 10px 26px -12px rgba(0,0,0,0.45);
      margin-bottom: 26px;
    }
    table {
      width: 100%;
      min-width: 640px;
      border-collapse: collapse;
      background: var(--card-bg);
      font-size: 0.9rem;
      font-family: "Montserrat", sans-serif;
    }
    th, td {
      padding: 13px 16px;
      text-align: center;
      border: 1px solid var(--border);
    }
    th {
      background: linear-gradient(135deg, var(--primary-header), var(--primary));
      color: var(--gold-light);
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-family: "Playfair Display", serif;
      white-space: nowrap;
    }
    td.left, th.left {
      text-align: left;
      min-width: 160px;
      white-space: nowrap;
    }

    input[type="number"] {
      width: 68px;
      padding: 9px 6px;
      border: 1.5px solid var(--border);
      border-radius: 6px;
      font-size: 1.05rem;
      font-weight: 700;
      text-align: center;
      color: var(--maroon);
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: "Montserrat", sans-serif;
    }
    input[type="number"]:focus {
      outline: none;
      border-color: var(--gold-deep);
      box-shadow: 0 0 0 2px rgba(207, 165, 60, 0.25);
    }

    .overall-score {
      font-weight: 700;
      color: var(--maroon);
      font-family: "Playfair Display", serif;
    }

    .rank-cell {
      font-weight: 700;
      color: var(--gold-deep);
      font-family: "Playfair Display", serif;
      font-size: 1.05rem;
    }

    .top-rank {
      background: linear-gradient(90deg, #fff8e0, #fdf1c7) !important;
    }

    .notice-box {
      background: rgba(207, 165, 60, 0.12);
      border: 1px solid var(--gold-deep);
      color: var(--gold-light);
      padding: 16px 18px;
      border-radius: 10px;
      font-size: 0.875rem;
      margin-top: 26px;
      font-family: "Montserrat", sans-serif;
    }

    .controls { display: flex; gap: 12px; margin-bottom: 22px; }
    .btn {
      padding: 11px 18px;
      background: linear-gradient(135deg, var(--gold-light), var(--gold-deep));
      color: var(--primary);
      border: none;
      border-radius: 999px;
      cursor: pointer;
      font-weight: 700;
      font-size: 0.9rem;
      letter-spacing: 0.02em;
      transition: all 0.2s;
      box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,0.32); }
    .btn-danger { background: linear-gradient(135deg, #c23a55, var(--maroon)); color: #fff; }
    .btn-danger:hover { background: linear-gradient(135deg, var(--maroon), #4a0d21); }

    /* Admin Grid */
    .admin-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 26px;
    }
    .admin-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      padding: 26px;
      border-radius: 10px;
      box-shadow: 0 10px 26px -12px rgba(0,0,0,0.4);
    }
    .admin-card h3 {
      margin-top: 0;
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--maroon);
      border-bottom: 1px solid var(--gold-deep);
      padding-bottom: 9px;
      margin-bottom: 16px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .inline-form {
      display: flex;
      gap: 12px;
      align-items: flex-end;
      flex-wrap: wrap;
    }
    .inline-form .form-group {
      margin-bottom: 0;
      flex: 1;
      min-width: 100px;
    }
    .btn-add {
      background: linear-gradient(135deg, var(--success), #164a2f);
      color: white;
      border: none;
      padding: 11px 18px;
      border-radius: 6px;
      font-weight: 700;
      font-size: 0.88rem;
      cursor: pointer;
      height: 42px;
      transition: all 0.2s;
    }
    .btn-add:hover {
      background: linear-gradient(135deg, #164a2f, #0f3320);
      transform: translateY(-1px);
    }

    /* Category / Candidate list with remove buttons */
    .tag-list {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 14px;
    }
    .tag-chip {
      display: flex;
      align-items: center;
      gap: 6px;
      background: var(--ivory);
      border: 1px solid var(--gold-deep);
      border-radius: 999px;
      padding: 5px 8px 5px 12px;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--maroon);
      font-family: "Montserrat", sans-serif;
    }
    .tag-chip button {
      border: none;
      background: #f3c8ce;
      color: var(--maroon);
      width: 18px;
      height: 18px;
      border-radius: 50%;
      cursor: pointer;
      font-size: 0.75rem;
      line-height: 1;
      font-weight: 700;
      padding: 0;
    }
    .tag-chip button:hover {
      background: #eda9b2;
    }

    /* Live Ranking */
    .live-rank-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
      margin-bottom: 8px;
    }
    .live-rank-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 22px;
      box-shadow: 0 10px 26px -12px rgba(0,0,0,0.4);
    }
    .live-rank-card h4 {
      margin: 0 0 14px 0;
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--maroon);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      border-bottom: 1px solid var(--gold-deep);
      padding-bottom: 9px;
    }
    .live-rank-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .live-rank-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 12px;
      border-radius: 6px;
      background: var(--ivory);
      font-size: 0.88rem;
      font-family: "Montserrat", sans-serif;
    }
    .live-rank-row.rank-1 {
      background: linear-gradient(90deg, #fff3cf, #ffe9ac);
      border: 1px solid var(--gold-deep);
      font-weight: 700;
      box-shadow: 0 3px 10px rgba(169,129,42,0.25);
    }
    .live-rank-number {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 26px;
      height: 26px;
      flex-shrink: 0;
      font-weight: 700;
      color: #fff;
      background: linear-gradient(135deg, var(--maroon), #4a0d21);
      border-radius: 50%;
      font-size: 0.75rem;
      font-family: "Playfair Display", serif;
    }
    .live-rank-row.rank-1 .live-rank-number {
      background: linear-gradient(135deg, var(--gold-light), var(--gold-deep));
      color: var(--primary);
    }
    .live-rank-position {
      min-width: 48px;
      text-align: right;
      font-weight: 700;
      color: var(--gold-deep);
      white-space: nowrap;
    }
    .live-rank-row.rank-1 .live-rank-position {
      color: var(--maroon);
    }
    .live-rank-name {
      flex: 1;
      color: var(--text);
      font-weight: 600;
    }
    .live-rank-score {
      font-weight: 700;
      color: var(--maroon);
      white-space: nowrap;
      font-family: "Playfair Display", serif;
    }
  </style>
</head>
<body onload="checkSession()">

  <!-- LOGIN CONTAINER -->
  <div id="loginView" class="login-wrapper">
    <div class="role-toggle">
      <button id="btnRoleJudge" class="role-btn active" onclick="setRole('judge')">Judge Portal</button>
      <button id="btnRoleAdmin" class="role-btn" onclick="setRole('admin')">Server Management</button>
    </div>

    <div id="loginBody" class="login-body judge-mode">
      <div class="login-logo-container">
        <img 
          id="pageantLogo"
          src="smit-removebg-preview.png" 
          alt="Mr. & Mrs. Smitian Logo" 
          onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'120\' height=\'120\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%231e293b\' stroke-width=\'1.5\'><circle cx=\'12\' cy=\'8\' r=\'5\'/><path d=\'M3 21v-2a7 7 0 0 1 14 0v2\'/></svg>';"
        />
        <h3 style="margin: 8px 0 0 0; color: var(--maroon); font-family: 'Playfair Display', serif; font-size: 1.3rem; font-weight: 700; letter-spacing: 0.03em;">👑 Mr. &amp; Mrs. Smitian</h3>
        <small style="color: var(--gold-deep); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.14em; font-weight: 600;">Official Tabulation System</small>
      </div>

      <div class="login-form-container">
        <form id="judgeForm" onsubmit="handleJudgeLogin(event)">
          <h2>Judge Authentication</h2>
          <div class="form-group">
            <label for="judgeUsername">Username</label>
            <input type="text" id="judgeUsername" placeholder="Enter credentials" required />
          </div>
          <div class="form-group">
            <label for="judgePassword">Password</label>
            <input type="password" id="judgePassword" placeholder="••••••••" required />
          </div>
          <button type="submit" class="btn-submit">Access Scoring Sheet</button>
          <div id="judgeError" class="error-msg">Invalid credentials provided. Please verify.</div>
        </form>

        <form id="adminForm" onsubmit="handleAdminLogin(event)" style="display: none;">
          <h2>Administrative Access</h2>
          <div class="form-group">
            <label for="adminUsername">Administrator ID</label>
            <input type="text" id="adminUsername" value="admin" required />
          </div>
          <div class="form-group">
            <label for="adminPassword">Master Password</label>
            <input type="password" id="adminPassword" placeholder="••••••••" required />
          </div>
          <button type="submit" class="btn-submit admin-btn">Unlock Master Tabulation</button>
          <div id="adminError" class="error-msg">Invalid master password provided.</div>
        </form>
      </div>
    </div>
  </div>

  <!-- JUDGE SCORING VIEW -->
  <div id="judgeView" class="container" style="display: none;">
    <header>
      <h1>Official Scoring Portal</h1>
      <div style="display: flex; align-items: center; gap: 16px;">
        <span id="judgeGreeting" class="badge-role"></span>
        <button class="btn-logout" onclick="logout()">Sign Out</button>
      </div>
    </header>

    <div class="section-title">Candidate Pair Evaluation</div>
    <div id="judgePairsContainer"></div>

    <div class="notice-box">
      <strong>Confidentiality Notice:</strong> You are currently viewing your independent evaluation criteria. Overall aggregate tallies, comparative averages, and final rankings remain restricted to the administrative panel. Use the <strong>Tab</strong> key or arrow keys for smooth navigation.
    </div>
  </div>

  <!-- ADMIN TABULATION VIEW -->
  <div id="adminView" class="container" style="display: none;">
    <header>
      <div>
        <h1 style="margin: 0;">Master Tabulation Dashboard</h1>
        <small style="color: var(--gold-light); font-size: 0.8rem; letter-spacing: 0.05em; text-transform: uppercase; opacity: 0.85;">Comprehensive performance tracking and analytical computations</small>
      </div>
      <div style="display: flex; align-items: center; gap: 16px;">
        <span class="badge-role" style="background: rgba(109,19,48,0.5); border-color: var(--gold);">Administrator</span>
        <button class="btn-logout" onclick="logout()">Sign Out</button>
      </div>
    </header>

    <div class="section-title">🏆 Live Ranking</div>
    <div class="live-rank-grid">
      <div class="live-rank-card">
        <h4>Male Division</h4>
        <div id="liveRankMale" class="live-rank-list"></div>
      </div>
      <div class="live-rank-card">
        <h4>Female Division</h4>
        <div id="liveRankFemale" class="live-rank-list"></div>
      </div>
    </div>

    <div class="admin-grid">
      <div class="admin-card">
        <h3>Category Management</h3>
        <form class="inline-form" onsubmit="handleAddCategory(event)">
          <div class="form-group">
            <label for="newCatName">Category Title</label>
            <input type="text" id="newCatName" placeholder="e.g. Formal Wear" required />
          </div>
          <button type="submit" class="btn-add">Add</button>
        </form>
        <div id="catMsg" style="margin-top: 8px; font-size: 0.85rem; font-weight: 600;"></div>
        <div id="categoryList" class="tag-list"></div>
      </div>

      <div class="admin-card">
        <h3>Candidate Registration</h3>
        <form class="inline-form" onsubmit="handleAddCandidate(event)">
          <div class="form-group" style="flex: 0 0 60px;">
            <label for="newCandidateId">No.</label>
            <input type="number" id="newCandidateId" min="1" placeholder="1" required />
          </div>
          <div class="form-group">
            <label for="newCandidateGender">Division</label>
            <select id="newCandidateGender">
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
          </div>
          <div class="form-group">
            <label for="newCandidateName">Full Name / Label</label>
            <input type="text" id="newCandidateName" placeholder="Candidate Name" required />
          </div>
          <button type="submit" class="btn-add">Register</button>
        </form>
        <div id="candidateMsg" style="margin-top: 8px; font-size: 0.85rem; font-weight: 600;"></div>
        <div id="candidateList" class="tag-list"></div>
      </div>

      <div class="admin-card">
        <h3>Judge Account Registry</h3>
        <form class="inline-form" onsubmit="handleAddJudge(event)">
          <div class="form-group">
            <label for="newJudgeName">Full Name</label>
            <input type="text" id="newJudgeName" placeholder="Judge Name" required />
          </div>
          <div class="form-group">
            <label for="newJudgeUsername">Username</label>
            <input type="text" id="newJudgeUsername" placeholder="user" required />
          </div>
          <div class="form-group">
            <label for="newJudgePassword">Password</label>
            <input type="password" id="newJudgePassword" placeholder="••••" required />
          </div>
          <button type="submit" class="btn-add">Create</button>
        </form>
        <div id="judgeAddMsg" style="margin-top: 8px; font-size: 0.85rem; font-weight: 600;"></div>
      </div>
    </div>

    <div class="controls">
      <button class="btn" onclick="syncAndRenderAdmin()">Synchronize Records</button>
      <button class="btn btn-danger" onclick="resetAllScores()">Purge All Scores</button>
    </div>

    <div class="section-title">Male Division Master Tabulation</div>
    <div class="table-container">
      <table>
        <thead>
          <tr id="adminMaleHeader"></tr>
        </thead>
        <tbody id="adminMaleTableBody"></tbody>
      </table>
    </div>

    <div class="section-title">Female Division Master Tabulation</div>
    <div class="table-container">
      <table>
        <thead>
          <tr id="adminFemaleHeader"></tr>
        </thead>
        <tbody id="adminFemaleTableBody"></tbody>
      </table>
    </div>
  </div>

  <script>
    const DEFAULT_JUDGES = [
      { username: 'judge1', password: 'pass123', name: 'Judge 1 - Alice' },
      { username: 'judge2', password: 'pass123', name: 'Judge 2 - Bob' },
      { username: 'judge3', password: 'pass123', name: 'Judge 3 - Charlie' }
    ];

    const DEFAULT_CANDIDATES = [
      { id: 1, gender: 'Male', name: 'Candidate 1' },
      { id: 2, gender: 'Male', name: 'Candidate 2' },
      { id: 3, gender: 'Male', name: 'Candidate 3' },
      { id: 4, gender: 'Male', name: 'Candidate 4' },
      { id: 5, gender: 'Male', name: 'Candidate 5' },
      { id: 1, gender: 'Female', name: 'Candidate 1' },
      { id: 2, gender: 'Female', name: 'Candidate 2' },
      { id: 3, gender: 'Female', name: 'Candidate 3' },
      { id: 4, gender: 'Female', name: 'Candidate 4' }
    ];

    const DEFAULT_CATEGORIES = [
      { id: 'uniform', name: 'Uniform' },
      { id: 'casual', name: 'Casual' },
      { id: 'swimsuit', name: 'Swimsuit' },
      { id: 'production', name: 'Production' },
      { id: 'formal', name: 'Formal' }
    ];

    const ADMIN_CREDENTIALS = { username: 'admin', password: 'adminpassword123' };

    let currentSession = null;
    let liveRankInterval = null;

    function getStoredJudges() {
      const data = localStorage.getItem('pageant_judges');
      return data ? JSON.parse(data) : DEFAULT_JUDGES;
    }
    function saveJudges(judges) {
      localStorage.setItem('pageant_judges', JSON.stringify(judges));
    }

    function getStoredCandidates() {
      const data = localStorage.getItem('pageant_candidates');
      return data ? JSON.parse(data) : DEFAULT_CANDIDATES;
    }
    function saveCandidates(candidates) {
      localStorage.setItem('pageant_candidates', JSON.stringify(candidates));
    }

    function getStoredCategories() {
      const data = localStorage.getItem('pageant_categories');
      return data ? JSON.parse(data) : DEFAULT_CATEGORIES;
    }
    function saveCategories(categories) {
      localStorage.setItem('pageant_categories', JSON.stringify(categories));
    }

    // Scores now live on the SERVER (shared JSON file via the PHP endpoints
    // above), not in this browser's localStorage. scoresCache mirrors the
    // server's data and is kept in sync via syncScoresFromServer().
    let scoresCache = {};

    function getStoredScores() {
      return scoresCache;
    }

    async function syncScoresFromServer() {
      try {
        const res = await fetch('?action=get_scores');
        if (res.ok) {
          scoresCache = await res.json();
        }
      } catch (err) {
        console.error('Failed to sync scores from server:', err);
      }
    }

    // Bulk save: used for admin operations that rewrite many keys at once
    // (e.g. deleting a category/candidate wipes several score entries).
    function saveScores(scores) {
      scoresCache = scores;
      fetch('?action=save_all_scores', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scores })
      }).catch(err => console.error('Failed to save scores to server:', err));
    }

    // Single-key save: used when a judge types one score, so we only push
    // the one changed value to the server instead of the whole object.
    function pushSingleScoreToServer(key, value) {
      fetch('?action=save_score', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key, value: value === undefined ? null : value })
      }).catch(err => console.error('Failed to save score to server:', err));
    }

    async function resetScoresOnServer() {
      try {
        await fetch('?action=reset_scores', { method: 'POST' });
      } catch (err) {
        console.error('Failed to reset scores on server:', err);
      }
    }


    function setRole(role) {
      const loginWrapper = document.getElementById('loginView');
      const loginBody = document.getElementById('loginBody');
      
      loginWrapper.classList.add('animating');

      setTimeout(() => {
        document.getElementById('btnRoleJudge').classList.toggle('active', role === 'judge');
        document.getElementById('btnRoleAdmin').classList.toggle('active', role === 'admin');
        
        document.getElementById('judgeForm').style.display = role === 'judge' ? 'block' : 'none';
        document.getElementById('adminForm').style.display = role === 'admin' ? 'block' : 'none';
        
        document.getElementById('judgeError').style.display = 'none';
        document.getElementById('adminError').style.display = 'none';

        if (role === 'judge') {
          loginBody.classList.remove('admin-mode');
          loginBody.classList.add('judge-mode');
        } else {
          loginBody.classList.remove('judge-mode');
          loginBody.classList.add('admin-mode');
        }

        loginWrapper.classList.remove('animating');
      }, 200);
    }

    function handleAddCategory(e) {
      e.preventDefault();
      const catName = document.getElementById('newCatName').value.trim();
      const msg = document.getElementById('catMsg');

      const categories = getStoredCategories();
      const catId = catName.toLowerCase().replace(/[^a-z0-9]/g, '_');

      if (categories.some(c => c.id === catId)) {
        msg.style.color = '#dc2626';
        msg.innerText = 'Category already exists.';
        return;
      }

      categories.push({ id: catId, name: catName });
      saveCategories(categories);

      msg.style.color = '#15803d';
      msg.innerText = `Successfully added "${catName}".`;

      document.getElementById('newCatName').value = '';
      renderAdminTables();
      renderCategoryList();
    }

    function handleAddCandidate(e) {
      e.preventDefault();
      const idVal = parseInt(document.getElementById('newCandidateId').value);
      const genderVal = document.getElementById('newCandidateGender').value;
      const nameVal = document.getElementById('newCandidateName').value.trim();
      const msg = document.getElementById('candidateMsg');

      const candidates = getStoredCandidates();

      if (candidates.some(c => c.id === idVal && c.gender === genderVal)) {
        msg.style.color = '#dc2626';
        msg.innerText = `${genderVal} candidate #${idVal} already registered.`;
        return;
      }

      candidates.push({ id: idVal, gender: genderVal, name: nameVal });
      saveCandidates(candidates);

      msg.style.color = '#15803d';
      msg.innerText = `Successfully registered ${genderVal} candidate.`;

      document.getElementById('newCandidateId').value = '';
      document.getElementById('newCandidateName').value = '';

      renderAdminTables();
      renderCandidateList();
    }

    function renderCandidateList() {
      const container = document.getElementById('candidateList');
      if (!container) return;
      const candidates = getStoredCandidates();

      container.innerHTML = candidates.map(c => `
        <span class="tag-chip">
          ${c.gender[0]}${c.id} — ${c.name}
          <button title="Remove candidate" onclick="handleRemoveCandidate(${c.id}, '${c.gender}', '${c.name.replace(/'/g, "\\'")}')">&times;</button>
        </span>
      `).join('');
    }

    function renderCategoryList() {
      const container = document.getElementById('categoryList');
      if (!container) return;
      const categories = getStoredCategories();

      container.innerHTML = categories.map(cat => `
        <span class="tag-chip">
          ${cat.name}
          <button title="Remove category" onclick="handleRemoveCategory('${cat.id}', '${cat.name.replace(/'/g, "\\'")}')">&times;</button>
        </span>
      `).join('');
    }

    function handleRemoveCategory(catId, catName) {
      if (!confirm(`Remove category "${catName}"? This will also permanently delete all scores recorded under this category.`)) {
        return;
      }

      let categories = getStoredCategories();
      categories = categories.filter(c => c.id !== catId);
      saveCategories(categories);

      const scores = getStoredScores();
      const infix = `_${catId}_`;
      Object.keys(scores).forEach(key => {
        if (key.includes(infix)) {
          delete scores[key];
        }
      });
      saveScores(scores);

      renderCategoryList();
      renderAdminTables();

      const msg = document.getElementById('catMsg');
      if (msg) {
        msg.style.color = '#15803d';
        msg.innerText = `Removed category "${catName}".`;
      }
    }

    function handleRemoveCandidate(candidateId, gender, candidateName) {
      if (!confirm(`Remove candidate "${candidateName}" (${gender})? This will also permanently delete all scores recorded for this candidate.`)) {
        return;
      }

      let candidates = getStoredCandidates();
      candidates = candidates.filter(c => !(c.id === candidateId && c.gender === gender));
      saveCandidates(candidates);

      const scores = getStoredScores();
      const suffix = `_${gender}_${candidateId}`;
      Object.keys(scores).forEach(key => {
        if (key.endsWith(suffix)) {
          delete scores[key];
        }
      });
      saveScores(scores);

      renderCandidateList();
      renderAdminTables();
    }

    function handleAddJudge(e) {
      e.preventDefault();
      const name = document.getElementById('newJudgeName').value.trim();
      const username = document.getElementById('newJudgeUsername').value.trim();
      const password = document.getElementById('newJudgePassword').value.trim();
      const msg = document.getElementById('judgeAddMsg');

      const judges = getStoredJudges();

      if (judges.some(j => j.username.toLowerCase() === username.toLowerCase())) {
        msg.style.color = '#dc2626';
        msg.innerText = 'Username is already assigned.';
        return;
      }

      judges.push({ username, password, name });
      saveJudges(judges);

      msg.style.color = '#15803d';
      msg.innerText = `Judge account "${username}" created.`;

      document.getElementById('newJudgeName').value = '';
      document.getElementById('newJudgeUsername').value = '';
      document.getElementById('newJudgePassword').value = '';

      renderAdminTables();
    }

    function handleJudgeLogin(e) {
      e.preventDefault();
      const u = document.getElementById('judgeUsername').value.trim();
      const p = document.getElementById('judgePassword').value.trim();

      const judge = getStoredJudges().find(acc => acc.username === u && acc.password === p);

      if (judge) {
        currentSession = { role: 'judge', user: judge };
        sessionStorage.setItem('active_session', JSON.stringify(currentSession));
        document.getElementById('judgeError').style.display = 'none';
        showView();
      } else {
        document.getElementById('judgeError').style.display = 'block';
      }
    }

    function handleAdminLogin(e) {
      e.preventDefault();
      const u = document.getElementById('adminUsername').value.trim();
      const p = document.getElementById('adminPassword').value.trim();

      if (u === ADMIN_CREDENTIALS.username && p === ADMIN_CREDENTIALS.password) {
        currentSession = { role: 'admin', user: { name: 'Administrator' } };
        sessionStorage.setItem('active_session', JSON.stringify(currentSession));
        document.getElementById('adminError').style.display = 'none';
        showView();
      } else {
        document.getElementById('adminError').style.display = 'block';
      }
    }

    function logout() {
      sessionStorage.removeItem('active_session');
      currentSession = null;
      if (liveRankInterval) {
        clearInterval(liveRankInterval);
        liveRankInterval = null;
      }
      document.getElementById('judgeView').style.display = 'none';
      document.getElementById('adminView').style.display = 'none';
      document.getElementById('loginView').style.display = 'block';
      document.getElementById('judgePassword').value = '';
      document.getElementById('adminPassword').value = '';
    }

    function checkSession() {
      const saved = sessionStorage.getItem('active_session');
      if (saved) {
        currentSession = JSON.parse(saved);
        showView();
      }
    }

    async function showView() {
      document.getElementById('loginView').style.display = 'none';

      // Always pull the latest server-wide scores before rendering, so
      // totals reflect every judge's input, not just this browser's own.
      await syncScoresFromServer();

      if (currentSession.role === 'judge') {
        document.getElementById('adminView').style.display = 'none';
        document.getElementById('judgeView').style.display = 'block';
        document.getElementById('judgeGreeting').innerText = currentSession.user.name;
        renderJudgeGrids();
      } else if (currentSession.role === 'admin') {
        document.getElementById('judgeView').style.display = 'none';
        document.getElementById('adminView').style.display = 'block';
        renderAdminTables();
        renderCandidateList();
        renderCategoryList();

        if (liveRankInterval) clearInterval(liveRankInterval);
        liveRankInterval = setInterval(pollLiveRanking, 4000);
      }
    }

    // Re-fetches the shared server scores, then re-renders the live ranking,
    // so the admin's leaderboard stays in sync with every judge in real time.
    async function pollLiveRanking() {
      await syncScoresFromServer();
      renderLiveRanking();
    }

    async function syncAndRenderAdmin() {
      await syncScoresFromServer();
      renderAdminTables();
    }

    // --- Dynamic Renderers & Smooth Tab Navigation Script ---

    function renderJudgeGrids() {
      renderPairedJudgeTables('judgePairsContainer');
    }

    function renderPairedJudgeTables(containerId) {
      const categories = getStoredCategories();
      const allCandidates = getStoredCandidates();
      const scores = getStoredScores();
      const judgeUsername = currentSession.user.username;
      const container = document.getElementById(containerId);
      if (!container) return;

      // Determine candidate numbers in order
      const idsSet = new Set(allCandidates.map(c => c.id));
      const ids = Array.from(idsSet).sort((a, b) =>
        String(a).localeCompare(String(b), undefined, { numeric: true })
      );

      let headerCellsHTML = `<th class="left">No. & Candidate Name</th>`;
      categories.forEach(cat => {
        headerCellsHTML += `<th>${cat.name}</th>`;
      });

      let containerHTML = '';

      ids.forEach(id => {
        const female = allCandidates.find(c => c.id === id && c.gender === 'Female');
        const male = allCandidates.find(c => c.id === id && c.gender === 'Male');
        const pairRows = [female, male].filter(Boolean);
        const tbodyId = `judgePairBody_${id}`;

        containerHTML += `<div class="section-title" style="font-size: 1.1rem; margin-top: 24px;">Candidate #${id}</div>`;
        containerHTML += `<div class="table-container"><table>`;
        containerHTML += `<thead><tr>${headerCellsHTML}</tr></thead>`;
        containerHTML += `<tbody id="${tbodyId}">`;

        pairRows.forEach((cand, rowIndex) => {
          const genderTag = cand.gender === 'Female' ? 'F' : 'M';
          containerHTML += `<tr>`;
          containerHTML += `<td class="left"><strong>#${cand.id}${genderTag}</strong> — ${cand.name}</td>`;

          categories.forEach((cat, catIndex) => {
            const scoreKey = `${judgeUsername}_${cat.id}_${cand.gender}_${cand.id}`;
            const currentVal = scores[scoreKey] !== undefined ? scores[scoreKey] : '';

            containerHTML += `<td>
              <input type="number" min="0" max="25" step="any" maxlength="2" 
                value="${currentVal}" 
                data-score-key="${scoreKey}"
                oninput="handleScoreInput(this, '${cand.gender}')"
                onfocus="this.select()"
                onkeydown="handleKeyNavigation(event, ${rowIndex}, ${catIndex}, '${tbodyId}')"
              />
            </td>`;
          });

          containerHTML += `</tr>`;
        });

        containerHTML += `</tbody></table></div>`;
      });

      container.innerHTML = containerHTML;
    }

    function handleScoreInput(inputElement, gender) {
      const key = inputElement.getAttribute('data-score-key');
      const scores = getStoredScores();
      let val = inputElement.value;

      if (val === '') {
        delete scores[key];
        pushSingleScoreToServer(key, null);
      } else {
        let num = parseFloat(val);
        if (isNaN(num)) {
          num = 0;
        }
        if (num > 25) {
          num = 25;
          inputElement.value = 25;
        }
        if (num < 0) {
          num = 0;
          inputElement.value = 0;
        }
        scores[key] = num;
        pushSingleScoreToServer(key, num);
      }
    }

    // Arrow Key & Tab Navigation Handler for smooth data entry within each
    // candidate pair's own small table (Up/Down moves between the Female
    // and Male row of that pair; Left/Right moves between category columns)
    function handleKeyNavigation(e, rowIndex, catIndex, tbodyId) {
      const categories = getStoredCategories();
      const tbody = document.getElementById(tbodyId);
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr');

      let targetRowIndex = rowIndex;
      let targetCatIndex = catIndex;

      if (e.key === 'ArrowDown') {
        targetRowIndex = Math.min(rowIndex + 1, rows.length - 1);
        e.preventDefault();
      } else if (e.key === 'ArrowUp') {
        targetRowIndex = Math.max(rowIndex - 1, 0);
        e.preventDefault();
      } else if (e.key === 'ArrowRight') {
        targetCatIndex = Math.min(catIndex + 1, categories.length - 1);
        e.preventDefault();
      } else if (e.key === 'ArrowLeft') {
        targetCatIndex = Math.max(catIndex - 1, 0);
        e.preventDefault();
      }

      if (targetRowIndex !== rowIndex || targetCatIndex !== catIndex) {
        if (rows[targetRowIndex]) {
          const inputs = rows[targetRowIndex].querySelectorAll('input[type="number"]');
          if (inputs[targetCatIndex]) {
            inputs[targetCatIndex].focus();
            inputs[targetCatIndex].select();
          }
        }
      }
    }

    function renderAdminTables() {
      renderAdminDivisionTable('Male', 'adminMaleHeader', 'adminMaleTableBody');
      renderAdminDivisionTable('Female', 'adminFemaleHeader', 'adminFemaleTableBody');
      renderLiveRanking();
    }

    // Assigns ranks with tie handling. Candidates with an equal total score
    // share the SAME whole-number rank — the rank itself is never divided
    // (e.g. two candidates tied for 1st & 2nd both get rank "1"), and the
    // next distinct score keeps counting from the next open position (e.g.
    // rank "3"), rather than resuming at "2". Candidates with no score (0)
    // get '-'. (Score-splitting on ties happens separately, per category,
    // not here.)
    function computeRanksWithTies(sortedItems, valueKey) {
      const ranks = new Array(sortedItems.length);
      let i = 0;
      while (i < sortedItems.length) {
        let j = i;
        while (j + 1 < sortedItems.length && sortedItems[j + 1][valueKey] === sortedItems[i][valueKey]) {
          j++;
        }
        if (sortedItems[i][valueKey] > 0) {
          const tiedRank = i + 1; // shared whole-number rank, never divided
          for (let k = i; k <= j; k++) ranks[k] = tiedRank;
        } else {
          for (let k = i; k <= j; k++) ranks[k] = '-';
        }
        i = j + 1;
      }
      return ranks;
    }

    function formatRank(rank) {
      if (rank === '-') return '-';
      return (rank % 1 === 0) ? String(rank) : rank.toFixed(1);
    }

    function renderAdminDivisionTable(gender, headerId, bodyId) {
      const categories = getStoredCategories();
      const candidates = getStoredCandidates().filter(c => c.gender === gender);
      const judges = getStoredJudges();
      const scores = getStoredScores();

      let headerHTML = `<th class="left">Candidate</th>`;
      categories.forEach(cat => {
        headerHTML += `<th>${cat.name}</th>`;
      });
      headerHTML += `<th>Overall Aggregate</th><th>Rank</th>`;
      document.getElementById(headerId).innerHTML = headerHTML;

      // Compute aggregates to evaluate rank
      const computedData = candidates.map(cand => {
        let totalAggregate = 0;
        let categoryAverages = {};

        categories.forEach(cat => {
          let catSum = 0;
          let count = 0;
          judges.forEach(j => {
            const k = `${j.username}_${cat.id}_${gender}_${cand.id}`;
            if (scores[k] !== undefined) {
              catSum += parseFloat(scores[k]) || 0;
              count++;
            }
          });
          const avg = count > 0 ? catSum / count : 0;
          categoryAverages[cat.id] = avg;
          totalAggregate += avg;
        });

        return { cand, categoryAverages, totalAggregate };
      });

      // When two or more candidates land on the exact same score within a
      // category, that score is automatically split evenly between them
      // (e.g. two candidates both scoring 25 in "Talent" each show 12.5).
      // A score with no tie is shown as-is.
      const categoryDisplayByCandId = {};
      categories.forEach(cat => {
        const groups = {};
        computedData.forEach(item => {
          const val = item.categoryAverages[cat.id];
          const key = val.toFixed(4); // group by matching score value
          if (!groups[key]) groups[key] = [];
          groups[key].push(item.cand.id);
        });
        Object.keys(groups).forEach(key => {
          const tiedIds = groups[key];
          const rawVal = parseFloat(key);
          const splitVal = tiedIds.length > 1 ? rawVal / tiedIds.length : rawVal;
          tiedIds.forEach(candId => {
            if (!categoryDisplayByCandId[candId]) categoryDisplayByCandId[candId] = {};
            categoryDisplayByCandId[candId][cat.id] = splitVal;
          });
        });
      });

      // Sort for ranking
      computedData.sort((a, b) => b.totalAggregate - a.totalAggregate);
      const ranks = computeRanksWithTies(computedData, 'totalAggregate');

      let bodyHTML = '';
      computedData.forEach((item, index) => {
        const rank = ranks[index];
        const isTop = rank === 1;

        bodyHTML += `<tr class="${isTop ? 'top-rank' : ''}">`;
        bodyHTML += `<td class="left"><strong>#${item.cand.id}</strong> — ${item.cand.name}</td>`;
        
        categories.forEach(cat => {
          const displayVal = categoryDisplayByCandId[item.cand.id][cat.id];
          bodyHTML += `<td>${displayVal.toFixed(2)}</td>`;
        });

        bodyHTML += `<td class="overall-score">${item.totalAggregate.toFixed(2)}</td>`;
        bodyHTML += `<td class="rank-cell">${formatRank(rank)}</td>`;
        bodyHTML += `</tr>`;
      });

      document.getElementById(bodyId).innerHTML = bodyHTML;
    }

    function renderLiveRanking() {
      renderLiveRankColumn('Male', 'liveRankMale');
      renderLiveRankColumn('Female', 'liveRankFemale');
    }

    function renderLiveRankColumn(gender, containerId) {
      const container = document.getElementById(containerId);
      if (!container) return;

      const categories = getStoredCategories();
      const candidates = getStoredCandidates().filter(c => c.gender === gender);
      const judges = getStoredJudges();
      const scores = getStoredScores();

      const computed = candidates.map(cand => {
        let total = 0;
        categories.forEach(cat => {
          let catSum = 0;
          let count = 0;
          judges.forEach(j => {
            const k = `${j.username}_${cat.id}_${gender}_${cand.id}`;
            if (scores[k] !== undefined) {
              catSum += parseFloat(scores[k]) || 0;
              count++;
            }
          });
          if (count > 0) total += (catSum / count);
        });
        return { cand, total };
      });

      computed.sort((a, b) => b.total - a.total);
      const ranks = computeRanksWithTies(computed, 'total');

      container.innerHTML = computed.map((item, idx) => {
        const rank = ranks[idx];
        const isLeader = rank === 1;
        return `
          <div class="live-rank-row ${isLeader ? 'rank-1' : ''}">
            <div class="live-rank-number">${formatRank(rank)}</div>
            <div class="live-rank-name">#${item.cand.id} — ${item.cand.name}</div>
            <div class="live-rank-score">${item.total.toFixed(2)} pts</div>
          </div>
        `;
      }).join('');
    }

    async function resetAllScores() {
      if (confirm('Are you sure you want to wipe out all recorded scores? This action cannot be undone.')) {
        await resetScoresOnServer();
        scoresCache = {};
        renderAdminTables();
        alert('All scores have been purged successfully.');
      }
    }
  </script>
</body>
