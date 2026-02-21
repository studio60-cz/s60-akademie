<?php
/**
 * S60Auth SSO handler
 *
 * Přijme authorization code z S60Auth, vymění za token,
 * najde/vytvoří uživatele a přihlásí ho do Moodle.
 *
 * Tile URL: https://auth.s60dev.cz/authorize
 *   ?client_id=s60-learnia-akademie-caafe24b
 *   &redirect_uri=https://akademie.learnia.cz/local/s60auth/sso.php
 */

require_once('../../config.php');
require_once($CFG->libdir . '/authlib.php');

// ── Konfigurace ────────────────────────────────────────────────────────────────

// Načte z Moodle config (nastav přes admin nebo přímo v DB)
// Nebo hardcode pro jednoduchost (změnit pro prod!)
$S60AUTH_URL    = defined('S60AUTH_URL')    ? S60AUTH_URL    : 'https://auth.s60dev.cz';
$CLIENT_ID      = defined('S60AUTH_CLIENT_ID')     ? S60AUTH_CLIENT_ID     : 's60-learnia-akademie-caafe24b';
$CLIENT_SECRET  = defined('S60AUTH_CLIENT_SECRET')  ? S60AUTH_CLIENT_SECRET  : '76915aa4fd149fcdab3bce9608f5eca807017664f5a9edffdb9418475cb7d915';
$REDIRECT_URI   = $CFG->wwwroot . '/local/s60auth/sso.php';

// ── Vstupní validace ───────────────────────────────────────────────────────────

$code  = optional_param('code', '', PARAM_RAW);
$error = optional_param('error', '', PARAM_RAW);

if ($error) {
    throw new moodle_exception('S60Auth SSO error: ' . s($error));
}

if (empty($code)) {
    // Žádný code → přesměruj na auth
    $authorize_url = $S60AUTH_URL . '/authorize?' . http_build_query([
        'client_id'    => $CLIENT_ID,
        'redirect_uri' => $REDIRECT_URI,
    ]);
    redirect($authorize_url);
}

// ── Výměna code za token ───────────────────────────────────────────────────────

$ch = curl_init($S60AUTH_URL . '/api/auth/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $CLIENT_ID,
        'client_secret' => $CLIENT_SECRET,
        'redirect_uri'  => $REDIRECT_URI,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 10,
]);
$token_response = curl_exec($ch);
$token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($token_http_code !== 200) {
    throw new moodle_exception('S60Auth: token exchange failed (HTTP ' . $token_http_code . ')');
}

$token_data = json_decode($token_response, true);
if (empty($token_data['access_token'])) {
    throw new moodle_exception('S60Auth: no access_token in response');
}

// ── Získání userinfo ───────────────────────────────────────────────────────────

$ch = curl_init($S60AUTH_URL . '/api/auth/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token_data['access_token']],
    CURLOPT_TIMEOUT        => 10,
]);
$userinfo_response = curl_exec($ch);
$userinfo_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($userinfo_http_code !== 200) {
    throw new moodle_exception('S60Auth: userinfo call failed (HTTP ' . $userinfo_http_code . ')');
}

$userinfo = json_decode($userinfo_response, true);
if (empty($userinfo['email']) || empty($userinfo['sub'])) {
    throw new moodle_exception('S60Auth: missing email or sub in userinfo');
}

// ── Najít nebo vytvořit Moodle uživatele ──────────────────────────────────────

$user = get_complete_user_data('email', $userinfo['email']);

if (!$user) {
    // Nový uživatel — vytvoříme ho
    $newuser = new stdClass();
    $newuser->auth        = 'oauth2';
    $newuser->confirmed   = 1;
    $newuser->mnethostid  = $CFG->mnet_localhost_id;
    $newuser->email       = $userinfo['email'];
    $newuser->username    = strtolower($userinfo['sub']); // S60Auth userId jako username
    $newuser->idnumber    = $userinfo['sub'];             // S60Auth userId pro reconciliation
    $newuser->firstname   = $userinfo['given_name']  ?? '';
    $newuser->lastname    = $userinfo['family_name'] ?? '';
    $newuser->lang        = 'cs';

    $userid = user_create_user($newuser, false, false);
    $user   = get_complete_user_data('id', $userid);

    if (!$user) {
        throw new moodle_exception('S60Auth: failed to create Moodle user');
    }
} else {
    // Existující uživatel — aktualizuj jméno a idnumber (reconciliation)
    $updateuser = new stdClass();
    $updateuser->id        = $user->id;
    $updateuser->firstname = $userinfo['given_name']  ?? $user->firstname;
    $updateuser->lastname  = $userinfo['family_name'] ?? $user->lastname;
    $updateuser->idnumber  = $userinfo['sub'];
    user_update_user($updateuser, false, false);
    $user = get_complete_user_data('id', $user->id);
}

// ── Přihlásit uživatele ───────────────────────────────────────────────────────

complete_user_login($user);

// Přesměruj na dashboard (nebo na původní stránku pokud je v session)
$wantsurl = isset($SESSION->wantsurl) ? $SESSION->wantsurl : new moodle_url('/my/');
redirect($wantsurl);
