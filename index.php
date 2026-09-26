<?php
/* =========================================================
   Volna — Distribution Platform
   PHP-версия. Скрытый администратор.
   ========================================================= */

declare(strict_types=1);

/* ---------------------------------------------------------
   1. НАСТРОЙКИ
   --------------------------------------------------------- */

// Скрытые учётные данные администратора.
// Хранятся только на сервере, в HTML/JS не попадают.
const ADMIN_LOGIN    = 'admin';
const ADMIN_PASSWORD = 'admin123';

// Файл хранилища (создаётся автоматически)
const STORAGE_FILE = __DIR__ . '/storage/state.json';

/* ---------------------------------------------------------
   2. ХРАНИЛИЩЕ
   --------------------------------------------------------- */

function ensureStorage(): void {
    $dir = dirname(STORAGE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!file_exists(STORAGE_FILE)) {
        file_put_contents(
            STORAGE_FILE,
            json_encode(defaultState(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}

function defaultState(): array {
    return [
        'users'          => [],
        'currentUserId'  => null,
        'releases'       => [],
        'contracts'      => [],
        'terms'          => '',
        'threads'        => [],
        'activeThreadId' => null,
        'profiles'       => [],
    ];
}

function loadState(): array {
    ensureStorage();
    $raw = @file_get_contents(STORAGE_FILE);
    if ($raw === false || $raw === '') return defaultState();
    $data = json_decode($raw, true);
    if (!is_array($data)) return defaultState();
    return array_replace_recursive(defaultState(), $data);
}

function saveState(array $state): void {
    ensureStorage();
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return;
    file_put_contents(STORAGE_FILE, $json, LOCK_EX);
}

/* ---------------------------------------------------------
   3. API
   --------------------------------------------------------- */

function jsonResponse(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function publicUser(array $u): array {
    // Никогда не отправляем пароль на клиент
    return [
        'id'    => $u['id']    ?? null,
        'name'  => $u['name']  ?? '',
        'email' => $u['email'] ?? '',
        'role'  => $u['role']  ?? 'artist',
    ];
}

function ensureAdminUser(array &$state): array {
    foreach ($state['users'] as $u) {
        if (($u['role'] ?? '') === 'admin') return $u;
    }
    $admin = [
        'id'       => 'admin-root',
        'name'     => 'Администратор',
        'email'    => ADMIN_LOGIN,
        'password' => ADMIN_PASSWORD,
        'role'     => 'admin',
        'hidden'   => true,
    ];
    $state['users'][] = $admin;
    return $admin;
}

function stripAdminUsers(array $incoming, array $existing): array {
    // Клиент не может ни добавить, ни удалить админа —
    // оставляем только тех, кто уже есть на сервере.
    if (!isset($incoming['users']) || !is_array($incoming['users'])) return $incoming;

    $serverAdmins = [];
    foreach ($existing['users'] as $u) {
        if (($u['role'] ?? '') === 'admin') $serverAdmins[] = $u;
    }
    $artists = [];
    foreach ($incoming['users'] as $u) {
        if (($u['role'] ?? '') !== 'admin') $artists[] = $u;
    }
    $incoming['users'] = array_merge($serverAdmins, $artists);
    return $incoming;
}

function handleApi(): void {
    $action = $_GET['action'] ?? '';
    $state  = loadState();

    switch ($action) {

        case 'state':
            jsonResponse(['ok' => true, 'state' => $state]);
            break;

        case 'save':
            $body = readJsonBody();
            if (!isset($body['state']) || !is_array($body['state'])) {
                jsonResponse(['ok' => false, 'error' => 'Нет state'], 400);
            }
            $incoming = stripAdminUsers($body['state'], $state);
            saveState($incoming);
            jsonResponse(['ok' => true]);
            break;

        case 'login':
            $body  = readJsonBody();
            $login = strtolower(trim((string)($body['login'] ?? '')));
            $pass  = (string)($body['password'] ?? '');

            if ($login === '' || $pass === '') {
                jsonResponse(['ok' => false, 'error' => 'Заполните поля'], 400);
            }

            // Скрытый администратор
            if ($login === ADMIN_LOGIN && $pass === ADMIN_PASSWORD) {
                $admin = ensureAdminUser($state);
                $state['currentUserId'] = $admin['id'];
                saveState($state);
                jsonResponse(['ok' => true, 'user' => publicUser($admin)]);
            }

            // Обычный артист
            foreach ($state['users'] as $u) {
                if (($u['role'] ?? '') === 'admin') continue;
                if (($u['email'] ?? '') === $login && ($u['password'] ?? '') === $pass) {
                    $state['currentUserId'] = $u['id'];
                    saveState($state);
                    jsonResponse(['ok' => true, 'user' => publicUser($u)]);
                }
            }
            jsonResponse(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
            break;

        case 'register':
            $body  = readJsonBody();
            $name  = trim((string)($body['name'] ?? ''));
            $email = strtolower(trim((string)($body['email'] ?? '')));
            $pass  = (string)($body['password'] ?? '');

            if ($name === '' || $email === '' || $pass === '') {
                jsonResponse(['ok' => false, 'error' => 'Заполните все поля'], 400);
            }
            if (mb_strlen($pass) < 4) {
                jsonResponse(['ok' => false, 'error' => 'Пароль слишком короткий'], 400);
            }
            if ($email === ADMIN_LOGIN) {
                jsonResponse(['ok' => false, 'error' => 'Этот логин занят'], 400);
            }
            foreach ($state['users'] as $u) {
                if (($u['email'] ?? '') === $email) {
                    jsonResponse(['ok' => false, 'error' => 'Пользователь с таким email уже существует'], 400);
                }
            }

            $id = 'u-' . time() . '-' . substr(bin2hex(random_bytes(4)), 0, 5);
            $user = [
                'id'       => $id,
                'name'     => $name,
                'email'    => $email,
                'password' => $pass,
                'role'     => 'artist',
            ];
            $state['users'][] = $user;
            $state['profiles'][$id] = [
                'nickname' => $name,
                'bio'      => '',
                'avatar'   => null,
                'accent'   => '#7c5cff',
            ];
            $state['currentUserId'] = $id;
            saveState($state);
            jsonResponse(['ok' => true, 'user' => publicUser($user)]);
            break;

        case 'logout':
            $state['currentUserId'] = null;
            saveState($state);
            jsonResponse(['ok' => true]);
            break;

        case 'me':
            $me = null;
            foreach ($state['users'] as $u) {
                if (($u['id'] ?? '') === ($state['currentUserId'] ?? '')) {
                    $me = $u;
                    break;
                }
            }
            jsonResponse(['ok' => true, 'user' => $me ? publicUser($me) : null]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'Неизвестное действие'], 404);
    }
}

/* ---------------------------------------------------------
   4. МАРШРУТИЗАЦИЯ
   --------------------------------------------------------- */

if (isset($_GET['action'])) {
    handleApi();
}

// Начальное состояние, чтобы страница не мигала
$initialState = loadState();
if (isset($initialState['users']) && is_array($initialState['users'])) {
    foreach ($initialState['users'] as $i => $u) {
        unset($initialState['users'][$i]['password']);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Volna — Платформа дистрибуции музыки</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" />
<style>
:root {
  --bg: #0e0e12; --bg-2: #16161d; --panel: #1b1b24; --panel-2: #22222d;
  --line: #2a2a37; --ink: #f2f0ee; --ink-soft: #9a97a8; --ink-dim: #6a6878;
  --accent: #7c5cff; --accent-2: #22d3ee; --green: #3ddc97; --red: #ff5c7a; --yellow: #ffce5c;
  --shadow-sm: 0 2px 8px rgba(0,0,0,.35); --shadow-md: 0 12px 40px rgba(0,0,0,.5);
  --glow: 0 0 24px rgba(124,92,255,.45);
  --radius: 16px; --radius-sm: 10px;
  --font-display: "Space Grotesk", system-ui, sans-serif;
  --font-body: "Inter", -apple-system, "Segoe UI", Roboto, sans-serif;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body {
  font-family: var(--font-body); color: var(--ink);
  background:
    radial-gradient(900px 500px at 0% -10%, rgba(124,92,255,.18) 0%, transparent 60%),
    radial-gradient(700px 400px at 100% 0%, rgba(34,211,238,.12) 0%, transparent 55%), var(--bg);
  font-size: 15px; line-height: 1.55; -webkit-font-smoothing: antialiased; min-height: 100vh;
}
h1,h2,h3 { font-family: var(--font-display); font-weight: 700; margin: 0; letter-spacing: -.01em; }
h2 { font-size: 26px; } h3 { font-size: 18px; } p { margin: 0; }

.auth-screen { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; padding: 24px;
  background: radial-gradient(600px 400px at 50% 0%, rgba(124,92,255,.25) 0%, transparent 60%),
              radial-gradient(500px 350px at 100% 100%, rgba(34,211,238,.15) 0%, transparent 60%), var(--bg); overflow-y: auto; }
.auth-screen.is-hidden { display: none; }
.auth-card { width: 100%; max-width: 460px; background: linear-gradient(180deg, var(--panel) 0%, var(--bg-2) 100%);
  border: 1px solid var(--line); border-radius: 24px; padding: 36px 32px 30px;
  box-shadow: var(--shadow-md), inset 0 1px 0 rgba(255,255,255,.04); animation: fadeUp .4s ease both; }
.auth-logo { display: flex; align-items: center; gap: 12px; margin-bottom: 26px; }
.auth-logo__mark { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center;
  font-family: var(--font-display); font-weight: 700; font-size: 20px; color: #fff;
  background: linear-gradient(135deg, var(--accent), var(--accent-2)); box-shadow: var(--glow); }
.auth-logo__text h1 { font-size: 18px; }
.auth-logo__text span { font-size: 11px; letter-spacing: .18em; text-transform: uppercase; color: var(--ink-soft); }
.auth-tabs { display: flex; gap: 6px; padding: 5px; background: var(--bg); border: 1px solid var(--line);
  border-radius: 999px; margin-bottom: 22px; }
.auth-tab { flex: 1; border: none; background: transparent; font-family: var(--font-body);
  font-size: 13.5px; font-weight: 600; color: var(--ink-soft); padding: 9px 12px; border-radius: 999px;
  cursor: pointer; transition: all .25s ease; }
.auth-tab.is-active { background: var(--accent); color: #fff; box-shadow: var(--glow); }
.auth-form { display: none; flex-direction: column; gap: 14px; }
.auth-form.is-active { display: flex; }
.field { display: flex; flex-direction: column; gap: 7px; }
.field > span { font-size: 12.5px; font-weight: 600; color: var(--ink-soft); letter-spacing: .02em; }
input[type="text"], input[type="password"], input[type="email"], textarea, select {
  font-family: var(--font-body); font-size: 14px; color: var(--ink); background: var(--bg);
  border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 12px 14px;
  outline: none; width: 100%; transition: border-color .2s ease, box-shadow .2s ease, background .2s ease; }
textarea { resize: vertical; min-height: 80px; }
input:focus, textarea:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124,92,255,.18); background: var(--bg-2); }
.auth-submit { margin-top: 6px; font-family: var(--font-body); font-size: 14.5px; font-weight: 600;
  padding: 13px 20px; border: none; border-radius: 999px; color: #fff; cursor: pointer;
  background: linear-gradient(135deg, var(--accent), #9d7cff); box-shadow: var(--glow);
  transition: transform .18s ease, box-shadow .25s ease; }
.auth-submit:hover { transform: translateY(-1px); box-shadow: 0 0 32px rgba(124,92,255,.6); }
.auth-note { font-size: 12.5px; color: var(--ink-dim); text-align: center; margin-top: 12px; }
.auth-note a { color: var(--accent-2); cursor: pointer; text-decoration: none; }
.auth-note a:hover { text-decoration: underline; }
.auth-error { color: var(--red); font-size: 13px; text-align: center; min-height: 18px; margin-top: 6px; }

.app { display: none; grid-template-columns: 260px 1fr; min-height: 100vh; }
.app.is-active { display: grid; }
.sidebar { display: flex; flex-direction: column; gap: 22px; padding: 24px 16px; background: var(--bg-2);
  border-right: 1px solid var(--line); position: sticky; top: 0; height: 100vh; }
.sidebar.is-hidden { display: none; }
.brand { display: flex; align-items: center; gap: 12px; padding: 4px 6px 6px; }
.brand__logo { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center;
  font-family: var(--font-display); font-weight: 700; font-size: 18px; color: #fff;
  background: linear-gradient(135deg, var(--accent), var(--accent-2)); box-shadow: var(--glow); }
.brand__logo--admin { background: linear-gradient(135deg, var(--accent-2), #4f8cff); }
.brand__text h1 { font-size: 16px; line-height: 1.1; }
.brand__text span { font-size: 10px; letter-spacing: .16em; text-transform: uppercase; color: var(--ink-soft); }
.nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.nav__item { display: flex; align-items: center; gap: 11px; border: none; background: transparent;
  font-family: var(--font-body); font-size: 13.5px; font-weight: 500; color: var(--ink-soft);
  text-align: left; padding: 11px 12px; border-radius: var(--radius-sm); cursor: pointer;
  transition: background .2s, color .2s, transform .2s; }
.nav__item:hover { background: var(--panel); color: var(--ink); transform: translateX(2px); }
.nav__item.is-active { background: linear-gradient(90deg, rgba(124,92,255,.18), transparent); color: var(--ink);
  border-left: 3px solid var(--accent); }
.nav__icon { width: 18px; text-align: center; font-size: 13px; color: var(--accent); }
.sidebar__footer { border-top: 1px solid var(--line); padding-top: 14px; }
.user-chip { display: flex; align-items: center; gap: 10px; }
.user-chip__avatar { width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center;
  font-weight: 700; font-size: 13px; color: #fff;
  background: linear-gradient(135deg, var(--accent), var(--accent-2)); background-size: cover; background-position: center; }
.user-chip__avatar--admin { background: linear-gradient(135deg, var(--accent-2), #4f8cff); }
.user-chip__info { display: flex; flex-direction: column; line-height: 1.2; flex: 1; min-width: 0; }
.user-chip__info strong { font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.user-chip__info span { font-size: 11px; color: var(--ink-soft); }
.logout-btn { margin-top: 10px; width: 100%; background: transparent; border: 1px solid var(--line);
  color: var(--ink-soft); font-family: var(--font-body); font-size: 12.5px; font-weight: 600;
  padding: 8px 12px; border-radius: 999px; cursor: pointer; transition: all .2s; }
.logout-btn:hover { border-color: var(--red); color: var(--red); }
.content { padding: 36px 44px 60px; max-width: 1080px; }
.tab { display: none; animation: fadeUp .35s ease both; }
.tab.is-active { display: block; }
@keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
.page-head { margin-bottom: 22px; }
.page-head--spaced { margin-top: 32px; }
.page-head__sub { color: var(--ink-soft); font-size: 13.5px; margin-top: 4px; }
.card { background: linear-gradient(180deg, var(--panel) 0%, var(--bg-2) 100%); border: 1px solid var(--line);
  border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow-md); }
.card--profile { display: flex; flex-direction: column; gap: 22px; }
.form-grid { display: grid; gap: 16px; }
.form-grid--2 { grid-template-columns: 1fr 1fr; }
.hint { font-size: 12px; color: var(--ink-dim); }
.color-row { display: flex; align-items: center; gap: 12px; }
input[type="color"] { width: 48px; height: 38px; border: 1px solid var(--line); border-radius: var(--radius-sm);
  background: var(--bg); cursor: pointer; padding: 3px; }
.btn { font-family: var(--font-body); font-size: 14px; font-weight: 600; border-radius: 999px;
  padding: 11px 22px; border: 1px solid transparent; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  transition: transform .18s, box-shadow .22s, background .22s, color .22s; }
.btn--primary { color: #fff; background: linear-gradient(135deg, var(--accent), #9d7cff); box-shadow: var(--glow); }
.btn--primary:hover { transform: translateY(-1px); box-shadow: 0 0 28px rgba(124,92,255,.55); }
.btn--ghost { background: transparent; color: var(--ink); border-color: var(--line); }
.btn--ghost:hover { background: var(--panel-2); transform: translateY(-1px); }
.btn--small { padding: 7px 14px; font-size: 12.5px; }
.btn--green { background: var(--green); color: #06281a; }
.btn--green:hover { transform: translateY(-1px); box-shadow: 0 0 20px rgba(61,220,151,.45); }
.btn--red { background: transparent; color: var(--red); border-color: rgba(255,92,122,.5); }
.btn--red:hover { background: var(--red); color: #fff; }
.actions { display: flex; align-items: center; gap: 14px; margin-top: 18px; flex-wrap: wrap; }
.actions--end { justify-content: flex-end; margin-top: 22px; }
.save-note { font-size: 13px; color: var(--green); opacity: 0; transition: opacity .3s; }
.save-note.is-visible { opacity: 1; }
.profile-avatar-block { display: flex; align-items: center; gap: 20px; }
.avatar-preview { width: 84px; height: 84px; border-radius: 50%; display: grid; place-items: center;
  font-family: var(--font-display); font-size: 28px; color: #fff;
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  background-size: cover; background-position: center; box-shadow: var(--glow); }
.upload-box { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.upload-preview { width: 68px; height: 68px; border-radius: var(--radius-sm); display: grid; place-items: center;
  font-size: 22px; color: var(--accent); background: var(--bg); border: 1px dashed var(--line);
  background-size: cover; background-position: center; }
.audio-preview { width: 100%; max-width: 260px; height: 34px; }
.catalog, .moderation-list { display: flex; flex-direction: column; gap: 12px; }
.release-card { display: grid; grid-template-columns: 56px 1fr auto; gap: 16px; align-items: center;
  background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); padding: 14px 18px;
  box-shadow: var(--shadow-sm); transition: transform .2s, box-shadow .2s, border-color .2s; }
.release-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: rgba(124,92,255,.4); }
.release-card__cover { width: 56px; height: 56px; border-radius: var(--radius-sm); background: var(--bg);
  display: grid; place-items: center; font-size: 20px; color: var(--accent);
  background-size: cover; background-position: center; }
.release-card__title { font-weight: 600; font-size: 15px; }
.release-card__meta { font-size: 12.5px; color: var(--ink-soft); margin-top: 2px; }
.release-card__upc { font-size: 12.5px; color: var(--green); margin-top: 4px; font-weight: 600; font-family: var(--font-display); }
.release-card__side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.status { font-size: 11.5px; font-weight: 600; padding: 5px 12px; border-radius: 999px; white-space: nowrap; border: 1px solid transparent; }
.status--pending { background: rgba(255,206,92,.12); color: var(--yellow); border-color: rgba(255,206,92,.35); }
.status--approved { background: rgba(61,220,151,.12); color: var(--green); border-color: rgba(61,220,151,.35); }
.status--rejected { background: rgba(255,92,122,.12); color: var(--red); border-color: rgba(255,92,122,.35); }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
.stat-card { background: linear-gradient(180deg, var(--panel) 0%, var(--bg-2) 100%); border: 1px solid var(--line);
  border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow-sm);
  transition: transform .2s, box-shadow .2s; }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-card__value { font-family: var(--font-display); font-size: 38px; line-height: 1;
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  -webkit-background-clip: text; background-clip: text; color: transparent; }
.stat-card__label { font-size: 12.5px; color: var(--ink-soft); margin-top: 8px; }
.chat { display: flex; flex-direction: column; background: var(--panel); border: 1px solid var(--line);
  border-radius: var(--radius); box-shadow: var(--shadow-md); overflow: hidden; min-height: 460px; }
.chat--admin { flex-direction: row; min-height: 520px; }
.chat__threads { width: 270px; border-right: 1px solid var(--line); background: var(--bg-2);
  overflow-y: auto; display: flex; flex-direction: column; }
.thread { padding: 14px 16px; border-bottom: 1px solid var(--line); cursor: pointer; transition: background .2s; }
.thread:hover { background: var(--panel-2); }
.thread.is-active { background: var(--panel); border-left: 3px solid var(--accent); }
.thread__subject { font-weight: 600; font-size: 13.5px; }
.thread__last { font-size: 12px; color: var(--ink-soft); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.thread__badge { display: inline-block; margin-top: 5px; font-size: 10.5px; font-weight: 600;
  color: #fff; background: var(--accent); border-radius: 999px; padding: 2px 8px; }
.chat__panel { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat__messages { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.chat__compose { display: flex; flex-direction: column; gap: 10px; padding: 16px 20px;
  border-top: 1px solid var(--line); background: var(--bg-2); }
.chat__compose .btn { align-self: flex-end; }
.chat__subject { font-weight: 600; }
.msg { max-width: 72%; padding: 10px 14px; border-radius: 14px; font-size: 14px; }
.msg--artist { align-self: flex-start; background: var(--panel-2); border-bottom-left-radius: 4px; }
.msg--admin { align-self: flex-end; color: #fff; background: linear-gradient(135deg, var(--accent), #9d7cff);
  border-bottom-right-radius: 4px; }
.msg__meta { font-size: 11px; opacity: .7; margin-bottom: 4px; }
.msg--admin .msg__meta { text-align: right; }
.empty-state { color: var(--ink-soft); font-size: 13.5px; margin: auto; }
.terms-text { white-space: pre-wrap; line-height: 1.7; font-size: 14.5px; }
.terms-editor { min-height: 280px; font-size: 14.5px; line-height: 1.6; }
.modal-overlay { position: fixed; inset: 0; z-index: 200; background: rgba(0,0,0,.6); backdrop-filter: blur(6px);
  display: none; place-items: center; padding: 20px; }
.modal-overlay.is-open { display: grid; animation: fadeIn .2s ease both; }
.modal { background: linear-gradient(180deg, var(--panel) 0%, var(--bg-2) 100%); border: 1px solid var(--line);
  border-radius: var(--radius); padding: 28px; width: 100%; max-width: 420px;
  box-shadow: var(--shadow-md); animation: popIn .25s ease both; }
.modal h3 { margin-bottom: 6px; }
.modal__sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 18px; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes popIn { from { opacity: 0; transform: scale(.94) translateY(8px); } to { opacity: 1; transform: none; } }
@media (max-width: 900px) {
  .app.is-active { grid-template-columns: 1fr; }
  .sidebar { position: static; height: auto; border-right: none; border-bottom: 1px solid var(--line); }
  .nav { flex-direction: row; flex-wrap: wrap; }
  .nav__item { flex: 1 1 auto; justify-content: center; }
  .content { padding: 24px 18px 50px; }
  .form-grid--2 { grid-template-columns: 1fr; }
  .chat--admin { flex-direction: column; }
  .chat__threads { width: 100%; max-height: 200px; border-right: none; border-bottom: 1px solid var(--line); }
}
</style>
</head>
<body>

<div class="auth-screen" id="authScreen">
  <div class="auth-card">
    <div class="auth-logo">
      <div class="auth-logo__mark">♪</div>
      <div class="auth-logo__text"><h1>Volna</h1><span>Distribution Platform</span></div>
    </div>
    <div class="auth-tabs">
      <button class="auth-tab is-active" data-auth-tab="login">Вход</button>
      <button class="auth-tab" data-auth-tab="register">Регистрация</button>
    </div>
    <form class="auth-form is-active" id="loginForm" autocomplete="off">
      <label class="field"><span>Логин или Email</span><input type="text" id="loginEmail" placeholder="you@example.com" required /></label>
      <label class="field"><span>Пароль</span><input type="password" id="loginPassword" placeholder="••••••••" required /></label>
      <div class="auth-error" id="loginError"></div>
      <button type="submit" class="auth-submit">Войти</button>
      <p class="auth-note">Нет аккаунта? <a id="goRegister">Создать</a></p>
    </form>
    <form class="auth-form" id="registerForm" autocomplete="off">
      <label class="field"><span>Имя / никнейм</span><input type="text" id="regName" placeholder="Например, Neon Tape" required /></label>
      <label class="field"><span>Email</span><input type="email" id="regEmail" placeholder="you@example.com" required /></label>
      <label class="field"><span>Пароль</span><input type="password" id="regPassword" placeholder="Минимум 4 символа" required minlength="4" /></label>
      <div class="auth-error" id="regError"></div>
      <button type="submit" class="auth-submit">Создать аккаунт</button>
      <p class="auth-note">Уже есть аккаунт? <a id="goLogin">Войти</a></p>
    </form>
  </div>
</div>

<div class="app" id="app">

  <aside class="sidebar" id="sidebar-artist">
    <div class="brand"><div class="brand__logo">♪</div><div class="brand__text"><h1>Volna</h1><span>кабинет артиста</span></div></div>
    <nav class="nav">
      <button class="nav__item is-active" data-tab="catalog"><span class="nav__icon">❏</span> Каталог</button>
      <button class="nav__item" data-tab="profile"><span class="nav__icon">☺</span> Профиль</button>
      <button class="nav__item" data-tab="newRelease"><span class="nav__icon">✚</span> Новый релиз</button>
      <button class="nav__item" data-tab="contract"><span class="nav__icon">✎</span> Договор</button>
      <button class="nav__item" data-tab="terms"><span class="nav__icon">§</span> Условия работы</button>
      <button class="nav__item" data-tab="support"><span class="nav__icon">✉</span> Поддержка</button>
    </nav>
    <div class="sidebar__footer">
      <div class="user-chip">
        <div class="user-chip__avatar" id="sideAvatarArtist">A</div>
        <div class="user-chip__info"><strong id="sideNameArtist">Артист</strong><span>музыкант</span></div>
      </div>
      <button class="logout-btn" id="logoutArtist">Выйти из аккаунта</button>
    </div>
  </aside>

  <aside class="sidebar is-hidden" id="sidebar-admin">
    <div class="brand"><div class="brand__logo brand__logo--admin">★</div><div class="brand__text"><h1>Volna</h1><span>панель админа</span></div></div>
    <nav class="nav">
      <button class="nav__item is-active" data-tab="dashboard"><span class="nav__icon">▤</span> Дашборд</button>
      <button class="nav__item" data-tab="moderation"><span class="nav__icon">⏳</span> Модерация</button>
      <button class="nav__item" data-tab="adminSupport"><span class="nav__icon">✉</span> Поддержка</button>
      <button class="nav__item" data-tab="termsEditor"><span class="nav__icon">§</span> Условия</button>
      <button class="nav__item" data-tab="contracts"><span class="nav__icon">▦</span> Договоры</button>
    </nav>
    <div class="sidebar__footer">
      <div class="user-chip">
        <div class="user-chip__avatar user-chip__avatar--admin">AD</div>
        <div class="user-chip__info"><strong id="adminName">Администратор</strong><span>модератор</span></div>
      </div>
      <button class="logout-btn" id="logoutAdmin">Выйти из аккаунта</button>
    </div>
  </aside>

  <main class="content">

    <section class="tab is-active" data-tab-content="catalog">
      <header class="page-head"><h2>Каталог релизов</h2><p class="page-head__sub">Ваши загруженные треки и их статусы</p></header>
      <div class="catalog" id="artistCatalog"></div>
    </section>

    <section class="tab" data-tab-content="profile">
      <header class="page-head"><h2>Профиль</h2><p class="page-head__sub">Настройте внешний вид кабинета</p></header>
      <div class="card card--profile">
        <div class="profile-avatar-block">
          <div class="avatar-preview" id="avatarPreview">A</div>
          <label class="btn btn--ghost">Загрузить аватар<input type="file" id="avatarInput" accept="image/*" hidden /></label>
        </div>
        <div class="form-grid">
          <label class="field"><span>Никнейм</span><input type="text" id="profileNickname" placeholder="Ваш никнейм" /></label>
          <label class="field"><span>Описание</span><textarea id="profileBio" rows="4" placeholder="Расскажите о своём творчестве"></textarea></label>
          <label class="field"><span>Акцентный цвет</span>
            <div class="color-row"><input type="color" id="profileAccent" value="#7c5cff" /><span class="hint">Применяется к вашему кабинету</span></div>
          </label>
        </div>
        <div class="actions">
          <button class="btn btn--primary" id="saveProfileBtn">Сохранить</button>
          <span class="save-note" id="profileSaveNote"></span>
        </div>
      </div>
    </section>

    <section class="tab" data-tab-content="newRelease">
      <header class="page-head"><h2>Новый релиз</h2><p class="page-head__sub">Загрузите трек — он уйдёт на модерацию</p></header>
      <div class="card">
        <div class="form-grid form-grid--2">
          <label class="field"><span>Название трека *</span><input type="text" id="releaseTitle" placeholder="Название" /></label>
          <label class="field"><span>Жанр</span>
            <select id="releaseGenre">
              <option>Инди</option><option>Поп</option><option>Рэп / Хип-хоп</option>
              <option>Электроника</option><option>Рок</option><option>Джаз</option>
              <option>Лоу-фай</option><option>Классика</option>
            </select>
          </label>
          <label class="field"><span>Обложка</span>
            <div class="upload-box">
              <div class="upload-preview" id="coverPreview">◍</div>
              <label class="btn btn--ghost btn--small">Выбрать<input type="file" id="coverInput" accept="image/*" hidden /></label>
            </div>
          </label>
          <label class="field"><span>Аудиофайл</span>
            <div class="upload-box">
              <audio id="audioPreview" class="audio-preview" controls hidden></audio>
              <label class="btn btn--ghost btn--small">Выбрать аудио<input type="file" id="audioInput" accept="audio/*" hidden /></label>
              <span class="hint" id="audioName"></span>
            </div>
          </label>
        </div>
        <div class="actions">
          <button class="btn btn--primary" id="submitReleaseBtn">Отправить на модерацию</button>
          <span class="save-note" id="releaseNote"></span>
        </div>
      </div>
    </section>

    <section class="tab" data-tab-content="contract">
      <header class="page-head"><h2>Договор</h2><p class="page-head__sub">Данные доступны администратору</p></header>
      <div class="card">
        <div class="form-grid form-grid--2">
          <label class="field"><span>ФИО</span><input type="text" id="contractName" placeholder="Иванов Иван Иванович" /></label>
          <label class="field"><span>Паспорт</span><input type="text" id="contractPassport" placeholder="0000 000000" /></label>
          <label class="field"><span>Карта / счёт</span><input type="text" id="contractCard" placeholder="0000 0000 0000 0000" /></label>
          <label class="field"><span>Телефон</span><input type="text" id="contractPhone" placeholder="+7 900 000-00-00" /></label>
        </div>
        <div class="actions">
          <button class="btn btn--primary" id="saveContractBtn">Сохранить</button>
          <span class="save-note" id="contractNote"></span>
        </div>
      </div>
    </section>

    <section class="tab" data-tab-content="terms">
      <header class="page-head"><h2>Условия работы</h2><p class="page-head__sub">Текст задаёт администратор</p></header>
      <div class="card"><div class="terms-text" id="termsView">Загрузка…</div></div>
    </section>

    <section class="tab" data-tab-content="support">
      <header class="page-head"><h2>Поддержка</h2><p class="page-head__sub">Задайте вопрос — админ ответит в чате</p></header>
      <div class="chat">
        <div class="chat__messages" id="artistChatMessages"></div>
        <div class="chat__compose">
          <input type="text" id="artistChatSubject" class="chat__subject" placeholder="Тема обращения" />
          <textarea id="artistChatText" rows="2" placeholder="Опишите вопрос…"></textarea>
          <button class="btn btn--primary" id="artistChatSend">Отправить</button>
        </div>
      </div>
    </section>

    <section class="tab" data-tab-content="dashboard">
      <header class="page-head"><h2>Дашборд</h2><p class="page-head__sub">Показатели платформы</p></header>
      <div class="stats">
        <div class="stat-card"><div class="stat-card__value" id="statUsers">0</div><div class="stat-card__label">Артистов</div></div>
        <div class="stat-card"><div class="stat-card__value" id="statReleases">0</div><div class="stat-card__label">Всего релизов</div></div>
        <div class="stat-card"><div class="stat-card__value" id="statPending">0</div><div class="stat-card__label">На модерации</div></div>
        <div class="stat-card"><div class="stat-card__value" id="statApproved">0</div><div class="stat-card__label">Одобрено</div></div>
      </div>
    </section>

    <section class="tab" data-tab-content="moderation">
      <header class="page-head"><h2>Модерация</h2><p class="page-head__sub">Треки, ожидающие решения</p></header>
      <div class="card"><div id="moderationList" class="moderation-list"></div></div>
      <header class="page-head page-head--spaced"><h3>Все релизы</h3></header>
      <div class="card"><div id="allReleasesList" class="moderation-list"></div></div>
    </section>

    <section class="tab" data-tab-content="adminSupport">
      <header class="page-head"><h2>Поддержка</h2><p class="page-head__sub">Обращения артистов</p></header>
      <div class="chat chat--admin">
        <div class="chat__threads" id="supportThreads"></div>
        <div class="chat__panel">
          <div class="chat__messages" id="adminChatMessages"><div class="empty-state">Выберите обращение</div></div>
          <div class="chat__compose">
            <textarea id="adminChatText" rows="2" placeholder="Введите ответ…"></textarea>
            <button class="btn btn--primary" id="adminChatSend">Ответить</button>
          </div>
        </div>
      </div>
    </section>

    <section class="tab" data-tab-content="termsEditor">
      <header class="page-head"><h2>Редактор условий</h2><p class="page-head__sub">Текст для артистов</p></header>
      <div class="card">
        <textarea id="termsEditorArea" rows="12" class="terms-editor" placeholder="Введите текст условий…"></textarea>
        <div class="actions">
          <button class="btn btn--primary" id="saveTermsBtn">Сохранить</button>
          <span class="save-note" id="termsNote"></span>
        </div>
      </div>
    </section>

    <section class="tab" data-tab-content="contracts">
      <header class="page-head"><h2>Договоры</h2><p class="page-head__sub">Заполненные анкеты артистов</p></header>
      <div class="card"><div id="contractsList" class="moderation-list"></div></div>
    </section>

  </main>
</div>

<div class="modal-overlay" id="upcModal">
  <div class="modal">
    <h3>Присвоить UPC код</h3>
    <p class="modal__sub" id="upcModalTrack"></p>
    <label class="field"><span>UPC код</span><input type="text" id="upcInput" placeholder="Например, 012345678905" /></label>
    <div class="actions actions--end">
      <button class="btn btn--ghost" id="upcCancel">Отмена</button>
      <button class="btn btn--primary" id="upcConfirm">Одобрить</button>
    </div>
  </div>
</div>

<script>
(function () {
  "use strict";

  const API_URL       = "index.php";          // путь к этому же файлу
  const STORAGE_KEY   = "volna-state-v1";     // ключ локального кэша (fallback)

  /* Начальное состояние, отрисованное PHP */
  const INITIAL_STATE = <?= json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const defaultState = {
    users: [], currentUserId: null, releases: [], contracts: {},
    terms: "", threads: [], activeThreadId: null, profiles: {}
  };

  let state = clone(defaultState);

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  /* =========================================================
     API
     ========================================================= */
  async function api(action, body) {
    const url = API_URL + "?action=" + encodeURIComponent(action);
    const opts = { method: body ? "POST" : "GET", headers: { "Content-Type": "application/json" } };
    if (body) opts.body = JSON.stringify(body);
    const res = await fetch(url, opts);
    return res.json();
  }

  async function pullState() {
    try {
      const r = await api("state");
      if (r && r.ok && r.state) state = Object.assign(clone(defaultState), r.state);
    } catch (e) {
      // fallback на localStorage, если сервер недоступен
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        state = raw ? Object.assign(clone(defaultState), JSON.parse(raw)) : clone(defaultState);
      } catch (err) { state = clone(defaultState); }
    }
  }

  async function pushState() {
    try {
      await api("save", { state: state });
    } catch (e) {
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (err) {}
    }
  }

  const $  = (s) => document.querySelector(s);
  const $$ = (s) => document.querySelectorAll(s);

  function escapeHtml(str) {
    return String(str == null ? "" : str).replace(/[&<>"']/g, (m) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    }[m]));
  }
  function fmtDate(ts) {
    return new Date(ts).toLocaleDateString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric" });
  }
  function statusLabel(s) {
    if (s === "pending") return "На модерации";
    if (s === "approved") return "Одобрено";
    return "Отклонено";
  }
  function statusClass(s) {
    if (s === "pending") return "status--pending";
    if (s === "approved") return "status--approved";
    return "status--rejected";
  }
  function showNote(el, text) {
    if (!el) return;
    el.textContent = text;
    el.classList.add("is-visible");
    setTimeout(() => el.classList.remove("is-visible"), 2600);
  }
  function uid(prefix) {
    return (prefix || "id") + "-" + Date.now() + "-" + Math.random().toString(36).slice(2,7);
  }
  function getCurrentUser() {
    return state.users.find((u) => u.id === state.currentUserId) || null;
  }
  function getProfile(userId) {
    if (!state.profiles[userId]) state.profiles[userId] = { nickname: "", bio: "", avatar: null, accent: "#7c5cff" };
    return state.profiles[userId];
  }

  /* =========================================================
     Авторизация
     ========================================================= */
  const authScreen = $("#authScreen");
  const appEl = $("#app");

  $$(".auth-tab").forEach((tab) => {
    tab.addEventListener("click", () => {
      const which = tab.dataset.authTab;
      $$(".auth-tab").forEach((t) => t.classList.toggle("is-active", t === tab));
      $$(".auth-form").forEach((f) => f.classList.toggle("is-active", f.id === (which === "login" ? "loginForm" : "registerForm")));
      $("#loginError").textContent = "";
      $("#regError").textContent = "";
    });
  });
  $("#goRegister").addEventListener("click", () => $('.auth-tab[data-auth-tab="register"]').click());
  $("#goLogin").addEventListener("click", () => $('.auth-tab[data-auth-tab="login"]').click());

  $("#registerForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const name = $("#regName").value.trim();
    const email = $("#regEmail").value.trim().toLowerCase();
    const password = $("#regPassword").value;
    const errEl = $("#regError"); errEl.textContent = "";

    if (!name || !email || !password) { errEl.textContent = "Заполните все поля"; return; }
    if (password.length < 4) { errEl.textContent = "Пароль слишком короткий"; return; }

    try {
      const r = await api("register", { name, email, password });
      if (!r.ok) { errEl.textContent = r.error || "Ошибка регистрации"; return; }
      await pullState();
      enterApp(r.user);
    } catch (err) {
      errEl.textContent = "Сервер недоступен. Откройте через PHP-сервер.";
    }
  });

  $("#loginForm").addEventListener("submit", async (e) => {
    e.preventDefault();
    const login = $("#loginEmail").value.trim().toLowerCase();
    const password = $("#loginPassword").value;
    const errEl = $("#loginError"); errEl.textContent = "";

    try {
      const r = await api("login", { login, password });
      if (!r.ok) { errEl.textContent = r.error || "Неверный логин или пароль"; return; }
      await pullState();
      enterApp(r.user);
    } catch (err) {
      errEl.textContent = "Сервер недоступен. Откройте через PHP-сервер.";
    }
  });

  async function logout() {
    try { await api("logout"); } catch (e) {}
    state.currentUserId = null;
    appEl.classList.remove("is-active");
    authScreen.classList.remove("is-hidden");
    $("#loginForm").reset();
    $("#registerForm").reset();
    $("#loginError").textContent = "";
    $("#regError").textContent = "";
    $('.auth-tab[data-auth-tab="login"]').click();
  }
  $("#logoutArtist").addEventListener("click", logout);
  $("#logoutAdmin").addEventListener("click", logout);

  /* =========================================================
     Вход в приложение
     ========================================================= */
  function enterApp(user) {
    authScreen.classList.add("is-hidden");
    appEl.classList.add("is-active");

    if (user.role === "admin") {
      $("#sidebar-artist").classList.add("is-hidden");
      $("#sidebar-admin").classList.remove("is-hidden");
      $("#adminName").textContent = user.name || "Администратор";
      activateTab("dashboard");
      renderDashboard(); renderModeration(); renderSupportThreads(); renderContracts();
      $("#termsEditorArea").value = state.terms;
    } else {
      $("#sidebar-admin").classList.add("is-hidden");
      $("#sidebar-artist").classList.remove("is-hidden");
      renderArtistSidebar(); initProfile(); initContract();
      activateTab("catalog");
      renderCatalog(); renderTerms(); renderArtistChat();
    }
  }

  function activateTab(tabName) {
    const user = getCurrentUser(); if (!user) return;
    const sidebarSel = user.role === "artist" ? "#sidebar-artist" : "#sidebar-admin";
    $$(sidebarSel + " .nav__item").forEach((b) => b.classList.toggle("is-active", b.dataset.tab === tabName));
    $$(".tab").forEach((t) => t.classList.toggle("is-active", t.dataset.tabContent === tabName));

    if (tabName === "catalog") renderCatalog();
    if (tabName === "terms") renderTerms();
    if (tabName === "support") renderArtistChat();
    if (tabName === "dashboard") renderDashboard();
    if (tabName === "moderation") renderModeration();
    if (tabName === "adminSupport") renderSupportThreads();
    if (tabName === "contracts") renderContracts();
    if (tabName === "termsEditor") $("#termsEditorArea").value = state.terms;
  }
  $$("#sidebar-artist .nav__item, #sidebar-admin .nav__item").forEach((b) => {
    b.addEventListener("click", () => activateTab(b.dataset.tab));
  });

  /* =========================================================
     Артист: профиль
     ========================================================= */
  function renderArtistSidebar() {
    const user = getCurrentUser(); if (!user) return;
    const p = getProfile(user.id);
    const nick = p.nickname || user.name || "Артист";
    $("#sideNameArtist").textContent = nick;
    const av = $("#sideAvatarArtist");
    if (p.avatar) {
      av.style.backgroundImage = "url(" + p.avatar + ")";
      av.style.background = "transparent";
      av.textContent = "";
    } else {
      av.style.backgroundImage = "";
      av.style.background = "linear-gradient(135deg," + (p.accent || "#7c5cff") + ", #22d3ee)";
      av.textContent = nick.charAt(0).toUpperCase();
    }
    document.documentElement.style.setProperty("--accent", p.accent || "#7c5cff");
  }

  function initProfile() {
    const user = getCurrentUser(); if (!user) return;
    const p = getProfile(user.id);
    $("#profileNickname").value = p.nickname || user.name || "";
    $("#profileBio").value = p.bio || "";
    $("#profileAccent").value = p.accent || "#7c5cff";
    const prev = $("#avatarPreview");
    const nick = p.nickname || user.name || "A";
    if (p.avatar) {
      prev.style.backgroundImage = "url(" + p.avatar + ")";
      prev.style.background = "transparent";
      prev.textContent = "";
    } else {
      prev.style.backgroundImage = "";
      prev.style.background = "linear-gradient(135deg," + (p.accent || "#7c5cff") + ", #22d3ee)";
      prev.textContent = nick.charAt(0).toUpperCase();
    }
  }

  $("#avatarInput").addEventListener("change", (e) => {
    const f = e.target.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = () => {
      const prev = $("#avatarPreview");
      prev.style.backgroundImage = "url(" + reader.result + ")";
      prev.style.background = "transparent";
      prev.textContent = "";
      prev.dataset.pending = reader.result;
    };
    reader.readAsDataURL(f);
  });
  $("#profileAccent").addEventListener("input", (e) => {
    const prev = $("#avatarPreview");
    if (!prev.style.backgroundImage) prev.style.background = "linear-gradient(135deg," + e.target.value + ", #22d3ee)";
  });
  $("#saveProfileBtn").addEventListener("click", async () => {
    const user = getCurrentUser(); if (!user) return;
    const p = getProfile(user.id);
    const nick = $("#profileNickname").value.trim() || user.name || "Артист";
    p.nickname = nick;
    p.bio = $("#profileBio").value.trim();
    p.accent = $("#profileAccent").value;
    const pending = $("#avatarPreview").dataset.pending;
    if (pending) { p.avatar = pending; delete $("#avatarPreview").dataset.pending; }
    state.threads.forEach((t) => { if (t.artistId === user.id) t.artistName = nick; });
    await pushState();
    renderArtistSidebar(); initProfile();
    showNote($("#profileSaveNote"), "Профиль сохранён ✓");
  });

  /* =========================================================
     Артист: новый релиз
     ========================================================= */
  let newReleaseCover = null;
  $("#coverInput").addEventListener("change", (e) => {
    const f = e.target.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = () => {
      newReleaseCover = reader.result;
      const prev = $("#coverPreview");
      prev.style.backgroundImage = "url(" + reader.result + ")";
      prev.textContent = "";
    };
    reader.readAsDataURL(f);
  });
  $("#audioInput").addEventListener("change", (e) => {
    const f = e.target.files[0]; if (!f) return;
    const audio = $("#audioPreview");
    audio.src = URL.createObjectURL(f);
    audio.hidden = false;
    $("#audioName").textContent = f.name;
    $("#audioInput").dataset.fileName = f.name;
  });
  $("#submitReleaseBtn").addEventListener("click", async () => {
    const user = getCurrentUser(); if (!user) return;
    const title = $("#releaseTitle").value.trim();
    if (!title) { showNote($("#releaseNote"), "Укажите название трека"); return; }
    const p = getProfile(user.id);
    state.releases.unshift({
      id: uid("r"), title, genre: $("#releaseGenre").value, cover: newReleaseCover,
      audioName: $("#audioInput").dataset.fileName || "", status: "pending", upc: null,
      createdAt: Date.now(), artistId: user.id, artistName: p.nickname || user.name || "Артист"
    });
    await pushState();
    $("#releaseTitle").value = "";
    $("#coverPreview").style.backgroundImage = "";
    $("#coverPreview").textContent = "◍";
    newReleaseCover = null;
    $("#audioPreview").hidden = true;
    $("#audioPreview").src = "";
    $("#audioName").textContent = "";
    delete $("#audioInput").dataset.fileName;
    activateTab("catalog");
    showNote($("#releaseNote"), "Трек отправлен на модерацию ✓");
  });

  /* =========================================================
     Артист: каталог
     ========================================================= */
  function renderCatalog() {
    const user = getCurrentUser(); if (!user) return;
    const wrap = $("#artistCatalog"); wrap.innerHTML = "";
    const mine = state.releases.filter((r) => r.artistId === user.id);
    if (!mine.length) {
      wrap.innerHTML = '<div class="card"><div class="empty-state">Пока нет релизов. Загрузите первый трек в разделе «Новый релиз».</div></div>';
      return;
    }
    mine.forEach((r) => wrap.appendChild(releaseCard(r, false)));
  }

  /* =========================================================
     Артист: договор
     ========================================================= */
  function initContract() {
    const user = getCurrentUser(); if (!user) return;
    const c = state.contracts[user.id] || { name: "", passport: "", card: "", phone: "" };
    $("#contractName").value = c.name || "";
    $("#contractPassport").value = c.passport || "";
    $("#contractCard").value = c.card || "";
    $("#contractPhone").value = c.phone || "";
  }
  $("#saveContractBtn").addEventListener("click", async () => {
    const user = getCurrentUser(); if (!user) return;
    state.contracts[user.id] = {
      name: $("#contractName").value.trim(),
      passport: $("#contractPassport").value.trim(),
      card: $("#contractCard").value.trim(),
      phone: $("#contractPhone").value.trim(),
      savedAt: Date.now(), userName: user.name, userEmail: user.email
    };
    await pushState();
    showNote($("#contractNote"), "Данные договора сохранены ✓");
  });

  /* =========================================================
     Условия
     ========================================================= */
  function renderTerms() {
    $("#termsView").textContent = state.terms || "Текст условий пока не задан.";
  }
  $("#saveTermsBtn").addEventListener("click", async () => {
    state.terms = $("#termsEditorArea").value;
    await pushState(); renderTerms();
    showNote($("#termsNote"), "Условия обновлены ✓");
  });

  /* =========================================================
     Чат артиста
     ========================================================= */
  function renderArtistChat() {
    const user = getCurrentUser(); if (!user) return;
    const wrap = $("#artistChatMessages"); wrap.innerHTML = "";
    const t = state.threads.find((x) => x.artistId === user.id);
    if (!t) { wrap.innerHTML = '<div class="empty-state">Обращений пока нет. Напишите нам — мы ответим.</div>'; return; }
    t.messages.forEach((m) => {
      const el = document.createElement("div");
      el.className = "msg " + (m.from === "artist" ? "msg--artist" : "msg--admin");
      el.innerHTML = '<div class="msg__meta">' + (m.from === "artist" ? "Вы" : "Поддержка") + " • " + fmtDate(m.at) + "</div><div>" + escapeHtml(m.text) + "</div>";
      wrap.appendChild(el);
    });
    wrap.scrollTop = wrap.scrollHeight;
  }
  $("#artistChatSend").addEventListener("click", async () => {
    const user = getCurrentUser(); if (!user) return;
    const subject = $("#artistChatSubject").value.trim();
    const text = $("#artistChatText").value.trim();
    if (!text) return;
    let t = state.threads.find((x) => x.artistId === user.id);
    const p = getProfile(user.id);
    if (!t) {
      t = { id: uid("t"), subject: subject || "Обращение", artistId: user.id,
            artistName: p.nickname || user.name || "Артист", messages: [], unreadForAdmin: true };
      state.threads.unshift(t);
    } else if (subject) t.subject = subject;
    t.messages.push({ from: "artist", text, at: Date.now() });
    t.unreadForAdmin = true;
    await pushState(); $("#artistChatText").value = ""; renderArtistChat();
  });

  /* =========================================================
     Админ: дашборд
     ========================================================= */
  function renderDashboard() {
    $("#statUsers").textContent = state.users.filter((u) => u.role !== "admin").length;
    $("#statReleases").textContent = state.releases.length;
    $("#statPending").textContent = state.releases.filter((r) => r.status === "pending").length;
    $("#statApproved").textContent = state.releases.filter((r) => r.status === "approved").length;
  }

  /* =========================================================
     Админ: модерация
     ========================================================= */
  function releaseCard(r, withActions) {
    const el = document.createElement("div");
    el.className = "release-card";
    el.innerHTML =
      '<div class="release-card__cover" ' + (r.cover ? 'style="background-image:url(' + r.cover + ')"' : "") + ">" +
        (r.cover ? "" : "♫") + "</div>" +
      "<div>" +
        '<div class="release-card__title">' + escapeHtml(r.title) + "</div>" +
        '<div class="release-card__meta">' + escapeHtml(r.genre) + " • " + fmtDate(r.createdAt) +
        (withActions ? " • " + escapeHtml(r.artistName || "Артист") : "") + "</div>" +
        (r.upc ? '<div class="release-card__upc">UPC: ' + escapeHtml(r.upc) + "</div>" : "") +
      "</div>" +
      '<div class="release-card__side">' +
        '<span class="status ' + statusClass(r.status) + '">' + statusLabel(r.status) + "</span>" +
        '<div class="release-actions"></div>' +
      "</div>";
    if (withActions && r.status === "pending") {
      const box = el.querySelector(".release-actions");
      box.style.display = "flex"; box.style.gap = "8px"; box.style.marginTop = "6px";
      const approve = document.createElement("button");
      approve.className = "btn btn--green btn--small";
      approve.textContent = "Одобрить";
      approve.addEventListener("click", () => openUpcModal(r.id));
      const reject = document.createElement("button");
      reject.className = "btn btn--red btn--small";
      reject.textContent = "Отклонить";
      reject.addEventListener("click", async () => {
        r.status = "rejected"; await pushState();
        renderModeration(); renderDashboard();
      });
      box.appendChild(approve); box.appendChild(reject);
    }
    return el;
  }
  function renderModeration() {
    const pendingWrap = $("#moderationList");
    const allWrap = $("#allReleasesList");
    pendingWrap.innerHTML = ""; allWrap.innerHTML = "";
    const pending = state.releases.filter((r) => r.status === "pending");
    if (!pending.length) pendingWrap.innerHTML = '<div class="empty-state">Нет релизов на модерации</div>';
    else pending.forEach((r) => pendingWrap.appendChild(releaseCard(r, true)));
    if (!state.releases.length) allWrap.innerHTML = '<div class="empty-state">Релизов пока нет.</div>';
    else state.releases.slice().sort((a,b) => b.createdAt - a.createdAt).forEach((r) => allWrap.appendChild(releaseCard(r, false)));
  }

  /* =========================================================
     Модалка UPC
     ========================================================= */
  let upcTargetId = null;
  function openUpcModal(id) {
    upcTargetId = id;
    const r = state.releases.find((x) => x.id === id);
    $("#upcModalTrack").textContent = r ? "Трек: " + r.title : "";
    $("#upcInput").value = "";
    $("#upcInput").style.borderColor = "";
    $("#upcModal").classList.add("is-open");
    setTimeout(() => $("#upcInput").focus(), 50);
  }
  function closeUpcModal() {
    $("#upcModal").classList.remove("is-open");
    upcTargetId = null;
  }
  $("#upcCancel").addEventListener("click", closeUpcModal);
  $("#upcModal").addEventListener("click", (e) => { if (e.target.id === "upcModal") closeUpcModal(); });
  $("#upcConfirm").addEventListener("click", async () => {
    const upc = $("#upcInput").value.trim();
    if (!upc) { $("#upcInput").style.borderColor = "#ff5c7a"; return; }
    const r = state.releases.find((x) => x.id === upcTargetId);
    if (!r) return closeUpcModal();
    r.status = "approved"; r.upc = upc;
    await pushState();
    closeUpcModal(); renderModeration(); renderDashboard();
  });

  /* =========================================================
     Админ: поддержка
     ========================================================= */
  function renderSupportThreads() {
    const wrap = $("#supportThreads"); wrap.innerHTML = "";
    if (!state.threads.length) {
      wrap.innerHTML = '<div class="empty-state" style="padding:20px">Обращений нет</div>';
      $("#adminChatMessages").innerHTML = '<div class="empty-state">Выберите обращение</div>';
      return;
    }
    state.threads.slice()
      .sort((a,b) => {
        const la = a.messages[a.messages.length-1];
        const lb = b.messages[b.messages.length-1];
        return ((lb ? lb.at : 0) - (la ? la.at : 0));
      })
      .forEach((t) => {
        const last = t.messages[t.messages.length - 1];
        const el = document.createElement("div");
        el.className = "thread" + (t.id === state.activeThreadId ? " is-active" : "");
        el.innerHTML =
          '<div class="thread__subject">' + escapeHtml(t.subject || "Без темы") + "</div>" +
          '<div class="thread__last">' + escapeHtml(last ? last.text : "") + "</div>" +
          (t.unreadForAdmin ? '<span class="thread__badge">новое</span>' : "");
        el.addEventListener("click", async () => {
          state.activeThreadId = t.id;
          t.unreadForAdmin = false;
          await pushState(); renderSupportThreads();
        });
        wrap.appendChild(el);
      });
    renderAdminChatMessages();
  }
  function renderAdminChatMessages() {
    const wrap = $("#adminChatMessages");
    const t = state.threads.find((x) => x.id === state.activeThreadId);
    if (!t) { wrap.innerHTML = '<div class="empty-state">Выберите обращение</div>'; return; }
    wrap.innerHTML = "";
    t.messages.forEach((m) => {
      const el = document.createElement("div");
      el.className = "msg " + (m.from === "admin" ? "msg--admin" : "msg--artist");
      el.innerHTML = '<div class="msg__meta">' + (m.from === "admin" ? "Вы (админ)" : escapeHtml(t.artistName)) + " • " + fmtDate(m.at) + "</div><div>" + escapeHtml(m.text) + "</div>";
      wrap.appendChild(el);
    });
    wrap.scrollTop = wrap.scrollHeight;
  }
  $("#adminChatSend").addEventListener("click", async () => {
    const text = $("#adminChatText").value.trim();
    if (!text) return;
    const t = state.threads.find((x) => x.id === state.activeThreadId);
    if (!t) return;
    t.messages.push({ from: "admin", text, at: Date.now() });
    await pushState(); $("#adminChatText").value = ""; renderAdminChatMessages();
  });

  /* =========================================================
     Админ: договоры
     ========================================================= */
  function renderContracts() {
    const wrap = $("#contractsList"); wrap.innerHTML = "";
    const entries = Object.entries(state.contracts).filter(([,c]) => c && c.savedAt);
    if (!entries.length) { wrap.innerHTML = '<div class="empty-state">Пока ни один артист не заполнил договор.</div>'; return; }
    entries.sort((a,b) => (b[1].savedAt || 0) - (a[1].savedAt || 0));
    entries.forEach(([userId, c]) => {
      const user = state.users.find((u) => u.id === userId);
      const el = document.createElement("div");
      el.className = "release-card";
      el.style.gridTemplateColumns = "56px 1fr";
      el.innerHTML =
        '<div class="release-card__cover">✎</div>' +
        "<div>" +
          '<div class="release-card__title">' + escapeHtml(c.name || (user ? user.name : "Без имени")) + "</div>" +
          '<div class="release-card__meta">Email: ' + escapeHtml((user && user.email) || "—") + "</div>" +
          '<div class="release-card__meta">Паспорт: ' + escapeHtml(c.passport || "—") + "</div>" +
          '<div class="release-card__meta">Карта/счёт: ' + escapeHtml(c.card || "—") + "</div>" +
          '<div class="release-card__meta">Телефон: ' + escapeHtml(c.phone || "—") + "</div>" +
          '<div class="release-card__meta">Сохранён: ' + fmtDate(c.savedAt) + "</div>" +
        "</div>";
      wrap.appendChild(el);
    });
  }

  async function boot() {
    state = Object.assign(clone(defaultState), INITIAL_STATE || {});
    try { await pullState(); } catch (e) {}

    let user = getCurrentUser();
    if (!user) {
      try {
        const r = await api("me");
        if (r.ok && r.user) {
          user = r.user;
          state.currentUserId = r.user.id;
        }
      } catch (e) {}
    }

    if (user) enterApp(user);
    else { authScreen.classList.remove("is-hidden"); appEl.classList.remove("is-active"); }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
</script>
</body>
</html>