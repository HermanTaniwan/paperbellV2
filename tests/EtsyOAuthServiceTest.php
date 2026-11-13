<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/OAuthVault.php';
require_once dirname(__DIR__).'/src/EtsyOAuthService.php';

function etsyAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function etsyReject(callable $callback, string $message): void {
    try { $callback(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
$dir = sys_get_temp_dir().'/paperbell-etsy-test-'.bin2hex(random_bytes(8));
mkdir($dir);
file_put_contents($dir.'/credentials.json', json_encode(['keystring'=>'test-key','shared_secret'=>'test-secret']));
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$calls = [];
$transport = function(string $method, string $url, ?array $form, string $token) use (&$calls): array {
    $calls[] = compact('method','url','form','token');
    if (str_ends_with($url, '/shops')) return ['shop_id'=>42,'shop_name'=>'Test Shop'];
    return ['access_token'=>'123.fake-access','refresh_token'=>'fake-refresh','expires_in'=>3600];
};
try {
    $vault = new OAuthVault($dir.'/vault.key');
    $service = new EtsyOAuthService($db, $vault, $dir.'/credentials.json', $transport);
    etsyAssert($service->status()['configured'] && !$service->status()['connected'], 'Configured is not connected');
    parse_str((string)parse_url($service->connectUrl('admin'), PHP_URL_QUERY), $launch);
    $stored = $db->query('SELECT payload FROM etsy_oauth_states')->fetchColumn();
    etsyAssert(!str_contains($stored, 'verifier'), 'PKCE verifier must be encrypted');
    parse_str((string)parse_url($service->authorize($launch['ticket'], 'browser-one'), PHP_URL_QUERY), $auth);
    etsyAssert($auth['redirect_uri']===EtsyOAuthService::DEFAULT_CALLBACK, 'Exact fixed redirect');
    etsyAssert($auth['code_challenge_method']==='S256', 'PKCE S256');
    etsyAssert($auth['scope']==='shops_r listings_r listings_w', 'Only required scopes');
    etsyReject(fn()=>$service->authorize($launch['ticket'], 'browser-two'), 'Launch ticket replay allowed');
    etsyReject(fn()=>$service->handleCallback(['state'=>$auth['state'],'code'=>'code'], 'browser-two'), 'Wrong browser accepted');
    etsyAssert(count($calls)===0, 'Wrong browser must not contact Etsy');
    $result=$service->handleCallback(['state'=>$auth['state'],'code'=>'code'], 'browser-one');
    etsyAssert($result['shop_id']==='42', 'Shop identity');
    $verifier=$calls[0]['form']['code_verifier'];
    etsyAssert(rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=')===$auth['code_challenge'], 'Verifier matches challenge');
    etsyAssert($calls[1]['token']==='123.fake-access', 'Shop resolved using authorized token');
    etsyReject(fn()=>$service->handleCallback(['state'=>$auth['state'],'code'=>'code'], 'browser-one'), 'State replay allowed');
    $public=json_encode($service->status());
    foreach (['fake-access','fake-refresh','test-secret','test-key','code_verifier'] as $secret) etsyAssert(!str_contains($public,$secret), 'Status exposes secret');
    $stored=$db->query('SELECT payload FROM etsy_oauth_connection')->fetchColumn();
    etsyAssert(!str_contains($stored,'fake-access'), 'Tokens must be encrypted');
    $expired=json_decode($vault->decrypt($stored),true);
    $expired['expires_at']=time()-10;
    $db->prepare('UPDATE etsy_oauth_connection SET payload=?')->execute([$vault->encrypt(json_encode($expired))]);
    etsyAssert($service->status()['connected'], 'Expired token refresh failed');
    etsyAssert($calls[2]['form']['grant_type']==='refresh_token', 'Refresh grant');
    parse_str((string)parse_url($service->connectUrl('admin'),PHP_URL_QUERY),$launch);
    parse_str((string)parse_url($service->authorize($launch['ticket'],'browser-one'),PHP_URL_QUERY),$auth);
    etsyReject(fn()=>$service->handleCallback(['state'=>$auth['state'],'error'=>'access_denied'],'browser-one'), 'Denied authorization accepted');
    etsyAssert($service->status()['connected'], 'Denied reconnect erased existing connection');
    parse_str((string)parse_url($service->connectUrl('admin'),PHP_URL_QUERY),$launch);
    $db->exec('UPDATE etsy_oauth_states SET expires_at=1');
    etsyReject(fn()=>$service->authorize($launch['ticket'],'browser-one'), 'Expired launch accepted');
    $service->disconnect();
    etsyAssert(!$service->status()['connected'], 'Disconnect retains tokens');
    etsyAssert((int)$db->query('SELECT COUNT(*) FROM etsy_oauth_states')->fetchColumn()===0, 'Disconnect retains pending flows');
    file_put_contents($dir.'/credentials.json',json_encode(['keystring'=>'test','shared_secret'=>'test','redirect_uri'=>'http://evil.test/etsy-callback.php']));
    etsyReject(fn()=>$service->connectUrl('admin'),'Non-HTTPS callback accepted');
    echo "Etsy OAuth: state, browser binding, PKCE, encryption, replay, refresh, denial, expiry, disconnect PASS\n";
} finally {
    foreach (['credentials.json','vault.key'] as $file) if (is_file($dir.'/'.$file)) unlink($dir.'/'.$file);
    rmdir($dir);
}
