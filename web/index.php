<?php
/* CinePulse Web — single-file PHP replacement.
 * PHP 8.2+ recommended; cURL + OpenSSL required; mbstring recommended.
 * API contract: https://movieapi.zarhosting.dpdns.org/
 * Android reference: MovieApiService.kt, MovieRepository.kt, MainViewModel.kt.
 * Deploy as index.php. See CINEPULSE-README.md before public deployment.
 * UI: Netflix-style layout with Apple liquid-glass styling. Security env vars: CINEPULSE_SECRET, CINEPULSE_PRIVATE_DIR,
 * CINEPULSE_HEALTH_KEY (enables ?action=health&key=...), CINEPULSE_TRUST_PROXY=1 (behind Cloudflare/nginx).
 */
declare(strict_types=1);
ini_set('display_errors', '0');
const CP_API = 'https://movieapi.zarhosting.dpdns.org/api/public/v1';
const CP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const CP_REFERER = 'https://moviebox.ph/';
const CP_ORIGIN = 'https://moviebox.ph';
const CP_TOKEN_TTL = 21600;
function cpq(string $k, string $default = ''): string { $v = $_GET[$k] ?? $default; if (!is_string($v) || strlen($v) > 20000) throw new RuntimeException('Invalid parameter: '.$k, 400); return $v; }
function cpjson(array $data, int $code = 200): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function cpurl(array $p): string { return '?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986); }
function cpdir(): string {
    static $dir; if ($dir !== null) return $dir;
    $dir = getenv('CINEPULSE_PRIVATE_DIR') ?: sys_get_temp_dir().'/cinepulse-'.substr(hash('sha256', __FILE__), 0, 20);
    if (is_link($dir)) throw new RuntimeException('Private directory must not be a symlink', 500);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create private storage', 500);
    if (!is_writable($dir)) throw new RuntimeException('Private storage is not writable', 500);
    return $dir;
}
function cpsecret(): string {
    static $secret; if ($secret !== null) return $secret;
    $v = getenv('CINEPULSE_SECRET');
    if ($v !== false && $v !== '') { if (strlen($v) < 32) throw new RuntimeException('CINEPULSE_SECRET must have at least 32 random characters', 500); return $secret = $v; }
    $f = cpdir().'/relay.key';
    $h = @fopen($f, 'c+b'); if (!$h) throw new RuntimeException('Cannot open relay key', 500);
    @chmod($f, 0600); if (!flock($h, LOCK_EX)) { fclose($h); throw new RuntimeException('Cannot lock relay key', 500); }
    $v = stream_get_contents($h);
    if ($v === '') { $v = bin2hex(random_bytes(32)); rewind($h); if (fwrite($h, $v) !== strlen($v)) { flock($h, LOCK_UN); fclose($h); throw new RuntimeException('Cannot save relay key', 500); } fflush($h); }
    flock($h, LOCK_UN); fclose($h);
    if (!is_string($v) || strlen($v) < 32) throw new RuntimeException('Invalid relay key', 500);
    return $secret = $v;
}
function cpb64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function cpunb64(string $s): string { $v = base64_decode(strtr($s, '-_', '+/'), true); if ($v === false) throw new RuntimeException('Invalid token', 403); return $v; }
function cpallowed(string $url, bool $api = false): array {
    if (strlen($url) > 14000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new RuntimeException('Unsafe upstream URL', 502);
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || ($p['port'] ?? 443) !== 443) throw new RuntimeException('Only HTTPS upstream URLs on port 443 are allowed', 502);
    $host = strtolower($p['host'] ?? '');
    if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) || strpos($host, '..') !== false || filter_var($host, FILTER_VALIDATE_IP)) throw new RuntimeException('Invalid upstream hostname', 502);
    $allowed = $api ? ['movieapi.zarhosting.dpdns.org'] : array_merge(['hakunaymatata.com', 'aoneroom.com'], array_filter(array_map('trim', explode(',', getenv('CINEPULSE_CDN_HOSTS') ?: ''))));
    foreach ($allowed as $domain) { $domain = strtolower($domain); if ($host === $domain || (!$api && substr($host, -strlen('.'.$domain)) === '.'.$domain)) return [$host, $p]; }
    error_log('CinePulse: upstream host not allowed: '.$host.' (see CINEPULSE_CDN_HOSTS)'); throw new RuntimeException('Upstream host is not allowed', 502);
}
function cppublic(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (strpos($ip, ':') !== false) return (bool)preg_match('/^[23][0-9a-f]{3}:/i', $ip) && stripos($ip, '2001:db8:') !== 0;
    $a = array_map('intval', explode('.', $ip));
    return $a[0] > 0 && $a[0] < 224 && !($a[0] === 100 && $a[1] >= 64 && $a[1] <= 127) && !($a[0] === 169 && $a[1] === 254);
}
function cpresolve(string $host): string {
    static $cache = [];
    if (isset($cache[$host])) return $cache[$host];
    $records = @dns_get_record($host, DNS_A); $ips = [];
    foreach ($records ?: [] as $r) { $ip = $r['ip'] ?? ''; if ($ip !== '' && cppublic($ip)) $ips[] = $ip; }
    if (!$ips) { $ip = @gethostbyname($host); if ($ip !== $host && cppublic($ip)) $ips[] = $ip; }
    if (!$ips) throw new RuntimeException('Upstream DNS resolution failed', 502);
    return $cache[$host] = $ips[0];
}
function cpredirect(string $base, string $next): string {
    if (strpos($next, 'https://') === 0) return $next;
    $p = parse_url($base); $root = 'https://'.$p['host'];
    if (strpos($next, '//') === 0) return 'https:'.$next;
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $next)) throw new RuntimeException('Unsafe redirect scheme', 502);
    if (substr($next, 0, 1) === '?') return $root.($p['path'] ?? '/').$next;
    $path = substr($next, 0, 1) === '/' ? $next : preg_replace('#[^/]*$#', '', $p['path'] ?? '/').$next;
    $parts = explode('?', $path, 2); $out = [];
    foreach (explode('/', $parts[0]) as $part) { if ($part === '..') array_pop($out); elseif ($part !== '' && $part !== '.') $out[] = $part; }
    return $root.'/'.implode('/', $out).(isset($parts[1]) ? '?'.$parts[1] : '');
}
function cpscrub(string $m): string {
    $m = preg_replace('~https?://\S+~i', '[url]', $m);
    $m = preg_replace('~\b\d{1,3}(?:\.\d{1,3}){3}\b~', '[ip]', $m);
    $m = preg_replace('~\b(?:[a-z0-9-]+\.)+[a-z]{2,}\b~i', '[host]', $m);
    return substr((string)$m, 0, 300);
}
function cphttps(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }
function cpdirect(): bool { return in_array(getenv('CINEPULSE_PLAY_MODE') ?: 'proxy', ['auto', 'direct'], true); }
/** Random per-browser id (HttpOnly cookie). Relay tokens are bound to it, so a copied link is useless elsewhere. */
function cpclient(): string {
    static $id;
    if ($id !== null) return $id;
    $c = $_COOKIE['cp_c'] ?? '';
    if (!is_string($c) || !preg_match('/^[a-f0-9]{32}$/', $c)) {
        $c = bin2hex(random_bytes(16));
        if (!headers_sent()) setcookie('cp_c', $c, ['expires' => time() + 86400 * 90, 'path' => '/', 'secure' => cphttps(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['cp_c'] = $c;
    }
    return $id = $c;
}
function cpbind(): string { return substr(hash_hmac('sha256', 'client|' . cpclient(), cpsecret()), 0, 32); }
function cptoken(): string { return substr(hash_hmac('sha256', 'api|' . cpclient(), cpsecret()), 0, 32); }
function cpenckey(): string { return hash('sha256', 'enc|' . cpsecret(), true); }
/** AES-256-GCM token: the CDN URL is encrypted (invisible to the browser) and authenticated. */
function cpsign(string $url, string $kind): string {
    cpallowed($url);
    $pl = json_encode(['u' => $url, 'e' => time() + CP_TOKEN_TTL, 'k' => $kind, 'c' => cpbind()], JSON_UNESCAPED_SLASHES);
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($pl, 'aes-256-gcm', cpenckey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false || strlen($tag) !== 16) throw new RuntimeException('Cannot create relay token (OpenSSL AES-GCM required)', 500);
    return cpurl(['action' => $kind, 't' => cpb64($iv . $tag . $ct)]);
}
function cpverify(string $token, string $kind): string {
    $raw = cpunb64($token);
    if (strlen($raw) < 30) throw new RuntimeException('Invalid relay token', 403);
    $pl = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', cpenckey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($pl === false) throw new RuntimeException('Invalid relay token', 403);
    $d = json_decode($pl, true);
    if (!is_array($d) || ($d['k'] ?? '') !== $kind || !is_int($d['e'] ?? null) || !is_string($d['u'] ?? null) || !is_string($d['c'] ?? null)) throw new RuntimeException('Invalid relay token', 403);
    if ($d['e'] < time()) throw new RuntimeException('Relay token expired; reload the stream', 403);
    $c = $_COOKIE['cp_c'] ?? '';
    if ($c !== '' && !hash_equals(cpbind(), $d['c'])) throw new RuntimeException('Relay token is not valid for this browser; reload the page', 403);
    cpallowed($d['u']); return $d['u'];
}
function cpip(): string {
    if (getenv('CINEPULSE_TRUST_PROXY')) foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) { $v = trim(explode(',', $_SERVER[$h] ?? '')[0]); if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v; }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0');
}
function cprate(string $bucket, int $limit, int $window = 60): void {
    $f = cpdir() . '/rl-' . hash('sha256', $bucket . '|' . cpip() . '|' . intdiv(time(), $window)) . '.cnt';
    $h = @fopen($f, 'c+b'); if (!$h) return;
    flock($h, LOCK_EX); $n = (int)stream_get_contents($h) + 1; ftruncate($h, 0); rewind($h); fwrite($h, (string)$n); fflush($h); flock($h, LOCK_UN); fclose($h);
    if (mt_rand(1, 150) === 1) foreach (glob(cpdir() . '/rl-*.cnt') ?: [] as $old) if (time() - (int)@filemtime($old) > 300) @unlink($old);
    if ($n > $limit) { header('Retry-After: ' . $window); throw new RuntimeException('Too many requests; slow down', 429); }
}
/** Same-origin only, no scripted clients, no direct navigation to streams, per-IP rate limits. */
function cpguard(string $action): void {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strlen($ua) < 12 || preg_match('/curl|wget|python|urllib|httpclient|scrapy|libwww|go-http|java\/|aiohttp|axios|node-fetch|headless|phantom|selenium|puppeteer|playwright|postman|insomnia|spider|crawl/i', $ua)) throw new RuntimeException('Forbidden', 403);
    $site = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && $site !== 'same-origin' && !($site === 'none' && in_array($action, ['health', 'proxy', 'sub'], true))) throw new RuntimeException('Forbidden', 403);
    if ($site === '') {
        $ref = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '' && $ref !== 'null' && strtolower((string)parse_url($ref, PHP_URL_HOST)) !== strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''))) throw new RuntimeException('Forbidden', 403);
    }
    if (in_array(strtolower($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''), ['document', 'iframe', 'frame', 'embed', 'object'], true) && $action !== 'health') throw new RuntimeException('Forbidden', 403);
    if (in_array($action, ['suggest', 'feed', 'detailData', 'play', 'captions'], true) && !hash_equals(cptoken(), (string)($_SERVER['HTTP_X_CP_TOKEN'] ?? ''))) throw new RuntimeException('Forbidden', 403);
    if ($action === 'proxy') cprate('relay', 500); elseif ($action === 'play' || $action === 'captions') cprate('play', 40); else cprate('api', 150);
}
function cpheaders(): array { return ['Accept: */*', 'Accept-Encoding: identity', 'Referer: '.CP_REFERER, 'Origin: '.CP_ORIGIN]; }
/** Bounded buffered fetch, or incremental streaming. Redirect bodies never reach the browser. */
function cphttp(string $url, array $headers = [], bool $api = false, string $method = 'GET', ?callable $onHeaders = null, ?callable $onChunk = null, int $limit = 8388608): array {
    if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL extension is required', 500);
    $started=microtime(true);
    for ($hop=0; $hop<=4; $hop++) {
        $remaining=22-(int)(microtime(true)-$started);
        if (!$onChunk && $remaining<=0) throw new RuntimeException('Upstream redirect/fetch deadline exceeded',502);
        [$host] = cpallowed($url, $api); $ip = cpresolve($host); $pin = strpos($ip, ':') !== false ? '['.$ip.']' : $ip;
        $status = 0; $hs = []; $body = ''; $tooLarge = false; $headerDone = false; $forward = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION=>false, CURLOPT_RETURNTRANSFER=>false, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>$onChunk ? 0 : max(1,$remaining), CURLOPT_LOW_SPEED_LIMIT=>32, CURLOPT_LOW_SPEED_TIME=>30, CURLOPT_NOSIGNAL=>true, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROXY=>'', CURLOPT_RESOLVE=>[$host.':443:'.$pin], CURLOPT_ENCODING=>'', CURLOPT_HTTPHEADER=>$headers, CURLOPT_USERAGENT=>$api ? 'CinePulse-Web/2.0' : CP_UA, CURLOPT_NOBODY=>$method === 'HEAD', CURLOPT_BUFFERSIZE=>131072,
            CURLOPT_HEADERFUNCTION=>function($c, $line) use (&$status, &$hs, &$headerDone, &$forward, $onHeaders) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) { $status=(int)$m[1]; $hs=[]; $headerDone=false; }
                elseif (strpos($line, ':') !== false) { [$k,$v]=explode(':',$line,2); $hs[strtolower(trim($k))]=trim($v); }
                elseif (trim($line) === '' && $status >= 200) { $headerDone=true; if (!in_array($status,[301,302,303,307,308],true) && $onHeaders) $forward=(bool)$onHeaders($status,$hs); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>function($c, $chunk) use (&$body,&$status,&$tooLarge,&$forward,$onChunk,$limit) {
                $n=strlen($chunk); if (in_array($status,[301,302,303,307,308],true)) return $n;
                if ($onChunk) { if (!$forward) return $n; return $onChunk($chunk) ? $n : 0; }
                if (strlen($body)+$n>$limit) { $tooLarge=true; return 0; } $body.=$chunk; return $n;
            }
        ]);
        $ok=curl_exec($ch); $errno=curl_errno($ch); $error=curl_error($ch); curl_close($ch);
        if ($tooLarge) throw new RuntimeException('Upstream response exceeded size limit',502);
        if ($ok === false) { if ($onChunk && connection_aborted()) return ['status'=>$status,'headers'=>$hs,'body'=>'']; error_log('CinePulse upstream transport: '.$errno.' '.$error); throw new RuntimeException('Upstream transport error '.$errno,502); }
        if (!$headerDone) throw new RuntimeException('Empty upstream response',502);
        if (in_array($status,[301,302,303,307,308],true)) { if (empty($hs['location'])) throw new RuntimeException('Redirect missing Location',502); $url=cpredirect($url,$hs['location']); continue; }
        return ['status'=>$status,'headers'=>$hs,'body'=>$body];
    }
    throw new RuntimeException('Too many upstream redirects',502);
}
/** Remember every title the API has listed (slug -> id + metadata), so a title page can still be built if /detail is down. */
function cpindex(array $d): void {
    $items=[];
    foreach (['items','data','list'] as $k) if (isset($d[$k]) && is_array($d[$k])) $items=array_merge($items,$d[$k]);
    foreach (($d['sections']??[]) as $sec) if (is_array($sec) && is_array($sec['items']??null)) $items=array_merge($items,$sec['items']);
    $n=0;
    foreach ($items as $it) {
        if (!is_array($it) || ++$n>80) continue;
        $slug=(string)($it['slug']??''); $id=(string)($it['subject_id']??($it['subjectId']??''));
        if ($slug==='' || strlen($slug)>400 || !preg_match('/^[\pL\pN._~-]+$/u',$slug) || !preg_match('/^\d{1,25}$/',$id)) continue;
        $f=cpdir().'/ix-'.hash('sha256',$slug).'.json';
        if (is_file($f) && time()-(int)@filemtime($f)<86400) continue;
        $year=(string)($it['year']??''); if ($year==='' && !empty($it['release_date'])) $year=substr((string)$it['release_date'],0,4);
        $type=$it['type']??''; 
        @file_put_contents($f,json_encode(['id'=>$id,'slug'=>$slug,'title'=>(string)($it['name']??($it['title']??'')),'poster'=>(string)($it['poster_url']??($it['poster']??'')),'rating'=>(string)($it['rating']??''),'year'=>$year,'genre'=>(string)($it['genre']??''),'country'=>(string)($it['country']??''),'description'=>(string)($it['description']??''),'tv'=>$type==='tv'||$type==='tv-series'||$type===2],JSON_UNESCAPED_UNICODE),LOCK_EX);
    }
    if ($n>0 && mt_rand(1,200)===1) foreach (glob(cpdir().'/ix-*.json') ?: [] as $old) if (time()-(int)@filemtime($old)>2592000) @unlink($old);
}
function cpapi(string $path, array $query = [], int $ttl = 1800, int $stale = 0): array {
    $url=CP_API.$path.($query ? '?'.http_build_query($query,'','&',PHP_QUERY_RFC3986) : '');
    $cache=cpdir().'/'.hash('sha256',$url).'.json';
    if ($ttl > 0 && is_file($cache) && (time() - (int)@filemtime($cache)) < $ttl) {
        $d=json_decode((string)@file_get_contents($cache),true,512,JSON_BIGINT_AS_STRING);
        if (is_array($d) && !empty($d)) return $d;
    }
    $last=null;
    $attempts=3;
    for ($i=0;$i<$attempts;$i++) {
        try {
            $r=cphttp($url,['Accept: application/json','Accept-Encoding: identity'],true);
            $d=json_decode($r['body'],true,512,JSON_BIGINT_AS_STRING);
            if ($r['status']!==200 || !is_array($d) || isset($d['error'])) {
                $text=is_array($d) && is_string($d['error']??null) ? $d['error'] : 'Invalid upstream response';
                error_log('CinePulse API HTTP '.$r['status'].': '.$text); $last=new RuntimeException('API HTTP '.$r['status'],502);
                if ($r['status']>=400 && $r['status']<500 && $r['status']!==429) break;
            } else { if ($ttl) @file_put_contents($cache,json_encode($d),LOCK_EX); try { cpindex($d); } catch (Throwable $e) {} return $d; }
        } catch (RuntimeException $e) { $last=$e; }
        if ($i<$attempts-1) usleep(250000*($i+1));
    }
    // Serve an older copy rather than an error page when the upstream is failing.
    if ($stale>0 && is_file($cache) && (time() - (int)@filemtime($cache)) < $stale) {
        $d=json_decode((string)@file_get_contents($cache),true,512,JSON_BIGINT_AS_STRING);
        if (is_array($d) && !empty($d)) return $d;
    }
    throw $last ?? new RuntimeException('API request failed',502);
}
/** Normalised title record -> the same shape cpdetail() returns. */
function cpbuild(array $x, string $slug): array {
    $series=!empty($x['tv']);
    return ['id'=>(string)$x['id'],'slug'=>$slug,'title'=>(string)($x['title']!==''?$x['title']:'Unknown'),'poster'=>(string)($x['poster']??''),'rating'=>(string)($x['rating']??''),'year'=>(string)($x['year']??''),'genre'=>(string)($x['genre']??''),'country'=>(string)($x['country']??''),'description'=>(string)($x['description']??''),'type'=>$series?'tv':'movie','series'=>$series,'seasons'=>$series?[['se'=>1,'count'=>30,'guess'=>true]]:[],'stars'=>[],'still'=>'','trailer'=>'','trailerProxy'=>''];
}
function cpindexed(string $slug): ?array {
    $f=cpdir().'/ix-'.hash('sha256',$slug).'.json';
    if (!is_file($f)) return null;
    $x=json_decode((string)@file_get_contents($f),true);
    return is_array($x) && ($x['slug']??'')===$slug && preg_match('/^\d{1,25}$/',(string)($x['id']??'')) ? $x : null;
}
/** Some titles make the upstream /detail endpoint answer 502 while search and /stream work fine. Rebuild the title from listings/search. */
function cpfallback(string $slug): ?array {
    if ($x=cpindexed($slug)) return cpbuild($x,$slug);
    $title=trim(str_replace('-',' ',preg_replace('/-[A-Za-z0-9]{6,16}$/','',$slug)));
    if ($title==='') return null;
    $words=preg_split('/\s+/',$title); $queries=[$title]; if (count($words)>3) $queries[]=implode(' ',array_slice($words,0,3));
    foreach ($queries as $q) {
        for ($page=1;$page<=3;$page++) {
            try { $r=cpapi('/search',['q'=>substr($q,0,150),'page'=>$page],600,86400); } catch (RuntimeException $e) { break; }
            if ($x=cpindexed($slug)) return cpbuild($x,$slug);
            foreach (($r['items']??[]) as $it) if (is_array($it) && ($it['slug']??'')===$slug && preg_match('/^\d{1,25}$/',(string)($it['subject_id']??''))) {
                $type=$it['type']??'';
                return cpbuild(['id'=>(string)$it['subject_id'],'title'=>(string)($it['name']??''),'poster'=>(string)($it['poster_url']??''),'rating'=>(string)($it['rating']??''),'year'=>(string)($it['year']??substr((string)($it['release_date']??''),0,4)),'genre'=>(string)($it['genre']??''),'country'=>(string)($it['country']??''),'description'=>(string)($it['description']??''),'tv'=>$type==='tv'||$type==='tv-series'||$type===2],$slug);
            }
            if (empty($r['has_more'])) break;
        }
    }
    return null;
}
function cpdetail(string $slug): array {
    if ($slug==='' || strlen($slug)>400 || !preg_match('/^[\pL\pN._~-]+$/u',$slug)) throw new RuntimeException('Invalid title slug',400);
    $bad=cpdir().'/bad-'.hash('sha256',$slug).'.flag'; $r=null; $err=null;
    if (!(is_file($bad) && time()-(int)@filemtime($bad)<300)) {
        try { $r=cpapi('/detail/'.rawurlencode($slug),[],120,604800); @unlink($bad); }
        catch (RuntimeException $e) { $err=$e; @touch($bad); error_log('CinePulse detail failed for '.$slug.': '.$e->getMessage()); }
    }
    $d=is_array($r)?($r['data']??[]):[]; $s=is_array($d)?($d['subject']??[]):[];
    if (!$s || (isset($r['code']) && (int)$r['code']!==0)) {
        $fb=cpfallback($slug); if ($fb) return $fb;
        if ($err) throw $err;
        throw new RuntimeException('Title not found',404);
    }
    $seasons=[]; foreach (($d['resource']['seasons']??[]) as $season) { if (!is_array($season)) continue; $se=(int)($season['se']??0); $count=(int)($season['maxEp']??($season['max_ep']??($season['epCount']??($season['episodes']??0)))); if ($se>0) $seasons[$se]=$count>0 ? ['se'=>$se,'count'=>min(20000,$count)] : ['se'=>$se,'count'=>30,'guess'=>true]; }
    ksort($seasons); $series=(int)($s['subjectType']??0)===2 || ((int)($s['subjectType']??0)!==1 && count($seasons)>0);
    if ($series && !$seasons) $seasons[1]=['se'=>1,'count'=>30,'guess'=>true];
    $stars=[]; foreach (($d['stars']??[]) as $st) { if (!is_array($st)) continue; $av=(string)($st['avatarUrl']??($st['avatar']??($st['avatar_url']??($st['photo']??($st['image']??(is_array($st['cover']??null)?($st['cover']['url']??''):($st['cover']??'')))))));
        $stars[]=['name'=>(string)($st['name']??''),'avatar'=>$av,'role'=>(string)($st['character']??($st['role']??''))]; }
    $id=(string)($s['subjectId']??''); if (!preg_match('/^\d{1,25}$/',$id)) { $fb=cpfallback($slug); if ($fb) return $fb; throw new RuntimeException('API returned no valid subject ID',502); }
    $trailer=(string)($s['trailer']['videoAddress']['url']??''); $trailerProxy='';
    if ($trailer!=='') { try { $trailerProxy=cpsign($trailer,'proxy'); } catch (RuntimeException $e) { error_log('CinePulse trailer: '.$e->getMessage()); } }
    return ['id'=>$id,'slug'=>$slug,'title'=>(string)($s['title']??'Unknown'),'poster'=>(string)($s['cover']['url']??''),'rating'=>(string)($s['imdbRatingValue']??''),'year'=>substr((string)($s['releaseDate']??''),0,4),'genre'=>(string)($s['genre']??''),'country'=>(string)($s['countryName']??''),'description'=>(string)($s['description']??''),'type'=>$series?'tv':'movie','series'=>$series,'seasons'=>$series?array_values($seasons):[],'stars'=>array_slice($stars,0,24),'still'=>(string)($s['stills']['url']??''),'trailer'=>cpdirect()?$trailer:'','trailerProxy'=>$trailerProxy];
}
/** Installable-app icons. Resized from your logo file (GD) so Chrome always gets real 192/512 PNGs; mask=1 adds the safe-zone padding for maskable icons. */
function cpiconsrc(): string { foreach (['faviconandlogo.jpg','faviconandlogo.jpeg','faviconandlogo.png','favicon.png','favicon.jpg','favicon.jpeg'] as $n) { $f=__DIR__.'/'.$n; if (is_file($f)) return $f; } return ''; }
function cpicontype(): string {
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) return 'image/png';
    $f=cpiconsrc(); if ($f==='') return 'image/svg+xml';
    $i=@getimagesize($f); return is_array($i)&&!empty($i['mime'])?$i['mime']:'image/png';
}
function cpicon(int $size, bool $mask): void {
    if (!in_array($size,[48,64,96,120,128,144,152,167,180,192,256,384,512],true)) $size=192;
    $src=cpiconsrc(); header('Cache-Control: public, max-age=86400'); header_remove('Content-Security-Policy');
    $cache=cpdir().'/icon-'.hash('sha256',$src.'|'.($src!==''?(int)@filemtime($src):0).'|'.$size.'|'.(int)$mask).'.png';
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        if (!is_file($cache)) {
            $dst=imagecreatetruecolor($size,$size); imagealphablending($dst,false); imagesavealpha($dst,true);
            $black=imagecolorallocate($dst,0,0,0);
            $im=false; $info=$src!==''?@getimagesize($src):false;
            if (is_array($info)) { $t=$info[2]??0; $im=$t===IMAGETYPE_JPEG&&function_exists('imagecreatefromjpeg')?@imagecreatefromjpeg($src):($t===IMAGETYPE_PNG&&function_exists('imagecreatefrompng')?@imagecreatefrompng($src):($t===IMAGETYPE_WEBP&&function_exists('imagecreatefromwebp')?@imagecreatefromwebp($src):false)); }
            if ($im) {
                $w=imagesx($im); $h=imagesy($im); $side=min($w,$h); $sx=intdiv($w-$side,2); $sy=intdiv($h-$side,2);
                if ($mask) { imagefilledrectangle($dst,0,0,$size,$size,$black); $in=(int)round($size*0.7); $off=intdiv($size-$in,2); imagealphablending($dst,true); imagecopyresampled($dst,$im,$off,$off,$sx,$sy,$in,$in,$side,$side); }
                else { imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127)); imagecopyresampled($dst,$im,0,0,$sx,$sy,$size,$size,$side,$side); }
            } else {
                // No logo file found: draw the same white tile + black play triangle used by the in-app logo.
                $white=imagecolorallocate($dst,255,255,255); imagefilledrectangle($dst,0,0,$size,$size,$mask?$black:$white);
                if ($mask) imagefilledrectangle($dst,intdiv($size,6),intdiv($size,6),$size-intdiv($size,6),$size-intdiv($size,6),$white);
                $u=$size*($mask?0.6:1.0); $o=($size-$u)/2; imagefilledpolygon($dst,[(int)($o+$u*0.38),(int)($o+$u*0.28),(int)($o+$u*0.38),(int)($o+$u*0.72),(int)($o+$u*0.74),(int)($o+$u*0.5)],3,$black);
            }
            $tmp=$cache.'.'.bin2hex(random_bytes(4)); imagepng($dst,$tmp,6); @rename($tmp,$cache);
        }
        header('Content-Type: image/png'); header('Content-Length: '.(int)@filesize($cache)); readfile($cache); return;
    }
    if ($src!=='') { $i=@getimagesize($src); header('Content-Type: '.(is_array($i)&&!empty($i['mime'])?$i['mime']:'image/png')); readfile($src); return; }
    header('Content-Type: image/svg+xml'); echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" fill="#000"/><rect x="'.($mask?'86':'0').'" y="'.($mask?'86':'0').'" width="'.($mask?'340':'512').'" height="'.($mask?'340':'512').'" rx="'.($mask?'70':'110').'" fill="#fff"/><path d="M200 150v212l170-106z" fill="#000"/></svg>';
}
function cpselection(): array {
    $d=cpdetail(cpq('slug')); $given=cpq('id');
    if ($given!=='' && $given!==$d['id']) throw new RuntimeException('Title ID does not match this slug',400);
    $se=$d['series'] ? (int)cpq('se','1') : 0; $ep=$d['series'] ? (int)cpq('ep','1') : 0;
    if ($d['series']) { $valid=false; foreach ($d['seasons'] as $s) if ($s['se']===$se && $ep>=1 && ($ep<=$s['count'] || !empty($s['guess']))) $valid=true; if (!$valid) throw new RuntimeException('Episode is not listed for this title',404); }
    return [$d,$se,$ep];
}
function cpmismatch(array $r,int $se,int $ep): bool { $rs=(int)($r['se']??0); $re=(int)($r['ep']??0); return ($rs>0 && $rs!==$se) || ($re>0 && $re!==$ep); }
function cppairs(bool $series,int $se,int $ep): array { return $series ? [[$se,$ep]] : [[0,0],[1,1],[0,1],[1,0]]; }
function cpsources(array $r): array {
    $out=[]; $seen=[];
    foreach (($r['sources']??[]) as $s) {
        $url=$s['url']??''; if (!is_string($url) || $url==='' || isset($seen[$url])) continue;
        // Android's repository consumes sources[] (MP4), not hls[] / dash[].
        if (strtoupper((string)($s['format']??'MP4'))!=='MP4') continue;
        $proxy=cpsign($url,'proxy'); $seen[$url]=true;
        $out[]=['resolution'=>(string)($s['resolution']??'Auto'),'size'=>(string)($s['size']??''),'codec'=>(string)($s['codec']??''),'url'=>cpdirect()?$url:'','proxy'=>$proxy];
    }
    usort($out,function($a,$b){return (int)preg_replace('/\D/','',$b['resolution'])-(int)preg_replace('/\D/','',$a['resolution']);}); return $out;
}
function cpvtt(string $body): string {
    if (substr($body,0,2)==="\xFF\xFE" || substr($body,0,2)==="\xFE\xFF") { if (!function_exists('mb_convert_encoding')) throw new RuntimeException('Install mbstring for UTF-16 subtitles',500); $body=mb_convert_encoding($body,'UTF-8',substr($body,0,2)==="\xFF\xFE"?'UTF-16LE':'UTF-16BE'); }
    $body=preg_replace('/^\xEF\xBB\xBF/','',$body); $body=str_replace(["\r\n","\r"],"\n",$body);
    if (!preg_match('//u',$body)) { if (!function_exists('mb_convert_encoding')) throw new RuntimeException('Install mbstring for non-UTF-8 subtitles',500); $body=mb_convert_encoding($body,'UTF-8','Windows-1252'); }
    if (preg_match('/^\s*(?:<!doctype|<html|<\?xml)/i',$body)) throw new RuntimeException('Upstream returned a document instead of subtitles',502);
    if (strpos(ltrim($body),'WEBVTT')===0) return ltrim($body);
    $body=preg_replace('/(\d{1,2}:\d{2}:\d{2}),(\d{3})/','$1.$2',$body);
    if (strpos($body,'-->')===false && trim($body)!=='') throw new RuntimeException('Unsupported subtitle format; expected SRT or WebVTT',502);
    return "WEBVTT\n\n".trim($body)."\n";
}
function cprelay(): void {
    $url=cpverify(cpq('t'),'proxy'); $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','HEAD'],true)) { header('Allow: GET, HEAD'); throw new RuntimeException('Method not allowed',405); }
    $headers=cpheaders(); $range=trim($_SERVER['HTTP_RANGE']??'');
    if ($range!=='') { if (!preg_match('/^bytes=(?:\d+-\d*|-\d+)$/D',$range)) throw new RuntimeException('Only a single byte range is supported',400); $headers[]='Range: '.$range; }
    $ifRange=$_SERVER['HTTP_IF_RANGE']??''; if ($ifRange!=='' && !preg_match('/[\r\n]/',$ifRange) && strlen($ifRange)<200) $headers[]='If-Range: '.$ifRange;
    @set_time_limit(0); @ini_set('zlib.output_compression','0'); ignore_user_abort(false); if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    while (ob_get_level()>0) { if (!@ob_end_clean()) break; }
    $sent=false; $bad=''; $download=''; // web build never offers file downloads
    try {
        cphttp($url,$headers,false,$method,
            function($status,$hs) use (&$sent,&$bad,$download) {
                if (!in_array($status,[200,206,416],true)) { $bad='CDN HTTP '.$status; return false; }
                $ct=strtolower(explode(';',$hs['content-type']??'application/octet-stream')[0]);
                if ($status!==416 && !in_array($ct,['video/mp4','application/octet-stream','binary/octet-stream','video/x-m4v'],true)) { $bad='CDN returned '.$ct.' instead of MP4'; return false; }
                if (!empty($hs['content-encoding']) && strtolower($hs['content-encoding'])!=='identity') { $bad='CDN returned encoded bytes despite identity request'; return false; }
                if ($status===206 && !preg_match('/^bytes \d+-\d+\/(?:\d+|\*)$/',$hs['content-range']??'')) { $bad='CDN returned 206 without a valid Content-Range'; return false; }
                $sent=true; http_response_code($status); header('Content-Type: '.($status===416?'text/plain; charset=utf-8':'video/mp4'));
                header('Cache-Control: private, no-store'); header('X-Accel-Buffering: no'); header('X-Content-Type-Options: nosniff');
                foreach (['content-length','content-range','accept-ranges','etag','last-modified'] as $key) if (isset($hs[$key]) && !preg_match('/[\r\n]/',$hs[$key])) header($key.': '.$hs[$key]);
                if ($download!=='' && $status!==416) header('Content-Disposition: attachment; filename="'.$download.'"');
                return true;
            },
            function($chunk) { echo $chunk; flush(); return !connection_aborted(); }
        );
        if (!$sent) throw new RuntimeException($bad ?: 'No response from CDN',502);
    } catch (Throwable $e) { error_log('CinePulse relay: '.$e->getMessage()); if (!$sent && !headers_sent()) { http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); if ($method!=='HEAD') echo 'Stream relay failed. Refresh the link or inspect the server error log.'; } }
    exit;
}
// Tests may include the helpers without dispatching an HTTP request.
if (defined('CINEPULSE_HELPERS_ONLY')) return;
$CP_NONCE = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY'); header('Cache-Control: no-store');
header('Cross-Origin-Opener-Policy: same-origin'); header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'); header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$CP_NONCE'; style-src 'nonce-$CP_NONCE'; img-src 'self' data: https:; media-src 'self' blob:".(cpdirect()?' https:':'')."; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
if (cphttps()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
cpclient();
try {
    $action=cpq('action','home');
    if ($action==='icon') { cpicon((int)cpq('size','192'),cpq('mask','')==='1'); exit; }
    if ($action==='manifest' || $action==='sw') {
        $base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')),'/').'/';
        if ($action==='manifest') {
            header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: public, max-age=3600');
            $it=cpicontype(); $sz=$it==='image/svg+xml'?'any':null;
            echo json_encode([
                'id'=>$base,
                'name'=>'CinePulse',
                'short_name'=>'CinePulse',
                'description'=>'Stream movies, series and anime.',
                'start_url'=>$base,
                'scope'=>$base,
                'display'=>'standalone',
                'display_override'=>['standalone','minimal-ui'],
                'orientation'=>'any',
                'background_color'=>'#000000',
                'theme_color'=>'#000000',
                'lang'=>'en',
                'prefer_related_applications'=>false,
                'categories'=>['entertainment','video'],
                'icons'=>[
                    ['src'=>$base.'?action=icon&size=192','sizes'=>$sz?:'192x192','type'=>$it,'purpose'=>'any'],
                    ['src'=>$base.'?action=icon&size=512','sizes'=>$sz?:'512x512','type'=>$it,'purpose'=>'any'],
                    ['src'=>$base.'?action=icon&size=512&mask=1','sizes'=>$sz?:'512x512','type'=>$it,'purpose'=>'maskable']
                ],
                'shortcuts'=>[
                    ['name'=>'Movies','url'=>$base.'?action=browse&type=movies'],
                    ['name'=>'TV Series','url'=>$base.'?action=browse&type=tv-series'],
                    ['name'=>'Anime','url'=>$base.'?action=browse&type=animation'],
                    ['name'=>'My List','url'=>$base.'?action=library']
                ]
            ],JSON_UNESCAPED_SLASHES);
            exit;
        }
        header_remove('Content-Security-Policy'); header("Content-Security-Policy: default-src 'none'; connect-src 'self'");
        header('Content-Type: application/javascript; charset=utf-8'); header('Cache-Control: no-cache'); header('Service-Worker-Allowed: '.$base);
        echo <<<'JS'
const OFF='<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CinePulse</title><body style="margin:0;background:#000;color:#fff;font:16px system-ui;display:grid;place-items:center;min-height:100vh;text-align:center"><div><h1 style="letter-spacing:-.03em">You\'re offline</h1><p style="color:#999">Check your connection, then try again.</p><button onclick="location.reload()" style="margin-top:14px;padding:12px 26px;border-radius:99px;border:0;font-weight:700">Retry</button></div>';
self.addEventListener('install',e=>self.skipWaiting());
self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));
self.addEventListener('fetch',e=>{const r=e.request;if(r.mode==='navigate')e.respondWith(fetch(r).catch(()=>new Response(OFF,{status:503,headers:{'Content-Type':'text/html; charset=utf-8'}})))});
JS;
        exit;
    }
    if (in_array($action,['proxy','sub','health','suggest','feed','detailData','play','captions'],true)) cpguard($action);
    if ($action==='proxy') cprelay();
    if ($action==='sub') { $url=cpverify(cpq('t'),'sub'); $r=cphttp($url,cpheaders(),false,'GET',null,null,4194304); if ($r['status']!==200) throw new RuntimeException('Subtitle CDN HTTP '.$r['status'],502); $vtt=cpvtt($r['body']); header('Content-Type: text/vtt; charset=utf-8'); header('Cache-Control: private, max-age=300'); echo $vtt; exit; }
    if ($action==='health') { $hk=getenv('CINEPULSE_HEALTH_KEY'); if (!$hk || !hash_equals($hk,cpq('key'))) throw new RuntimeException('Not found',404); $result=['php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'mbstring'=>extension_loaded('mbstring'),'private_storage'=>is_writable(cpdir()),'mode'=>getenv('CINEPULSE_PLAY_MODE')?:'proxy']; cpsecret(); try { $result['api']=cpapi('/health'); } catch(Throwable $e) { $result['api']=['error'=>$e->getMessage()]; } cpjson($result); }
    if ($action==='suggest') { $q=substr(trim(cpq('q')),0,150); cpjson($q===''?['suggestions'=>[]]:cpapi('/search/suggest',['q'=>$q],60)); }
    if ($action==='feed') {
        $kind=cpq('kind','home'); if (!in_array($kind,['home','movies','tv-series','animation','search'],true)) throw new RuntimeException('Unknown feed',400);
        $params=[]; if ($kind!=='home') $params['page']=max(1,min(500,(int)cpq('page','1')));
        if ($kind==='search') { $params['q']=substr(trim(cpq('q')),0,150); if ($params['q']==='') throw new RuntimeException('Search query required',400); }
        elseif ($kind!=='home') { $sort=cpq('sort','foryou'); $params['sort']=in_array($sort,['foryou','hottest','latest','rating'],true)?$sort:'foryou'; foreach(['genre','country','year'] as $key) if (cpq($key)!=='') $params[$key]=substr(cpq($key),0,100); }
        cpjson(cpapi('/'.$kind,$params,1800,86400));
    }
    if ($action==='detailData') cpjson(cpdetail(cpq('slug')));
    if ($action==='play' || $action==='captions') {
        @set_time_limit(240); [$d,$se,$ep]=cpselection(); $pairs=cppairs($d['series'],$se,$ep); $lastError=''; $last=[];
        if ($action==='captions') {
            // The player passes the successful stream's exact pair for movies.
            if (!$d['series']) { $s=(int)cpq('used_se','0'); $e=(int)cpq('used_ep','0'); $pairs=in_array([$s,$e],$pairs,true)?[[$s,$e]]:[[0,0]]; }
            $caps=[]; foreach ($pairs as [$s,$e]) { try { $last=cpapi('/stream/'.$d['id'].'/captions',['detail_path'=>$d['slug'],'se'=>$s,'ep'=>$e],120); if ($d['series'] && cpmismatch($last,$se,$ep)) throw new RuntimeException('API returned subtitles for a different episode',502); foreach(($last['captions']??[]) as $c) if (!empty($c['url'])) $caps[]=['lan'=>(string)($c['lan']??''),'name'=>(string)($c['lanName']??$c['lan']??'Subtitle'),'url'=>cpsign((string)$c['url'],'sub')]; } catch(Throwable $ex) { $lastError=$ex->getMessage(); } }
            cpjson(['captions'=>$caps,'warning'=>$lastError?cpscrub($lastError):null]);
        }
        $sources=[]; $used=[$se,$ep];
        foreach($pairs as [$s,$e]) {
            try { $last=cpapi('/stream/'.$d['id'],['detail_path'=>$d['slug'],'se'=>$s,'ep'=>$e],45);
                if ($d['series'] && cpmismatch($last,$se,$ep)) { $lastError='API returned a different episode'; continue; }
                $sources=cpsources($last); if ($sources) { $used=[$s,$e]; break; }
            } catch(Throwable $ex) { $lastError=$ex->getMessage(); }
        }
        $mode=getenv('CINEPULSE_PLAY_MODE')?:'proxy'; if (!in_array($mode,['proxy','auto','direct'],true)) $mode='proxy';
        $note=is_string($last['note']??null)?cpscrub($last['note']):null;
        if (!$sources) $note=cpscrub($lastError?:($note?:'No playable MP4 resource for this exact selection. HLS/DASH-only resources are not supported by this MP4 port.'));
        cpjson(['has_resource'=>(bool)$sources,'sources'=>$sources,'used_se'=>$used[0],'used_ep'=>$used[1],'limited'=>(bool)($last['limited']??false),'note'=>$note,'mode'=>$mode],$sources?200:503);
    }
} catch(Throwable $e) { error_log('CinePulse: '.$e->getMessage()); $code=$e->getCode(); cpjson(['error'=>cpscrub($e->getMessage())],in_array($code,[400,403,404,405,429,500,502,503],true)?$code:500); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#000000">
<meta name="color-scheme" content="dark">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="CinePulse">
<meta name="description" content="CinePulse — stream movies, series and anime.">
<title>CinePulse</title>
<link rel="icon" href="/faviconandlogo.jpg" type="image/jpeg">
<link rel="apple-touch-icon" href="/faviconandlogo.jpg">
<link rel="manifest" href="?action=manifest">
<style nonce="<?= $CP_NONCE ?>">
:root{color-scheme:dark;--bg:#000;--fg:#fff;--fg2:rgba(255,255,255,.68);--fg3:rgba(255,255,255,.42);--line:rgba(255,255,255,.12);--pad:clamp(16px,4vw,60px);--cw:clamp(118px,13.2vw,198px);--gw:clamp(110px,14vw,196px);--ease:cubic-bezier(.22,.8,.24,1);--spring:cubic-bezier(.34,1.5,.64,1);--sk:1.5s;--font:-apple-system,BlinkMacSystemFont,"SF Pro Display","SF Pro Text",Inter,system-ui,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html{background:#000;scrollbar-width:none;-webkit-text-size-adjust:100%}
html::-webkit-scrollbar{display:none}
body{background:#000;color:var(--fg);font:400 15px/1.45 var(--font);-webkit-font-smoothing:antialiased;overflow-x:hidden;min-height:100vh;min-height:100dvh;overscroll-behavior-y:none}
body.noscroll{overflow:hidden}
a{color:inherit;text-decoration:none}
button{font:inherit;color:inherit;background:none;border:0;cursor:pointer;outline:0}
button:focus-visible,a:focus-visible,input:focus-visible{outline:2px solid #fff;outline-offset:3px}
img{display:block;max-width:100%}
svg.i{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:none;pointer-events:none}
svg.i.f{fill:currentColor;stroke:none}
.hide{display:none!important}

/* ───────── Liquid glass material ───────── */
.lg{position:relative;isolation:isolate;color:#fff;background:linear-gradient(180deg,rgba(255,255,255,.17),rgba(255,255,255,.055));-webkit-backdrop-filter:blur(20px) saturate(1.7);backdrop-filter:blur(20px) saturate(1.7);box-shadow:0 10px 32px rgba(0,0,0,.55),inset 0 1px 1px rgba(255,255,255,.5),inset 0 -8px 18px rgba(255,255,255,.05),inset 0 0 0 1px rgba(255,255,255,.14)}
.lg::after{content:"";position:absolute;inset:0;border-radius:inherit;pointer-events:none;background:radial-gradient(160px circle at var(--gx,25%) var(--gy,15%),rgba(255,255,255,.22),rgba(255,255,255,.04) 45%,transparent 70%)}
.lite .lg{-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);box-shadow:0 6px 18px rgba(0,0,0,.5),inset 0 1px 1px rgba(255,255,255,.35),inset 0 0 0 1px rgba(255,255,255,.12)}
.lite .lg::after{display:none}
.lg-solid{background:rgba(255,255,255,.94);color:#000;-webkit-backdrop-filter:none;backdrop-filter:none;box-shadow:0 10px 30px rgba(0,0,0,.5),inset 0 -2px 6px rgba(0,0,0,.12)}
.lg-solid::after{display:none}
.press{transition:transform .35s var(--spring),background .2s}
.press:active{transform:scale(.93)}
.ib{width:44px;height:44px;border-radius:50%;display:grid;place-items:center}
.ib svg.i{width:21px;height:21px}
.pill{border-radius:999px}

/* ───────── Nav ───────── */
#nav{position:fixed;inset:0 0 auto 0;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:calc(env(safe-area-inset-top,0px) + 14px) var(--pad) 22px;background:linear-gradient(180deg,rgba(0,0,0,.78),rgba(0,0,0,0));pointer-events:none}
#nav>*{pointer-events:auto}
.logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:21px;letter-spacing:-.03em;min-width:0}
.logo i{position:relative;width:32px;height:32px;border-radius:10px;background:#fff;display:grid;place-items:center;flex:none;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.5),inset 0 0 0 1px rgba(255,255,255,.25)}
.logo i::after{content:"";width:0;height:0;border-left:10px solid #000;border-top:6px solid transparent;border-bottom:6px solid transparent;margin-left:3px}
.logo i img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1}
#navPill{display:flex;gap:2px;padding:5px;border-radius:999px}
#navPill a{position:relative;z-index:1;padding:8px 17px;border-radius:999px;font-weight:600;font-size:14px;color:var(--fg2);transition:color .2s,background .25s}
#navPill a:hover{color:#fff}
#navPill a.on{color:#fff}
.nav-r{display:flex;gap:10px}
.nav-r .ib{position:relative}
.dot{position:absolute;top:9px;right:9px;width:8px;height:8px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(0,0,0,.6)}
#tabbar{display:none;position:fixed;z-index:50;left:50%;transform:translateX(-50%);bottom:calc(env(safe-area-inset-bottom,0px) + 12px);width:min(440px,calc(100% - 28px));padding:6px;border-radius:999px;justify-content:space-between}
#tabbar a{position:relative;z-index:1;flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:7px 0 6px;border-radius:999px;font-size:10.5px;font-weight:600;color:var(--fg2);transition:color .2s,background .25s}
#tabbar a svg.i{width:22px;height:22px}
#tabbar a.on{color:#fff}
@media (max-width:820px){#navPill{display:none}#tabbar{display:flex}main{padding-bottom:110px}}

/* ───────── Layout ───────── */
main{min-height:100vh;min-height:100dvh}
.view{animation:vin .45s var(--ease) both}
@keyframes vin{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.pagehead{padding:calc(env(safe-area-inset-top,0px) + 96px) var(--pad) 18px;display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap}
.pagehead h1{font-size:clamp(30px,5vw,48px);font-weight:800;letter-spacing:-.035em;line-height:1.05}
.pagehead p{color:var(--fg2);margin-top:4px}
.seg{display:inline-flex;padding:4px;gap:2px;border-radius:999px;max-width:100%;overflow-x:auto;scrollbar-width:none}
.seg::-webkit-scrollbar{display:none}
.seg button{position:relative;z-index:1;padding:7px 15px;border-radius:999px;font-size:13px;font-weight:600;color:var(--fg2);white-space:nowrap;transition:color .2s,background .25s}
.seg button.on{background:rgba(255,255,255,.92);color:#000}

/* ───────── Hero (edge to edge, never framed) ───────── */
.hero{position:relative;width:100%;height:clamp(520px,88vh,880px);height:clamp(520px,88dvh,880px);overflow:hidden;background:#000;contain:layout paint}
.hero-bg{position:absolute;inset:0}
.hero-bg img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:50% 22%;opacity:0;transition:opacity .9s ease}
.hero-bg img.on{opacity:1}
.hero-shade{position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.88) 0%,rgba(0,0,0,.55) 34%,rgba(0,0,0,0) 68%),linear-gradient(0deg,#000 0%,rgba(0,0,0,.65) 16%,rgba(0,0,0,0) 46%),linear-gradient(180deg,rgba(0,0,0,.55),rgba(0,0,0,0) 22%)}
.hero-copy{position:absolute;left:var(--pad);right:var(--pad);bottom:clamp(64px,13vh,150px);max-width:620px;transition:opacity .25s,transform .25s}
.hero-copy.sw{opacity:0;transform:translateY(6px)}
.eyebrow{font-size:12px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--fg2);margin-bottom:10px}
.hero h1{font-size:clamp(34px,6.2vw,76px);line-height:.98;font-weight:800;letter-spacing:-.045em;text-wrap:balance;text-shadow:0 4px 30px rgba(0,0,0,.5)}
.meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 12px;font-size:13px;font-weight:600;color:var(--fg2)}
.chip{padding:3px 9px;border-radius:7px;border:1px solid var(--line);font-size:12px;color:var(--fg);background:rgba(255,255,255,.07)}
.hero p{color:var(--fg2);font-size:clamp(14px,1.4vw,17px);line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;max-width:560px}
.acts{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;gap:8px;margin-top:20px;width:100%;max-width:100%}
.acts .btn{flex:1 1 0;min-width:0;justify-content:center;padding:0 8px;height:46px;font-size:14px;white-space:nowrap}
.acts .btn span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.acts .btn svg.i{width:18px;height:18px;flex-shrink:0}
@media(max-width:480px){
  .acts{gap:6px}
  .acts .btn{padding:0 6px;height:42px;font-size:12.5px;gap:5px}
  .acts .btn svg.i{width:16px;height:16px}
}
.btn{display:inline-flex;align-items:center;gap:9px;height:50px;padding:0 26px;border-radius:999px;font-weight:700;font-size:16px;white-space:nowrap}
.btn svg.i{width:20px;height:20px}
.btn.sm{height:44px;padding:0 20px;font-size:14.5px}
.dots{position:absolute;right:var(--pad);bottom:clamp(64px,13vh,150px);display:flex;gap:7px;padding:9px 11px;border-radius:999px}
.dots button{width:7px;height:7px;border-radius:99px;background:rgba(255,255,255,.4);transition:width .4s var(--ease),background .3s}
.dots button.on{width:24px;background:#fff}
@media (max-width:820px){.hero{height:clamp(480px,76dvh,720px)}.hero-shade{background:linear-gradient(0deg,#000 0%,rgba(0,0,0,.72) 24%,rgba(0,0,0,0) 62%),linear-gradient(180deg,rgba(0,0,0,.6),rgba(0,0,0,0) 22%)}.hero-copy{bottom:36px;max-width:none}.dots{display:none}.hero-bg img{object-position:50% 14%}}

/* ───────── Rails & cards ───────── */
.rails{position:relative;margin-top:-64px;z-index:2}
.rsec{content-visibility:auto;contain-intrinsic-size:auto 340px;margin-bottom:30px}
.rsec h2{font-size:clamp(18px,2vw,23px);font-weight:700;letter-spacing:-.02em;padding:0 var(--pad);margin-bottom:12px}
.rwrap{position:relative}
.rail{display:flex;gap:clamp(8px,1vw,14px);overflow-x:auto;padding:4px var(--pad) 8px;scroll-snap-type:x proximity;scroll-padding:0 var(--pad);scrollbar-width:none;overscroll-behavior-x:contain}
.rail::-webkit-scrollbar{display:none}
.rail .card{flex:0 0 var(--cw);scroll-snap-align:start}
.arrow{position:absolute;top:calc(50% - 40px);width:46px;height:46px;border-radius:50%;display:none;place-items:center;opacity:0;transition:opacity .25s,transform .3s var(--spring);z-index:3}
.arrow.l{left:12px}.arrow.r{right:12px}
@media (hover:hover){.arrow{display:grid}.rwrap:hover .arrow{opacity:1}.arrow:active{transform:scale(.9)}}
.card{display:block;min-width:0}
.ph{position:relative;aspect-ratio:2/3;border-radius:14px;overflow:hidden;background:#0e0e0f;box-shadow:inset 0 0 0 1px rgba(255,255,255,.07);transition:transform .45s var(--ease)}
.ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .4s}
.ph img.ld{opacity:1}
.ph::after{content:"";position:absolute;inset:0;border-radius:inherit;box-shadow:inset 0 1px 1px rgba(255,255,255,.28),inset 0 0 0 1px rgba(255,255,255,.1);pointer-events:none}
@media (hover:hover){.card:hover .ph{transform:scale(1.045)}}
.card:active .ph{transform:scale(.97)}
.bdg{position:absolute;left:8px;top:8px;z-index:1;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:700;background:rgba(0,0,0,.55);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
.lite .bdg{-webkit-backdrop-filter:none;backdrop-filter:none;background:rgba(0,0,0,.72)}
.card .t{margin-top:9px;font-size:13.5px;font-weight:600;line-height:1.25;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
.card .s{font-size:12px;color:var(--fg3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pbar{position:absolute;left:10px;right:10px;bottom:10px;height:4px;border-radius:9px;background:rgba(255,255,255,.28);overflow:hidden;z-index:1}
.pbar i{display:block;height:100%;width:100%;background:#fff;border-radius:9px;transform-origin:left;transform:scaleX(var(--p,0))}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(var(--gw),1fr));gap:22px clamp(8px,1.1vw,16px);padding:0 var(--pad) 40px}
.sentinel{height:80px;display:grid;place-items:center;color:var(--fg3);font-size:13px}
.empty{padding:90px var(--pad);text-align:center;color:var(--fg2);display:grid;gap:14px;justify-items:center}
.empty .ring{width:76px;height:76px;border-radius:50%;display:grid;place-items:center}
.empty .ring svg.i{width:32px;height:32px}
.empty h3{color:#fff;font-size:20px;letter-spacing:-.02em}

/* ───────── Skeletons (one per layout, identical geometry) ───────── */
.sk{position:relative;overflow:hidden;background:#101011;border-radius:12px}
.sk::after{content:"";position:absolute;inset:0;transform:translateX(-100%);background:linear-gradient(90deg,transparent,rgba(255,255,255,.075),transparent);animation:sk var(--sk) ease-in-out infinite}
@keyframes sk{to{transform:translateX(100%)}}
.sk.ln{height:14px;border-radius:8px}
.sk.pl{border-radius:999px}
.hero.skh .hero-copy{opacity:1}
.skc .ph{background:none;box-shadow:none}
.skc .ph.sk{border-radius:14px}
.skc .t.sk{height:13px;width:78%;margin-top:11px;border-radius:7px}
.skc .s.sk{height:10px;width:46%;margin-top:7px;border-radius:7px}
.skr h2.sk{height:20px;width:170px;margin:0 var(--pad) 14px;border-radius:8px}
.skd{position:relative}
.skd .backdrop{height:clamp(420px,70vh,760px)}
.skd .dbody{position:relative;margin-top:-200px;padding:0 var(--pad);display:grid;gap:14px;max-width:760px}
.skd .eps{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;padding:30px var(--pad)}
.skd .eps .sk{height:72px;border-radius:16px}
.skl{display:grid;gap:12px;padding:0 var(--pad)}
.skl .sk{height:92px;border-radius:20px}

/* ───────── Detail ───────── */
.backdrop{position:relative;height:clamp(420px,72vh,780px);overflow:hidden;background:#000}
.backdrop img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:50% 20%;opacity:0;transition:opacity .6s}
.backdrop img.ld{opacity:1}
.backdrop::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.85),rgba(0,0,0,.2) 62%,rgba(0,0,0,0)),linear-gradient(0deg,#000 2%,rgba(0,0,0,.4) 32%,rgba(0,0,0,0) 60%),linear-gradient(180deg,rgba(0,0,0,.6),rgba(0,0,0,0) 24%)}
.dbody{position:relative;z-index:2;margin-top:clamp(-300px,-34vh,-200px);padding:0 var(--pad)}
.dbody h1{font-size:clamp(32px,5.5vw,68px);font-weight:800;letter-spacing:-.045em;line-height:1;max-width:800px;text-wrap:balance}
.dbody .meta{margin:16px 0}
.dbody .ov{max-width:700px;color:var(--fg2);font-size:15.5px;line-height:1.6}
.dbody .acts{margin:24px 0 8px}
.dsec{padding:34px var(--pad) 0}
.dsec h2{font-size:21px;font-weight:700;letter-spacing:-.02em;margin-bottom:14px}
.dsec .seg{margin-bottom:16px}
.eps{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.ep{position:relative;display:flex;align-items:stretch;border-radius:16px;overflow:hidden;background:rgba(255,255,255,.06);box-shadow:inset 0 0 0 1px rgba(255,255,255,.09);transition:background .2s}
.ep:hover{background:rgba(255,255,255,.11)}
.ep .go{flex:1;display:flex;flex-direction:column;gap:2px;align-items:flex-start;padding:13px 14px;text-align:left;min-width:0}
.ep b{font-size:15px;letter-spacing:-.01em}
.ep span{font-size:11.5px;color:var(--fg3)}
.ep .dlb{width:42px;display:grid;place-items:center;color:var(--fg2);border-left:1px solid rgba(255,255,255,.07)}
.ep .dlb:hover{color:#fff;background:rgba(255,255,255,.08)}
.ep .dlb svg.i{width:18px;height:18px}
.ep .pbar{left:0;right:0;bottom:0;height:3px;border-radius:0}
.more{margin-top:12px}
.cast{display:flex;gap:18px;overflow-x:auto;scrollbar-width:none;padding-bottom:6px}
.cast::-webkit-scrollbar{display:none}
.cast div{flex:0 0 84px;text-align:center}
.cast .av{width:84px;height:84px;border-radius:50%;overflow:hidden;background:#101011;position:relative;box-shadow:inset 0 0 0 1px rgba(255,255,255,.1)}
.cast .av img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .4s}
.cast .av img.ld{opacity:1}
.cast p{font-size:12px;margin-top:8px;color:var(--fg2);line-height:1.25;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

/* ───────── Search ───────── */
#search{position:fixed;inset:0;z-index:70;display:flex;flex-direction:column;align-items:center;padding:calc(env(safe-area-inset-top,0px) + 18px) 16px 16px;background:rgba(0,0,0,.82);opacity:0;visibility:hidden;transition:opacity .25s,visibility 0s .25s}
#search.on{opacity:1;visibility:visible;transition:opacity .25s}
.sbar{width:min(680px,100%);display:flex;align-items:center;gap:10px;padding:6px 8px 6px 18px;border-radius:999px;height:56px;transform:translateY(-12px);transition:transform .45s var(--ease)}
#search.on .sbar{transform:none}
.sbar input{flex:1;min-width:0;background:none;border:0;color:#fff;font:500 17px var(--font);outline:0;height:100%}
.sbar input::placeholder{color:var(--fg3)}
.sbar input::-webkit-search-cancel-button{display:none}
.sugs{width:min(680px,100%);margin-top:12px;border-radius:26px;padding:8px;display:none}
.sugs.on{display:block}
.sugs button{display:flex;gap:12px;align-items:center;width:100%;text-align:left;padding:12px 14px;border-radius:18px;font-size:15.5px;font-weight:500}
.sugs button:hover,.sugs button.on{background:rgba(255,255,255,.12)}
.sugs svg.i{width:17px;height:17px;color:var(--fg3)}

/* ───────── Downloads ───────── */
.dls{display:grid;gap:12px;padding:0 var(--pad) 40px;max-width:980px}
.dl{display:flex;gap:14px;align-items:center;padding:12px;border-radius:24px;background:rgba(255,255,255,.055);box-shadow:inset 0 0 0 1px rgba(255,255,255,.09)}
.dl .th{flex:0 0 58px;aspect-ratio:2/3;border-radius:11px;overflow:hidden;position:relative;background:#101011}
.dl .th img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .4s}
.dl .th img.ld{opacity:1}
.dl .in{flex:1;min-width:0}
.dl .nm{font-weight:700;font-size:15.5px;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dl .sub{font-size:12.5px;color:var(--fg2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dl .bar{height:5px;border-radius:9px;background:rgba(255,255,255,.14);margin-top:10px;overflow:hidden}
.dl .bar i{display:block;height:100%;width:100%;background:#fff;border-radius:9px;transform-origin:left;transform:scaleX(var(--p,0))}
.dl .bt{display:flex;gap:8px;flex:none}
.stor{padding:0 var(--pad) 22px;max-width:980px}
.stor .bar{height:6px;border-radius:9px;background:rgba(255,255,255,.14);overflow:hidden;margin:8px 0 6px}
.stor .bar i{display:block;height:100%;width:100%;background:#fff;transform-origin:left;transform:scaleX(var(--p,0))}
.stor small{color:var(--fg3);font-size:12.5px}
.set{padding:10px var(--pad) 40px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;color:var(--fg2);font-size:13.5px}
@media (max-width:520px){.dl .bt .ib{width:40px;height:40px}.dl{padding:10px;gap:10px}}

/* ───────── Sheet (download quality / dev credit) ───────── */
#sheetWrap{position:fixed;inset:0;z-index:120;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(0,0,0,.72);opacity:0;visibility:hidden;transition:opacity .3s,visibility 0s .3s}
#sheetWrap.on{opacity:1;visibility:visible;transition:opacity .3s}
.sheet{width:min(420px,100%);border-radius:34px;padding:26px 22px 20px;transform:translateY(24px) scale(.96);transition:transform .5s var(--spring)}
#sheetWrap.on .sheet{transform:none}
.sheet h3{font-size:22px;font-weight:800;letter-spacing:-.03em;text-align:center}
.sheet p{color:var(--fg2);text-align:center;margin-top:8px;font-size:14.5px;line-height:1.5}
.sheet .by{display:block;text-align:center;font-size:19px;font-weight:700;margin-top:6px;text-decoration:underline;text-underline-offset:4px;text-decoration-color:rgba(255,255,255,.4)}
.sheet .mk{position:relative;width:64px;height:64px;border-radius:20px;background:#fff;margin:0 auto 14px;display:grid;place-items:center;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.5),inset 0 0 0 1px rgba(255,255,255,.3)}
.sheet .mk::after{content:"";border-left:18px solid #000;border-top:11px solid transparent;border-bottom:11px solid transparent;margin-left:5px}
.sheet .mk img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1}
.sheet .row{display:grid;gap:10px;margin-top:22px}
.sheet .row.two{grid-template-columns:1fr 1fr}
.sheet .btn{justify-content:center;width:100%;height:50px;padding:0 14px;font-size:15px}
.qopt{display:flex;justify-content:space-between;align-items:center;width:100%;padding:15px 18px;border-radius:20px;background:rgba(255,255,255,.08);font-weight:700;font-size:16px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.1);text-align:left}
.qopt:hover{background:rgba(255,255,255,.16)}
.qopt small{font-weight:500;color:var(--fg2);font-size:13px}

/* ───────── Toast ───────── */
#toast{position:fixed;z-index:200;left:50%;top:calc(env(safe-area-inset-top,0px) + 18px);transform:translate(-50%,-24px) scale(.94);padding:11px 20px;border-radius:999px;font-size:14px;font-weight:600;max-width:calc(100% - 32px);text-align:center;opacity:0;visibility:hidden;transition:transform .45s var(--spring),opacity .25s,visibility 0s .45s;pointer-events:none}
#toast.on{opacity:1;visibility:visible;transform:translate(-50%,0);transition:transform .45s var(--spring),opacity .25s}

/* ───────── Player: liquid glass drawn straight onto the video ───────── */
#player{position:fixed;inset:0;z-index:100;background:#000;color:#fff;visibility:hidden;opacity:0;transition:opacity .3s,visibility 0s .3s;overflow:hidden;user-select:none;-webkit-user-select:none;touch-action:manipulation}
#player.on{visibility:visible;opacity:1;transition:opacity .3s}
#player.nocur{cursor:none}
#vid{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;background:#000}
#vid::cue{background:rgba(0,0,0,.55);color:#fff;font:600 clamp(16px,2.3vw,30px)/1.3 var(--font);text-shadow:0 1px 6px #000}
#pTap{position:absolute;inset:0}
#pUI{position:absolute;inset:0;pointer-events:none;transition:opacity .35s ease,visibility 0s;display:flex;flex-direction:column;justify-content:space-between;padding:calc(env(safe-area-inset-top,0px) + 16px) clamp(14px,2.4vw,32px) calc(env(safe-area-inset-bottom,0px) + 18px)}
#pUI.h{opacity:0;visibility:hidden;transition:opacity .35s ease,visibility 0s .35s}
#pUI button,#pUI .lg,#pUI input{pointer-events:auto}
.p-top{display:flex;gap:10px;align-items:center}
.p-top .sp{flex:1}
#pTitle{padding:0 22px;height:44px;display:flex;align-items:center;gap:10px;max-width:min(46vw,520px);min-width:0}
#pTitle b{font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;letter-spacing:-.01em}
#pTitle span{font-size:12.5px;color:var(--fg2);white-space:nowrap}
#pTools{display:flex;align-items:center;padding:0 6px;height:50px;border-radius:999px;gap:2px}
#pTools .ib{width:40px;height:40px}
#pTools .ib.on{background:rgba(255,255,255,.22)}
#pVolW{display:flex;align-items:center}
#pVolR{width:0;opacity:0;transition:width .35s var(--ease),opacity .25s,margin .35s;height:4px;-webkit-appearance:none;appearance:none;background:rgba(255,255,255,.3);border-radius:9px;cursor:pointer;margin:0}
#pVolW:hover #pVolR,#pVolR:focus{width:78px;opacity:1;margin:0 10px 0 2px}
#pVolR::-webkit-slider-thumb{-webkit-appearance:none;width:14px;height:14px;border-radius:50%;background:#fff}
#pVolR::-moz-range-thumb{width:14px;height:14px;border:0;border-radius:50%;background:#fff}
@media (hover:none){#pVolW{display:none}}
.p-mid{position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);display:flex;justify-content:center;align-items:center;gap:clamp(18px,5vw,56px);pointer-events:none}
.p-mid .ib{width:clamp(54px,7vw,68px);height:clamp(54px,7vw,68px)}
.p-mid .ib svg.i{width:clamp(26px,3.4vw,32px);height:clamp(26px,3.4vw,32px)}
.p-mid .xl{width:clamp(76px,10vw,98px);height:clamp(76px,10vw,98px)}
.p-mid .xl svg.i{width:clamp(34px,4.6vw,44px);height:clamp(34px,4.6vw,44px)}
.p-bot{display:flex;flex-direction:column;align-items:stretch;gap:12px}
#pNext{align-self:flex-end;height:46px;padding:0 20px;display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px}
#pCap{width:min(780px,100%);align-self:center;height:58px;border-radius:999px;display:flex;align-items:center;gap:14px;padding:0 24px;font-variant-numeric:tabular-nums;font-size:13px;font-weight:600}
#pCap span{min-width:42px}
#pCap #pRem{text-align:right}
#pSeek{position:relative;flex:1;height:36px;display:flex;align-items:center;cursor:pointer;touch-action:none}
.trk{position:relative;width:100%;height:6px;border-radius:9px;background:rgba(255,255,255,.22);overflow:hidden;transition:height .2s cubic-bezier(0.16,1,0.3,1)}
#pSeek:hover .trk,#pSeek.d .trk{height:8px}
.trk i{position:absolute;top:0;bottom:0;left:0;border-radius:inherit}
#pBuf{background:rgba(255,255,255,.32);width:0%}
#pFill{background:#fff;width:0%;transition:width .1s linear}
#pHov{position:absolute;top:0;bottom:0;left:0;width:0%;background:rgba(255,255,255,.25);pointer-events:none;display:none;border-radius:inherit}
#pSeek:hover #pHov{display:block}
#pSeek.d #pFill{transition:none}
#pThumb{position:absolute;left:0%;top:50%;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.6);transform:translate(-50%,-50%) scale(0.8);transition:left .1s linear,transform .2s cubic-bezier(0.16,1,0.3,1);pointer-events:none}
#pSeek:hover #pThumb,#pSeek.d #pThumb{transform:translate(-50%,-50%) scale(1.25)}
#pSeek.d #pThumb{transition:none}
#pTip{position:absolute;bottom:30px;left:0%;padding:4px 10px;border-radius:99px;background:rgba(15,15,20,.85);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.15);font-size:12px;font-weight:700;color:#fff;opacity:0;transform:translateX(-50%);pointer-events:none;transition:opacity .15s}
#pSeek:hover #pTip,#pSeek.d #pTip{opacity:1}
#pMenu{position:absolute;z-index:5;top:calc(env(safe-area-inset-top,0px) + 76px);right:clamp(14px,2.4vw,32px);width:min(300px,calc(100% - 28px));max-height:min(60vh,420px);overflow-y:auto;border-radius:28px;padding:8px;opacity:0;visibility:hidden;transform:translateY(-8px) scale(.96);transform-origin:top right;transition:opacity .2s,transform .4s var(--spring),visibility 0s .4s;scrollbar-width:none}
#pMenu::-webkit-scrollbar{display:none}
#pMenu.on{opacity:1;visibility:visible;transform:none;transition:opacity .2s,transform .4s var(--spring)}
#pMenu h4{padding:10px 14px 6px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--fg3)}
#pMenu button{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;text-align:left;padding:12px 14px;border-radius:19px;font-size:15px;font-weight:600}
#pMenu button.on::after{content:"";display:inline-block;width:6px;height:12px;border:solid #fff;border-width:0 2.5px 2.5px 0;transform:rotate(45deg);margin-left:auto;margin-right:4px}
#pMenu .none{padding:14px;color:var(--fg2);font-size:14px}
@media (max-width:640px){#pMenu{top:auto;bottom:calc(env(safe-area-inset-bottom,0px) + 106px);right:50%;transform:translate(50%,12px) scale(.96);transform-origin:bottom center}#pMenu.on{transform:translate(50%,0)}#pTitle{display:none}.p-top .sp{display:block}#pTools{padding:0 4px}#pTools .ib{width:36px;height:36px}#pCap{height:54px;padding:0 18px;gap:10px}}
#pSpin{position:absolute;left:50%;top:50%;width:64px;height:64px;margin:-32px 0 0 -32px;border-radius:50%;opacity:0;pointer-events:none;transition:opacity .25s;border:3px solid rgba(255,255,255,.18);border-top-color:#fff;animation:spin .8s linear infinite;animation-play-state:paused}
#pSpin.on{opacity:1;animation-play-state:running}
@keyframes spin{to{transform:rotate(360deg)}}
#pFlash{position:absolute;top:50%;margin-top:-34px;width:68px;height:68px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:15px;opacity:0;pointer-events:none;transform:scale(.7);transition:opacity .35s,transform .4s var(--spring)}
#pFlash.on{opacity:1;transform:none;transition:opacity .08s,transform .3s var(--spring)}
#pErr{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(380px,calc(100% - 40px));padding:28px 24px 22px;border-radius:32px;text-align:center;display:none;z-index:4}
#pErr.on{display:block}
#pErr h3{font-size:19px;letter-spacing:-.02em}
#pErr p{color:var(--fg2);font-size:14px;margin:8px 0 18px}
#pErr .row{display:flex;gap:10px}
#pErr .btn{flex:1;justify-content:center;height:46px;padding:0}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}.sk::after{animation:none}}

.hsbg{position:absolute;inset:0;border-radius:0;background:#0b0b0c}
.skh .hero-copy{z-index:1}
.hs1{width:84px;height:12px;margin-bottom:6px}
.hs2{height:clamp(44px,6.4vw,76px);width:min(440px,84%);margin:12px 0 18px;border-radius:14px}
.hs3{width:min(280px,64%)}.hs4{width:min(540px,92%);margin-top:10px}
.hs5{width:132px;height:50px}
.skh .acts{margin-top:26px}
.skh .hs2,.skh .hs3,.skh .hs4,.skh .hs5,.skh .hs1{background:#1a1a1c}
.hh1{width:min(240px,60vw);height:44px;border-radius:12px}
.hh2{width:min(300px,80vw);height:42px}
.hd1{height:clamp(44px,6vw,70px);width:min(560px,86%);border-radius:14px}
.hd2{width:min(280px,70%)}.hd3{width:min(640px,94%)}.hd4{width:min(480px,76%)}
.skd .dbody{margin-top:clamp(-300px,-34vh,-200px)}

/* sliding glass lens (iOS 26 tab-bar style) */
.lens{position:absolute;z-index:0;top:5px;bottom:5px;left:0;width:0;border-radius:999px;pointer-events:none;opacity:0;background:linear-gradient(180deg,rgba(255,255,255,.34),rgba(255,255,255,.12));box-shadow:inset 0 1px 1px rgba(255,255,255,.7),inset 0 -3px 8px rgba(255,255,255,.12),inset 0 0 0 1px rgba(255,255,255,.2),0 6px 16px rgba(0,0,0,.45);-webkit-backdrop-filter:saturate(1.8) brightness(1.15);backdrop-filter:saturate(1.8) brightness(1.15);transition:transform .55s var(--spring),width .55s var(--spring),opacity .25s}
.lens.on{opacity:1}
.lite .lens{-webkit-backdrop-filter:none;backdrop-filter:none}
#tabbar{padding:5px}
#tabbar .lens{top:5px;bottom:5px}
#tabbar a{font-size:10px;padding:7px 0 6px;min-width:0}
#tabbar a svg.i{width:21px;height:21px}
/* richer glass rim */
.lg::before{content:"";position:absolute;inset:0;border-radius:inherit;padding:1px;pointer-events:none;background:linear-gradient(135deg,rgba(255,255,255,.75),rgba(255,255,255,.04) 38%,rgba(255,255,255,.04) 62%,rgba(255,255,255,.5));-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask:linear-gradient(#000 0 0) content-box exclude,linear-gradient(#000 0 0);opacity:.7}
.lite .lg::before{display:none}
.lg-solid::before{display:none}
.lg-solid{color:#000}
.lg-solid svg{color:#000}
/* mobile: no overlap between hero buttons and first rail */
@media (max-width:820px){.rails{margin-top:10px}.rsec:first-child{margin-top:6px}}
/* settings */
.sgroup{padding:0 var(--pad);max-width:860px;display:grid;gap:22px;margin-bottom:60px}
.sgroup h2{font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:var(--fg3);font-weight:700;margin:0 0 -10px 6px}
.panel{border-radius:26px;background:linear-gradient(180deg,rgba(255,255,255,.08) 0%,rgba(255,255,255,.03) 100%),#121214;box-shadow:inset 0 1px 0 rgba(255,255,255,.24),inset 0 0 0 1px rgba(255,255,255,.09),inset 0 -1px 0 rgba(0,0,0,.35),0 16px 36px -8px rgba(0,0,0,.6);overflow:hidden}
.srow{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;flex-wrap:wrap}
.srow+.srow{border-top:1px solid rgba(255,255,255,.07)}
.srow b{display:block;font-size:15.5px;letter-spacing:-.01em}
.srow small{display:block;color:var(--fg3);font-size:12.5px;margin-top:2px;max-width:420px}
.seg.flat{background:rgba(255,255,255,.08);box-shadow:inset 0 1px 0 rgba(255,255,255,.18),inset 0 0 0 1px rgba(255,255,255,.1)}
.seg.flat button.on{background:#fff;color:#000}
.sbtn{height:36px;padding:0 16px;border-radius:12px;background:rgba(255,255,255,.08);color:#fff;font-size:13px;font-weight:700;box-shadow:inset 0 1px 0 rgba(255,255,255,.22),inset 0 0 0 1px rgba(255,255,255,.08);transition:all .2s ease}
.sbtn:hover{background:rgba(255,255,255,.15);box-shadow:inset 0 1px 0 rgba(255,255,255,.32),inset 0 0 0 1px rgba(255,255,255,.14)}
.app{display:flex;gap:16px;align-items:center;padding:18px}
.app .mk2{flex:none;width:52px;height:52px;border-radius:16px;display:grid;place-items:center;background:rgba(255,255,255,.1)}
.app .mk2 svg.i{width:26px;height:26px}
.app div{flex:1;min-width:0}
.app b{display:block;font-size:16px}.app small{color:var(--fg2);font-size:13px;line-height:1.4}
@media (max-width:520px){.app{flex-wrap:wrap}.app .btn{width:100%;justify-content:center}}
.link{text-decoration:underline;text-underline-offset:3px;text-decoration-color:rgba(255,255,255,.4)}

/* mobile portrait: player docks to the top half, page continues below it */
body.docked #vMain{padding-top:calc(env(safe-area-inset-top,0px) + 56.25vw)}
body.docked .backdrop{display:none}
body.docked .dbody{margin-top:18px}
body.docked #toast{top:calc(env(safe-area-inset-top,0px) + 56.25vw + 12px)}
body.playing #toast{top:auto;bottom:110px;transform:translate(-50%,24px) scale(.94)}
body.playing #toast.on{transform:translate(-50%,0)}
#player.dock{inset:0 0 auto 0;height:calc(env(safe-area-inset-top,0px) + 56.25vw);overflow:visible;box-shadow:0 14px 34px rgba(0,0,0,.7)}
#player.dock #vid{inset:env(safe-area-inset-top,0px) 0 0 0;height:auto}
#player.dock #pUI{padding:calc(env(safe-area-inset-top,0px) + 8px) 8px 8px}
#player.dock #pBack{width:38px;height:38px}
#player.dock #pTools{height:42px}
#player.dock #pTools .ib{width:33px;height:33px}
#player.dock #pTools .ib svg.i{width:19px;height:19px}
#player.dock .p-mid{gap:16px}
#player.dock .p-mid .ib{width:44px;height:44px}
#player.dock .p-mid .ib svg.i{width:22px;height:22px}
#player.dock .p-mid .xl{width:58px;height:58px}
#player.dock .p-mid .xl svg.i{width:28px;height:28px}
#player.dock #pCap{height:40px;padding:0 14px;gap:8px;font-size:11px}
#player.dock #pNext{display:none!important}
#player.dock #pMenu{top:calc(100% + 10px);bottom:auto;right:12px;transform:translateY(-8px) scale(.96);transform-origin:top right;max-height:50vh}
#player.dock #pMenu.on{transform:none}
#player.dock #pErr{padding:18px 16px 14px;border-radius:26px}
#player.dock #pErr p{margin:6px 0 12px;font-size:13px}
#player.pfs{inset:0;height:auto}

/* ───────── final polish ───────── */
html,body{-webkit-user-select:none;user-select:none;-webkit-touch-callout:none}
input,textarea{-webkit-user-select:text;user-select:text}
img,video,a{-webkit-user-drag:none}
/* single-line action rows */
.acts{flex-wrap:nowrap;gap:10px}
.acts .btn{flex:0 1 auto;min-width:0}
.acts .btn span{overflow:hidden;text-overflow:ellipsis}
@media (max-width:520px){
  .acts{gap:8px}
  .acts .btn{flex:1 1 0;justify-content:center;height:46px;padding:0 8px;gap:6px;font-size:13.5px}
  .acts .btn svg.i{width:18px;height:18px}
}
@media (max-width:340px){.acts .btn{font-size:12.5px;gap:4px;padding:0 4px}}
/* install pill */
#btnInstall{height:44px;padding:0 16px;display:inline-flex;align-items:center;gap:7px;font-weight:700;font-size:13.5px}
#btnInstall.hide{display:none!important}
#btnInstall svg.i{width:18px;height:18px}
@media (max-width:820px){#btnInstall span{display:none}#btnInstall{width:44px;padding:0;justify-content:center}}
/* cast */
.cast{gap:16px}
.cast>div{flex:0 0 88px}
.cast .av{width:88px;height:88px;background:linear-gradient(160deg,#1c1c1e,#0b0b0c)}
.cast .av span{position:absolute;inset:0;display:grid;place-items:center;font-weight:800;font-size:24px;letter-spacing:-.02em;color:rgba(255,255,255,.5)}
.cast .av img{z-index:1}
.cast p em{display:block;font-style:normal;color:var(--fg3);font-size:11px;margin-top:1px}
/* remove from continue watching */
.cwx{position:absolute;top:7px;right:7px;z-index:2;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:rgba(0,0,0,.6);opacity:0;transition:opacity .2s,transform .3s var(--spring)}
.cwx svg.i{width:14px;height:14px}
.card:hover .cwx,.cwx:focus-visible{opacity:1}
@media (hover:none){.cwx{opacity:.9}}
.cwx:active{transform:scale(.85)}
.keys{line-height:2;max-width:none!important}
.keys b{color:#fff;font-weight:700;margin-right:2px}


</style>
</head>
<body>
<svg width="0" height="0" class="hide" aria-hidden="true"><defs>
<symbol id="i-play" viewBox="0 0 24 24"><path d="M7.5 4.6v14.8c0 .9 1 1.4 1.7.9l11-7.4c.6-.4.6-1.3 0-1.8l-11-7.4c-.7-.5-1.7 0-1.7.9z"/></symbol>
<symbol id="i-pause" viewBox="0 0 24 24"><rect x="5.5" y="4" width="4.6" height="16" rx="1.6"/><rect x="13.9" y="4" width="4.6" height="16" rx="1.6"/></symbol>
<symbol id="i-back" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
<symbol id="i-next" viewBox="0 0 24 24"><path d="M6 5l9 7-9 7z" fill="currentColor" stroke="none"/><path d="M18.5 5v14"/></symbol>
<symbol id="i-rew" viewBox="0 0 24 24"><path d="M4.5 12a8 8 0 1 0 2.6-5.9"/><path d="M4.5 3.8v4.6h4.6"/><text x="12" y="15.4" font-size="7.6" font-weight="800" text-anchor="middle" fill="currentColor" stroke="none">10</text></symbol>
<symbol id="i-fwd" viewBox="0 0 24 24"><path d="M19.5 12a8 8 0 1 1-2.6-5.9"/><path d="M19.5 3.8v4.6h-4.6"/><text x="12" y="15.4" font-size="7.6" font-weight="800" text-anchor="middle" fill="currentColor" stroke="none">10</text></symbol>
<symbol id="i-vol" viewBox="0 0 24 24"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5z" fill="currentColor"/><path d="M15.5 9a4.2 4.2 0 0 1 0 6M18 6.5a8 8 0 0 1 0 11"/></symbol>
<symbol id="i-mute" viewBox="0 0 24 24"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5z" fill="currentColor"/><path d="M16 9.5l5 5M21 9.5l-5 5"/></symbol>
<symbol id="i-cc" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="3.5"/><path d="M10.2 10.2a2.3 2.3 0 1 0 0 3.6M16.6 10.2a2.3 2.3 0 1 0 0 3.6"/></symbol>
<symbol id="i-speed" viewBox="0 0 24 24"><path d="M4.2 17a8.5 8.5 0 1 1 15.6 0"/><path d="M12 13.5l4-4.5"/><circle cx="12" cy="14" r="1.2" fill="currentColor"/></symbol>
<symbol id="i-qual" viewBox="0 0 24 24"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></symbol>
<symbol id="i-dl" viewBox="0 0 24 24"><path d="M12 4v11M7.5 11l4.5 4.5 4.5-4.5M5 19.5h14"/></symbol>
<symbol id="i-pip" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="3.5"/><rect x="12" y="11.5" width="6.5" height="4.5" rx="1.4" fill="currentColor" stroke="none"/></symbol>
<symbol id="i-fs" viewBox="0 0 24 24"><path d="M4 9V5.5A1.5 1.5 0 0 1 5.5 4H9M15 4h3.5A1.5 1.5 0 0 1 20 5.5V9M20 15v3.5a1.5 1.5 0 0 1-1.5 1.5H15M9 20H5.5A1.5 1.5 0 0 1 4 18.5V15"/></symbol>
<symbol id="i-fsx" viewBox="0 0 24 24"><path d="M9 4v3.5A1.5 1.5 0 0 1 7.5 9H4M20 9h-3.5A1.5 1.5 0 0 1 15 7.5V4M15 20v-3.5a1.5 1.5 0 0 1 1.5-1.5H20M4 15h3.5A1.5 1.5 0 0 1 9 16.5V20"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="M4 11l8-6.5 8 6.5V19a1 1 0 0 1-1 1h-4.5v-5.5h-5V20H5a1 1 0 0 1-1-1z"/></symbol>
<symbol id="i-film" viewBox="0 0 24 24"><rect x="3.5" y="4" width="17" height="16" rx="3"/><path d="M8 4v16M16 4v16M3.5 9h4.5M3.5 15h4.5M16 9h4.5M16 15h4.5"/></symbol>
<symbol id="i-tv" viewBox="0 0 24 24"><rect x="3" y="5.5" width="18" height="12.5" rx="3"/><path d="M8.5 21h7M9 5.5L7 2.5M15 5.5l2-3"/></symbol>
<symbol id="i-anime" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.6"/><path d="M7.9 10.6q1.4-1.8 2.8 0M13.3 10.6q1.4-1.8 2.8 0"/><path d="M8.6 14.1q3.4 3.6 6.8 0"/></symbol>
<symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.1"/><path d="M12 3.4l1.4 2.3 2.6-.7 1 2.5 2.5 1-.7 2.6 2.3 1.4-2.3 1.4.7 2.6-2.5 1-1 2.5-2.6-.7-1.4 2.3-1.4-2.3-2.6.7-1-2.5-2.5-1 .7-2.6-2.3-1.4 2.3-1.4-.7-2.6 2.5-1 1-2.5 2.6.7z"/></symbol>
<symbol id="i-list" viewBox="0 0 24 24"><path d="M6.5 4h11a1.5 1.5 0 0 1 1.5 1.5V21l-7-4.5L5 21V5.5A1.5 1.5 0 0 1 6.5 4z"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
<symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5"/><circle cx="12" cy="7.8" r=".6" fill="currentColor"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M4.5 7h15M10 7V4.5h4V7M6.5 7l.8 12a1.5 1.5 0 0 0 1.5 1.4h6.4a1.5 1.5 0 0 0 1.5-1.4l.8-12M10 11v6M14 11v6"/></symbol>
<symbol id="i-save" viewBox="0 0 24 24"><path d="M12 3.5v10M8 10l4 4 4-4"/><rect x="4" y="15" width="16" height="5" rx="2"/></symbol>
<symbol id="i-chev" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
</defs></svg>

<header id="nav">
  <a class="logo" href="?" data-r="home" aria-label="CinePulse home"><i><img src="/faviconandlogo.jpg" alt="" width="30" height="30"></i><span>CinePulse</span></a>
  <nav id="navPill" class="lg" aria-label="Main">
    <a href="?" data-r="home" data-nav="home">Home</a>
    <a href="?action=browse&amp;type=movies" data-r="browse" data-type="movies" data-nav="movies">Movies</a>
    <a href="?action=browse&amp;type=tv-series" data-r="browse" data-type="tv-series" data-nav="tv-series">Series</a>
    <a href="?action=browse&amp;type=animation" data-r="browse" data-type="animation" data-nav="animation">Anime</a>
    <a href="?action=library" data-r="library" data-nav="library">My List</a>
    <a href="?action=settings" data-r="settings" data-nav="settings">Settings</a>
  </nav>
  <div class="nav-r">
    <a class="lg ib press" id="btnRelease" href="https://github.com/ImAhmedZarir/cinepulse/releases/" target="_blank" rel="noopener noreferrer" aria-label="Download App Releases"><svg class="i"><use href="#i-dl"/></svg></a>
    <button class="lg pill press hide" id="btnInstall" aria-label="Install app"><span>Install App</span></button>
    <button class="lg ib press" id="btnSearch" aria-label="Search"><svg class="i"><use href="#i-search"/></svg></button>
  </div>
</header>

<nav id="tabbar" class="lg" aria-label="Tabs">
  <a href="?" data-r="home" data-nav="home"><svg class="i"><use href="#i-home"/></svg>Home</a>
  <a href="?action=browse&amp;type=movies" data-r="browse" data-type="movies" data-nav="movies"><svg class="i"><use href="#i-film"/></svg>Movies</a>
  <a href="?action=browse&amp;type=tv-series" data-r="browse" data-type="tv-series" data-nav="tv-series"><svg class="i"><use href="#i-tv"/></svg>Series</a>
  <a href="?action=browse&amp;type=animation" data-r="browse" data-type="animation" data-nav="animation"><svg class="i"><use href="#i-anime"/></svg>Anime</a>
  <a href="?action=library" data-r="library" data-nav="library"><svg class="i"><use href="#i-list"/></svg>My List</a>
  <a href="?action=settings" data-r="settings" data-nav="settings"><svg class="i"><use href="#i-gear"/></svg>Settings</a>
</nav>

<main id="app">
  <div id="vHome"></div>
  <div id="vMain"></div>
</main>

<div id="search" role="dialog" aria-label="Search">
  <div class="sbar lg">
    <svg class="i"><use href="#i-search"/></svg>
    <input id="sIn" type="search" placeholder="Movies, series, anime…" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
    <button class="ib press" id="sX" aria-label="Close"><svg class="i"><use href="#i-x"/></svg></button>
  </div>
  <div class="sugs lg" id="sSug"></div>
</div>

<div id="sheetWrap"><div class="sheet lg" id="sheet" role="dialog"></div></div>
<div id="toast" class="lg" role="status"></div>

<section id="player" aria-label="Player">
  <video id="vid" playsinline preload="auto" disableremoteplayback controlslist="nodownload noremoteplayback"></video>
  <div id="pTap"></div>
  <div id="pSpin"></div>
  <div id="pFlash" class="lg"></div>
  <div id="pUI" class="h">
    <div class="p-top">
      <button class="lg ib press" id="pBack" aria-label="Back"><svg class="i"><use href="#i-back"/></svg></button>
      <div class="lg pill" id="pTitle"><b></b><span></span></div>
      <div class="sp"></div>
      <div class="lg pill" id="pTools">
        <div id="pVolW"><input id="pVolR" type="range" min="0" max="1" step="0.02" value="1" aria-label="Volume"><button class="ib press" id="pVol" aria-label="Mute"><svg class="i"><use href="#i-vol"/></svg></button></div>
        <button class="ib press" id="pCc" aria-label="Subtitles"><svg class="i"><use href="#i-cc"/></svg></button>
        <button class="ib press" id="pSp" aria-label="Speed"><svg class="i"><use href="#i-speed"/></svg></button>
        <button class="ib press" id="pQ" aria-label="Quality"><svg class="i"><use href="#i-qual"/></svg></button>
        <button class="ib press" id="pDl" aria-label="Download"><svg class="i"><use href="#i-dl"/></svg></button>
        <button class="ib press" id="pPip" aria-label="Picture in picture"><svg class="i"><use href="#i-pip"/></svg></button>
        <button class="ib press" id="pFs" aria-label="Fullscreen"><svg class="i"><use href="#i-fs"/></svg></button>
      </div>
    </div>
    <div class="p-mid">
      <button class="lg ib press" id="pRew" aria-label="Back 10 seconds"><svg class="i"><use href="#i-rew"/></svg></button>
      <button class="lg ib xl press" id="pPlay" aria-label="Play"><svg class="i f"><use href="#i-play"/></svg></button>
      <button class="lg ib press" id="pFwd" aria-label="Forward 10 seconds"><svg class="i"><use href="#i-fwd"/></svg></button>
    </div>
    <div class="p-bot">
      <button class="lg pill press hide" id="pNext"><span>Next episode</span><svg class="i"><use href="#i-chev"/></svg></button>
      <div class="lg" id="pCap">
        <span id="pCur">0:00</span>
        <div id="pSeek" role="slider" aria-label="Seek" tabindex="0">
          <div class="trk"><i id="pBuf"></i><i id="pHov"></i><i id="pFill"></i></div>
          <div id="pThumb"></div><div id="pTip">0:00</div>
        </div>
        <span id="pRem">-0:00</span>
      </div>
    </div>
  </div>
  <div id="pMenu" class="lg" role="menu"></div>
  <div id="pErr" class="lg"><h3 id="pErrT">Can't play this</h3><p id="pErrM"></p><div class="row"><button class="btn sm lg-solid press" id="pRetry">Try again</button><button class="btn sm lg press" id="pErrBack">Go back</button></div></div>
</section>
<noscript>CinePulse needs JavaScript.</noscript>

<script nonce="<?= $CP_NONCE ?>">
/* liquid-glass.js — official Apple refraction engine */
(function (global) {
  "use strict";

  const SVG_NS = "http://www.w3.org/2000/svg";
  let uid = 0;
  let svgDefs = null;

  // Chromium can apply SVG filters via backdrop-filter; Safari and Firefox
  // silently no-op, so they get the frosted fallback instead.
  const supported = (() => {
    const ua = navigator.userAgent;
    const isSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg/.test(ua);
    const isFirefox = /Firefox/.test(ua);
    if (isSafari || isFirefox) return false;
    if (!CSS.supports("backdrop-filter", "url(#lg)")) return false;
    try {
      const c = document.createElement("canvas");
      c.width = c.height = 4;
      c.getContext("2d").getImageData(0, 0, 1, 1);
      return true;
    } catch (_) {
      return false;
    }
  })();

  function ensureDefs() {
    if (svgDefs) return svgDefs;
    const svg = document.createElementNS(SVG_NS, "svg");
    // width/height 0 keeps it renderable (display:none would break feImage)
    svg.setAttribute("width", "0");
    svg.setAttribute("height", "0");
    svg.setAttribute("aria-hidden", "true");
    svg.style.position = "absolute";
    svgDefs = document.createElementNS(SVG_NS, "defs");
    svg.appendChild(svgDefs);
    (document.body || document.documentElement).appendChild(svg);
    return svgDefs;
  }

  // Displacement map, gradient-difference method: a red left->right ramp
  // encodes X displacement, a blue top->bottom ramp encodes Y ("difference"
  // keeps both since the channels are disjoint). A blurred, inset 50%-gray
  // rounded rect neutralizes the interior, confining the refraction bulge to
  // an edge band whose curvature is set by the blur radius.
  function makeMap(w, h, radius, border, mapBlur) {
    const canvas = document.createElement("canvas");
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d");

    const gx = ctx.createLinearGradient(0, 0, w, 0);
    gx.addColorStop(0, "rgb(0,0,0)");
    gx.addColorStop(1, "rgb(255,0,0)");
    ctx.fillStyle = gx;
    ctx.fillRect(0, 0, w, h);

    const gy = ctx.createLinearGradient(0, 0, 0, h);
    gy.addColorStop(0, "rgb(0,0,0)");
    gy.addColorStop(1, "rgb(0,0,255)");
    ctx.globalCompositeOperation = "difference";
    ctx.fillStyle = gy;
    ctx.fillRect(0, 0, w, h);

    ctx.globalCompositeOperation = "source-over";
    const inset = border * Math.min(w, h);
    ctx.filter = "blur(" + mapBlur + "px)";
    ctx.fillStyle = "rgba(128,128,128,0.93)";
    ctx.beginPath();
    ctx.roundRect(inset, inset, w - inset * 2, h - inset * 2,
                  Math.max(radius - inset, 2));
    ctx.fill();
    ctx.filter = "none";
    return canvas.toDataURL();
  }

  // Three displacement passes at staggered scales (R strongest), channels
  // isolated with feColorMatrix and recombined with screen blends — the
  // faint prism fringe at the rim.
  function buildFilter(id, scales) {
    const filter = document.createElementNS(SVG_NS, "filter");
    filter.setAttribute("id", id);
    filter.setAttribute("x", "0");
    filter.setAttribute("y", "0");
    filter.setAttribute("width", "100%");
    filter.setAttribute("height", "100%");
    // Load-bearing: filters default to linearRGB, which re-maps the map's
    // neutral gray 128 to ~0.216 and injects a constant phantom displacement.
    filter.setAttribute("color-interpolation-filters", "sRGB");

    const feImage = document.createElementNS(SVG_NS, "feImage");
    feImage.setAttribute("x", "0");
    feImage.setAttribute("y", "0");
    feImage.setAttribute("result", "map");
    feImage.setAttribute("preserveAspectRatio", "none");
    filter.appendChild(feImage);

    const keep = [
      "1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0",
      "0 0 0 0 0  0 1 0 0 0  0 0 0 0 0  0 0 0 1 0",
      "0 0 0 0 0  0 0 0 0 0  0 0 1 0 0  0 0 0 1 0",
    ];
    const channels = [];
    for (let i = 0; i < 3; i++) {
      const disp = document.createElementNS(SVG_NS, "feDisplacementMap");
      disp.setAttribute("in", "SourceGraphic");
      disp.setAttribute("in2", "map");
      disp.setAttribute("scale", scales[i]);
      disp.setAttribute("xChannelSelector", "R");
      disp.setAttribute("yChannelSelector", "B");
      disp.setAttribute("result", "d" + i);
      filter.appendChild(disp);

      const cm = document.createElementNS(SVG_NS, "feColorMatrix");
      cm.setAttribute("in", "d" + i);
      cm.setAttribute("type", "matrix");
      cm.setAttribute("values", keep[i]);
      cm.setAttribute("result", "c" + i);
      filter.appendChild(cm);
      channels.push("c" + i);
    }

    const blend1 = document.createElementNS(SVG_NS, "feBlend");
    blend1.setAttribute("in", channels[0]);
    blend1.setAttribute("in2", channels[1]);
    blend1.setAttribute("mode", "screen");
    blend1.setAttribute("result", "c01");
    filter.appendChild(blend1);

    const blend2 = document.createElementNS(SVG_NS, "feBlend");
    blend2.setAttribute("in", "c01");
    blend2.setAttribute("in2", channels[2]);
    blend2.setAttribute("mode", "screen");
    filter.appendChild(blend2);

    ensureDefs().appendChild(filter);
    return { filter, feImage };
  }

  function resolveRadius(el, w, h, override) {
    if (override != null) return override;
    const raw = getComputedStyle(el).borderTopLeftRadius || "0px";
    const v = parseFloat(raw) || 0;
    return raw.trim().endsWith("%") ? (v / 100) * Math.min(w, h) : v;
  }

  /**
   * Apply liquid glass to an element.
   *
   * @param {Element} el
   * @param {Object} [opts]
   * @param {number} [opts.scale=-112]     Displacement strength (negative = magnifying bulge).
   * @param {number} [opts.chroma=6]       Per-channel scale stagger; 0 disables the prism fringe.
   * @param {number} [opts.border=0.07]    Neutral inset as a fraction of the smaller side.
   * @param {number} [opts.mapBlur=12]     Edge-curvature softness (px) of the map's gray inset.
   * @param {number} [opts.blur=3]         Backdrop blur (px) behind the glass interior.
   * @param {number} [opts.saturate=1.5]   Backdrop saturation boost.
   * @param {number} [opts.radius]         Corner radius override (px); default reads border-radius.
   * @param {number} [opts.fallbackBlur=16] Frosted blur (px) where refraction is unsupported.
   * @returns {{supported: boolean, refresh: Function, destroy: Function}}
   */
  function liquidGlass(el, opts) {
    const o = Object.assign(
      { scale: -112, chroma: 6, border: 0.07, mapBlur: 12,
        blur: 3, saturate: 1.5, radius: null, fallbackBlur: 16 },
      opts
    );

    if (!supported) {
      const frosted = "blur(" + o.fallbackBlur + "px) saturate(" + o.saturate + ")";
      el.style.backdropFilter = frosted;
      el.style.webkitBackdropFilter = frosted;
      el.classList.add("lg-fallback");
      return { supported: false, refresh: function () {}, destroy: function () {
        el.style.backdropFilter = "";
        el.style.webkitBackdropFilter = "";
        el.classList.remove("lg-fallback");
      } };
    }

    const id = "lg-filter-" + (++uid);
    const scales = [o.scale, o.scale + o.chroma, o.scale + 2 * o.chroma];
    const parts = buildFilter(id, scales);

    function refresh() {
      const w = el.offsetWidth;
      const h = el.offsetHeight;
      if (!w || !h) return;
      const radius = resolveRadius(el, w, h, o.radius);
      parts.feImage.setAttribute("href", makeMap(w, h, radius, o.border, o.mapBlur));
      parts.feImage.setAttribute("width", w);
      parts.feImage.setAttribute("height", h);
    }

    refresh();
    el.style.backdropFilter =
      "url(#" + id + ") blur(" + o.blur + "px) saturate(" + o.saturate + ")";

    let timer = null;
    const ro = new ResizeObserver(function () {
      clearTimeout(timer);
      timer = setTimeout(refresh, 120);
    });
    ro.observe(el);

    return {
      supported: true,
      refresh: refresh,
      destroy: function () {
        ro.disconnect();
        clearTimeout(timer);
        parts.filter.remove();
        el.style.backdropFilter = "";
      },
    };
  }

  global.liquidGlass = liquidGlass;
})(window);
</script>
<script nonce="<?= $CP_NONCE ?>">
var CPT=<?= json_encode(cptoken()) ?>;
(function(){
'use strict';
/* ═════════ CinePulse front-end ═════════ */
var $=function(s,r){return(r||document).querySelector(s)};
var $$=function(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))};
var LS={get:function(k,d){try{var v=localStorage.getItem(k);return v===null?d:JSON.parse(v)}catch(e){return d}},set:function(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}},del:function(k){try{localStorage.removeItem(k)}catch(e){}}};
function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function fmtT(s){s=Math.max(0,Math.floor(s||0));var h=(s/3600)|0,m=((s%3600)/60)|0,x=s%60;return h?h+':'+(m<10?'0':'')+m+':'+(x<10?'0':'')+x:m+':'+(x<10?'0':'')+x}
function fmtB(n){n=+n;if(!n||n<0)return'';var u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<4){n/=1024;i++}return(n>=100||i===0?n.toFixed(0):n.toFixed(1))+' '+u[i]}
function icon(n,c){return'<svg class="i'+(c?' '+c:'')+'"><use href="#i-'+n+'"/></svg>'}
function starIcon(){return'<svg class="i" style="width:11px;height:11px;display:inline-block;vertical-align:-1px;margin-right:3px" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="currentColor"/></svg>'}
function set(el,v){el.style.setProperty('--p',v)}

/* Glass: real refraction on Chromium, frosted fallback elsewhere, plain CSS blur in Lite mode */
var SET=Object.assign({autonext:true,heroauto:true,cue:'1'},LS.get('cp:set',{}));
function saveSet(){LS.set('cp:set',SET)}
var APK='https://github.com/ImAhmedZarir/cinepulse/releases/';
document.addEventListener('error',function(e){var t=e.target;if(t&&t.tagName==='IMG'&&(t.getAttribute('src')==='/faviconandlogo.jpg'||(t.parentNode&&t.parentNode.classList.contains('av'))))t.style.display='none'},true);
var nv=navigator,pref=LS.get('cp:glass','auto');
var LITE=pref==='lite'||(pref==='auto'&&((nv.deviceMemory&&nv.deviceMemory<=2)||(nv.hardwareConcurrency&&nv.hardwareConcurrency<=2)||(nv.connection&&nv.connection.saveData)));
document.documentElement.classList.toggle('lite',LITE);
var GS={scale:-92,chroma:5,border:.13,mapBlur:6,blur:2,saturate:1.6},GL={scale:-124,chroma:7,border:.07,mapBlur:12,blur:3,saturate:1.6};
function glass(el,big){if(!el||LITE||!window.liquidGlass||el._lg)return;try{el._lg=window.liquidGlass(el,big?GL:GS)}catch(e){}}
function unglass(el){if(el&&el._lg){el._lg.destroy();el._lg=null}}

/* Toast */
var toastEl=$('#toast'),toastT=0;
function toast(m,ms){toastEl.textContent=m;glass(toastEl);toastEl.classList.add('on');clearTimeout(toastT);toastT=setTimeout(function(){toastEl.classList.remove('on')},ms||2600)}

/* Data */
function norm(r){
  if(!r)return null;var st=r.stills&&r.stills.url;
  return{title:r.name||r.title||'Untitled',poster:r.poster_url||r.poster||(r.cover&&r.cover.url)||(typeof r.cover==='string'?r.cover:'')||'',
    still:r.still||r.banner_url||r.backdrop_url||st||(r.banner&&r.banner.url)||(typeof r.banner==='string'?r.banner:'')||'',
    slug:r.slug||'',rating:r.rating||r.imdbRatingValue||'',year:r.year||(r.releaseDate?String(r.releaseDate).slice(0,4):''),genre:r.genre||'',
    type:r.type||(r.series?'tv':'movie'),description:r.description||''};
}
function normList(a){return(Array.isArray(a)?a:[]).map(norm).filter(function(x){return x&&x.slug})}
function parseHome(res){
  var hero=[],rails=[];
  if(res&&Array.isArray(res.sections)){
    res.sections.forEach(function(s){var it=normList(s.items);if(!it.length)return;
      if(String(s.section||'').toLowerCase()==='banner')hero=it;else rails.push({label:s.section||'Featured',items:it.slice(0,24)})});
    if(!hero.length&&rails.length)hero=rails[0].items.slice(0,8);
  }else if(res){var all=normList(res.items||res.data||res.list);hero=all.slice(0,8);rails=[{label:'Trending Now',items:all.slice(0,24)}]}
  return{hero:hero.slice(0,8),rails:rails};
}
var inflight={},mem={};
function api(params,ttl){
  var k=new URLSearchParams(params).toString();
  if(ttl){
    if(mem[k]&&Date.now()-mem[k].t<ttl)return Promise.resolve(mem[k].d);
    try{var s=sessionStorage.getItem('cp:api:'+k);if(s){var p=JSON.parse(s);if(p&&Date.now()-p.t<ttl){mem[k]=p;return Promise.resolve(p.d)}}}catch(_){}
  }
  if(ttl&&inflight[k])return inflight[k];
  var p=fetch('?'+k,{headers:{'X-Requested-With':'XMLHttpRequest','X-CP-Token':CPT},credentials:'same-origin'}).then(function(r){
    return r.json().catch(function(){return{}}).then(function(j){if(!r.ok)throw new Error(j.error||j.note||('HTTP '+r.status));return j})});
  if(!ttl)return p;
  inflight[k]=p;
  p.then(function(d){
    var e={t:Date.now(),d:d};mem[k]=e;
    try{sessionStorage.setItem('cp:api:'+k,JSON.stringify(e))}catch(_){}
    var ks=Object.keys(mem);if(ks.length>80)delete mem[ks[0]];delete inflight[k];
  },function(){delete inflight[k]});
  return p;
}
function detail(slug){return api({action:'detailData',slug:slug},180000)}

/* Images: only what is near the viewport is held in memory */
var io=window.IntersectionObserver?new IntersectionObserver(function(es){es.forEach(function(e){
  var i=e.target;
  if(e.isIntersecting){var s=i.getAttribute('data-src');if(s&&i.getAttribute('src')!==s){i.src=s;io.unobserve(i);}}
})},{rootMargin:'600px 800px'}):null;
function lazy(root){$$('img[data-src]',root).forEach(function(i){if(io)io.observe(i);else i.src=i.getAttribute('data-src')})}
function unlazy(root){if(io)$$('img[data-src]',root).forEach(function(i){io.unobserve(i)})}
document.addEventListener('load',function(e){if(e.target.tagName==='IMG')e.target.classList.add('ld')},true);

/* Cards */
function card(it,extra){
  return'<a class="card" href="?action=detail&amp;slug='+encodeURIComponent(it.slug)+'" data-r="detail" data-slug="'+esc(it.slug)+'"><div class="ph"><img alt="" decoding="async" data-src="'+esc(it.poster)+'">'+
    (it.rating?'<span class="bdg">'+starIcon()+esc(it.rating)+'</span>':'')+(extra||'')+'</div><div class="t">'+esc(it.title)+'</div><div class="s">'+esc([it.year,it.type==='tv'?'Series':'Movie'].filter(Boolean).join(' · '))+'</div></a>';
}
/* Skeletons — each page gets a skeleton with the exact same geometry as its real content */
function skCards(n,cls){var h='';for(var i=0;i<n;i++)h+='<div class="card skc"><div class="ph sk"></div><div class="t sk"></div><div class="s sk"></div></div>';return cls?'<div class="'+cls+'">'+h+'</div>':h}
function skRail(){return'<section class="rsec skr"><h2 class="sk"></h2><div class="rail">'+skCards(10)+'</div></section>'}
function skHome(){return'<section class="hero skh"><div class="sk hsbg"></div><div class="hero-copy"><div class="sk ln hs1"></div><div class="sk hs2"></div><div class="sk ln hs3"></div><div class="sk ln hs4"></div><div class="acts"><div class="sk pl hs5"></div><div class="sk pl hs5"></div></div></div></section><div class="rails">'+skRail()+skRail()+skRail()+'</div>'}
function skHead(){return'<div class="pagehead"><div><div class="sk hh1"></div></div><div class="sk pl hh2"></div></div>'}
function skGrid(n){return skHead()+skCards(n||18,'grid')}
function skDetail(){return'<div class="skd"><div class="backdrop sk"></div><div class="dbody"><div class="sk hd1"></div><div class="sk ln hd2"></div><div class="sk ln hd3"></div><div class="sk ln hd4"></div><div class="acts"><div class="sk pl hs5"></div><div class="sk pl hs5"></div></div></div><div class="eps">'+Array(8+1).join('<div class="sk"></div>')+'</div></div>'}
function skList(){return skHead()+'<div class="skl">'+Array(5).join('<div class="sk"></div>')+'</div>'}


/* ═════════ Protection: no context menu, drag, selection or view-source shortcuts ═════════ */
document.addEventListener('contextmenu',function(e){e.preventDefault()});
document.addEventListener('dragstart',function(e){e.preventDefault()});
document.addEventListener('selectstart',function(e){var t=e.target;if(!(t&&t.closest&&t.closest('input,textarea')))e.preventDefault()});
document.addEventListener('keydown',function(e){
  var k=(e.key||'').toLowerCase(),c=e.ctrlKey||e.metaKey;
  if(k==='f12'||(c&&e.shiftKey&&'ijcks'.indexOf(k)>-1)||(c&&'usp'.indexOf(k)>-1)||(e.metaKey&&e.altKey&&'iju'.indexOf(k)>-1)){e.preventDefault();e.stopPropagation()}
},true);

/* ═════════ Liquid-glass specular glare tracking ═════════ */
document.addEventListener('pointermove',function(e){
  var g=e.target&&e.target.closest&&e.target.closest('.lg, .glass, .panel, #navPill, #tabbar, #pCap, .sheet');
  if(g){
    var r=g.getBoundingClientRect();
    g.style.setProperty('--gx',((e.clientX-r.left)/r.width*100).toFixed(1)+'%');
    g.style.setProperty('--gy',((e.clientY-r.top)/r.height*100).toFixed(1)+'%');
  }
},{passive:true});
/* keep toast / sheet visible while the player is in real fullscreen */
document.addEventListener('fullscreenchange',function(){var host=document.fullscreenElement||document.webkitFullscreenElement||document.body;[toastEl,$('#sheetWrap')].forEach(function(el){if(el&&el.parentNode!==host)host.appendChild(el)})});

/* ═════════ Installable app (PWA) ═════════ */
var deferred=null,standalone=(window.matchMedia&&matchMedia('(display-mode: standalone)').matches)||nv.standalone===true;
window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();deferred=e;var b=$('#btnInstall');if(b&&!standalone)b.classList.remove('hide')});
window.addEventListener('appinstalled',function(){deferred=null;var b=$('#btnInstall');if(b)b.classList.add('hide');toast('CinePulse installed')});
function installApp(){if(deferred){deferred.prompt();deferred.userChoice.finally(function(){deferred=null;var b=$('#btnInstall');if(b)b.classList.add('hide')})}else toast(/iPhone|iPad|iPod/.test(nv.userAgent)?'Tap Share, then “Add to Home Screen”':'Use your browser menu → Install CinePulse')}
if('serviceWorker'in nv&&(location.protocol==='https:'||location.hostname==='localhost'||location.hostname==='127.0.0.1'))window.addEventListener('load',function(){nv.serviceWorker.register('?action=sw').catch(function(){})});
window.addEventListener('offline',function(){toast('You\'re offline')});window.addEventListener('online',function(){toast('Back online')});

/* ═════════ Router ═════════ */
var vHome=$('#vHome'),vMain=$('#vMain'),scrolls={},curKey='',playerOpen=false;
function parseQ(){var o={};new URLSearchParams(location.search).forEach(function(v,k){o[k]=v});if(!o.action)o.action='home';return o}
function go(p,replace){
  var q=new URLSearchParams(p).toString();scrolls[curKey]=window.scrollY;
  history[replace?'replaceState':'pushState'](Object.assign({_in:1},p),'','?'+q);
  route(p,false);
}
window.addEventListener('popstate',function(){route(parseQ(),true)});
function setNav(a,t){
  var key=a==='browse'?t:(a==='detail'||a==='watch'||a==='search')?'':a;
  $$('[data-nav]').forEach(function(l){l.classList.toggle('on',l.getAttribute('data-nav')===key)});
  lens();
}
function lens(){
  ['#navPill','#tabbar'].forEach(function(s){
    var c=$(s);if(!c)return;var l=$('.lens',c);if(!l){l=document.createElement('i');l.className='lens';c.insertBefore(l,c.firstChild)}
    var a=$('a.on',c);if(!a||!c.offsetWidth){l.classList.remove('on');return}
    l.style.width=a.offsetWidth+'px';l.style.transform='translateX('+a.offsetLeft+'px)';l.classList.add('on');
  });
}
window.addEventListener('resize',lens);if(document.fonts&&document.fonts.ready)document.fonts.ready.then(lens);
function clearMain(){unlazy(vMain);vMain.innerHTML='';browseCleanup()}
function route(p,pop){
  var a=p.action||'home',key=location.search;curKey=key;closeSearch();hideSheet();
  if(a==='watch'){openWatch(p);return}
  if(playerOpen)closePlayer(true);
  setNav(a,p.type);
  if(a==='home'){clearMain();vMain.classList.add('hide');vHome.classList.remove('hide');showHome()}
  else{vHome.classList.add('hide');vMain.classList.remove('hide');clearMain();
    if(a==='browse')viewBrowse(p);else if(a==='search')viewSearch(p.q||'');else if(a==='detail')viewDetail(p);
    else if(a==='library')viewLibrary();else if(a==='settings')viewSettings();else{go({action:'home'},true);return}}
  window.scrollTo(0,pop?(scrolls[key]||0):0);
  if(a!=='detail')document.title='CinePulse';
}
document.addEventListener('click',function(e){
  if(e.defaultPrevented)return;var a=e.target.closest&&e.target.closest('a[data-r]');if(!a||e.metaKey||e.ctrlKey||e.shiftKey||e.button)return;
  e.preventDefault();var r=a.getAttribute('data-r'),p;var rp=playerOpen&&r==='watch';
  if(r==='home')p={action:'home'};else if(r==='browse')p={action:'browse',type:a.getAttribute('data-type')};
  else if(r==='detail')p={action:'detail',slug:a.getAttribute('data-slug')};else if(r==='watch')p={action:'watch',slug:a.getAttribute('data-slug')};
  else p={action:r};
  if(location.search==='?'+new URLSearchParams(p).toString()||(r==='home'&&!location.search)){window.scrollTo({top:0,behavior:'smooth'});return}
  go(p,rp);
});
/* prefetch details on intent so pages open instantly */
var pf=0;document.addEventListener('pointerover',function(e){var a=e.target.closest&&e.target.closest('a.card');if(!a)return;clearTimeout(pf);pf=setTimeout(function(){detail(a.getAttribute('data-slug')).catch(function(){})},140)},{passive:true});

/* ═════════ Home ═════════ */
var hero={items:[],i:0,t:0,vis:true,layer:0},homeSig='',homeBuilt=false;
function cwList(){return LS.get('cp:cw',[])}
function cwExtra(it){return'<div class="pbar"><i data-p="'+(it.d?Math.min(1,it.t/it.d):0)+'"></i></div><button class="cwx" aria-label="Remove from Continue Watching" data-slug="'+esc(it.slug)+'">'+icon('x')+'</button>'}
function railHTML(label,items,extraFn){
  return'<section class="rsec"><h2>'+esc(label)+'</h2><div class="rwrap"><div class="rail">'+items.map(function(it){return card(it,extraFn&&extraFn(it))}).join('')+'</div>'+
    '<button class="lg ib arrow l" aria-label="Scroll left" data-dir="-1">'+icon('back')+'</button><button class="lg ib arrow r" aria-label="Scroll right" data-dir="1">'+icon('chev')+'</button></div></section>';
}
function offlineBannerHTML(){
  if(navigator.onLine)return'';
  return'<div class="panel sg-alert" style="margin:calc(env(safe-area-inset-top,0px) + 84px) var(--pad) 14px;border:1px solid rgba(238,30,80,.4);background:rgba(238,30,80,.12);border-radius:18px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px">'+
    '<div><b style="color:#fff;font-size:14.5px;display:block">Offline Mode</b><small style="color:var(--fg2)">No internet connection detected. You can browse cached titles and local library.</small></div></div>';
}
function getStartedHTML(){
  if(LS.get('cp:getstarted_dismissed'))return'';
  return'<div class="panel sg-banner" id="gsBanner" style="margin:calc(env(safe-area-inset-top,0px) + 84px) var(--pad) 16px;border:1px solid rgba(255,255,255,.15);border-radius:24px;padding:22px;background:rgba(255,255,255,.06);box-shadow:0 12px 36px rgba(0,0,0,.6)">'+
    '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px">'+
      '<div><span class="chip" style="color:var(--fg);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;display:inline-block">Welcome to CinePulse</span>'+
      '<h3 style="font-size:19px;font-weight:800;color:#fff;margin-bottom:6px">Stream movies, series and anime</h3>'+
      '<p style="color:var(--fg2);font-size:13.5px;line-height:1.5;max-width:560px">Engineered with Solid Glass &amp; Liquid Glass architecture. Zero tracking, ultra-fast streaming, and homepage caching.</p></div>'+
      '<button class="ib" id="gsClose" style="width:36px;height:36px;background:rgba(255,255,255,.08);color:var(--fg2);border-radius:50%" aria-label="Dismiss">'+icon('x')+'</button></div>'+
    '<div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap"><a class="btn sm lg-solid press" href="?action=browse&amp;type=movies" data-r="browse" data-type="movies">Explore Movies</a><a class="btn sm lg press" href="?action=browse&amp;type=tv-series" data-r="browse" data-type="tv-series">Browse Series</a></div></div>';
}
function renderHome(h){
  var cw=cwList(),rails='';
  if(cw.length)rails+=railHTML('Continue Watching',cw,cwExtra);
  h.rails.forEach(function(r){rails+=railHTML(r.label,r.items)});
  vHome.innerHTML=offlineBannerHTML()+getStartedHTML()+'<section class="hero" id="hero"><div class="hero-bg"><img alt="" class="a"><img alt="" class="b"></div><div class="hero-shade"></div>'+
    '<div class="hero-copy" id="hCopy"><div class="eyebrow" id="hEye"></div><h1 id="hTitle"></h1><div class="meta" id="hMeta"></div><p id="hDesc"></p>'+
    '<div class="acts"><a class="btn lg-solid press" id="hPlay" href="?" data-r="watch">'+icon('play','f')+'Play</a><a class="btn lg press" id="hMore" href="?" data-r="detail">'+icon('info')+'More Info</a></div></div>'+
    '<div class="dots lg" id="hDots"></div></section><div class="rails">'+rails+'</div>';
  var gsc=$('#gsClose');if(gsc)gsc.onclick=function(){LS.set('cp:getstarted_dismissed',true);var b=$('#gsBanner');if(b)b.remove()};
  $$('.pbar i',vHome).forEach(function(i){set(i,i.getAttribute('data-p'))});
  lazy(vHome);glass($('#hMore'));glass($('#hDots'));
  hero.items=h.hero;hero.i=0;hero.layer=0;
  if(hero.items.length){
    $('#hDots').innerHTML=hero.items.length>1?hero.items.map(function(_,i){return'<button aria-label="Slide '+(i+1)+'" data-i="'+i+'"></button>'}).join(''):'';
    if(hero.items.length<2)$('#hDots').classList.add('hide');
    setHero(0,true);
    if('IntersectionObserver'in window){var ho=new IntersectionObserver(function(es){hero.vis=es[0].isIntersecting;hero.vis?heroTimer():clearTimeout(hero.t)},{threshold:.25});ho.observe($('#hero'))}
  }
}
function showHome(){
  if(homeBuilt){heroTimer();var cw=cwList();return refreshCW(cw)}
  var cached=LS.get('cp:home',null);
  if(cached&&cached.hero){renderHome(cached);homeBuilt=true;homeSig=sig(cached)}else vHome.innerHTML=skHome();
  api({action:'feed',kind:'home'},60000).then(function(res){
    var h=parseHome(res);if(!h.hero.length&&!h.rails.length){if(!homeBuilt)vHome.innerHTML='<div class="empty"><h3>Nothing to show yet</h3><p>The catalogue didn\'t return any titles. Try again in a moment.</p></div>';return}
    var s=sig(h);try{LS.set('cp:home',h)}catch(e){}
    if(!homeBuilt||s!==homeSig){clearTimeout(hero.t);renderHome(h);homeBuilt=true;homeSig=s}
  }).catch(function(err){if(!homeBuilt)vHome.innerHTML='<div class="empty"><h3>Can\'t reach the catalogue</h3><p>'+esc(err.message)+'</p><button class="btn lg press" id="retryHome">Retry</button></div>';var b=$('#retryHome');if(b)b.onclick=function(){homeBuilt=false;showHome()}});
}
function sig(h){return h.hero.map(function(x){return x.slug}).join(',')+'|'+h.rails.map(function(r){return r.label+r.items.length+(r.items[0]?r.items[0].slug:'')}).join(',')}
function refreshCW(cw){
  var old=$$('.rsec',vHome)[0];var has=old&&$('h2',old).textContent==='Continue Watching';
  if(!cw.length&&!has)return;
  var wrap=$('.rails',vHome);if(!wrap)return;
  if(has){unlazy(old);old.remove()}
  if(cw.length){wrap.insertAdjacentHTML('afterbegin',railHTML('Continue Watching',cw,cwExtra));var s=wrap.firstChild;$$('.pbar i',s).forEach(function(i){set(i,i.getAttribute('data-p'))});lazy(s)}
}
function heroTimer(){clearTimeout(hero.t);if(hero.items.length<2||!hero.vis||document.hidden||!SET.heroauto)return;hero.t=setTimeout(function(){setHero((hero.i+1)%hero.items.length)},7000)}
document.addEventListener('visibilitychange',function(){if(!vHome.classList.contains('hide'))heroTimer()});
function setHero(i,first){
  var it=hero.items[i];if(!it)return;hero.i=i;clearTimeout(hero.t);
  var copy=$('#hCopy');if(!first)copy.classList.add('sw');
  function paintText(){
    $('#hEye').textContent=it.type==='tv'?'Series':'Movie';$('#hTitle').textContent=it.title;
    $('#hMeta').innerHTML=[it.rating?'<span class="chip">'+starIcon()+esc(it.rating)+'</span>':'',it.year?'<span>'+esc(it.year)+'</span>':'',it.genre?'<span>'+esc(String(it.genre).split(',').slice(0,3).join(' · '))+'</span>':''].join('');
    $('#hDesc').textContent=it.description||'';
    $('#hPlay').setAttribute('data-slug',it.slug);$('#hMore').setAttribute('data-slug',it.slug);
    $$('#hDots button').forEach(function(b,k){b.classList.toggle('on',k===i)});copy.classList.remove('sw');
  }
  if(first)paintText();else setTimeout(paintText,220);
  var bg=$$('.hero-bg img');
  function paintImg(){
    var url=it.still||it.poster;if(!url)return heroTimer();
    var next=bg[hero.layer?0:1],cur=bg[hero.layer],im=new Image();
    var done=function(){if(hero.i!==i)return;next.src=url;next.classList.add('on');cur.classList.remove('on');hero.layer=hero.layer?0:1;heroTimer()};
    im.onload=im.onerror=done;im.decoding='async';im.src=url;
  }
  paintImg();
  /* upgrade to a wide backdrop / fill missing description in the background */
  [it,hero.items[(i+1)%hero.items.length]].forEach(function(t){
    if(!t||t._e||!t.slug)return;t._e=1;
    detail(t.slug).then(function(d){
      if(!d)return;if(d.description&&!t.description)t.description=d.description;if(d.rating&&!t.rating)t.rating=d.rating;if(d.genre&&!t.genre)t.genre=d.genre;
      if(d.still&&!t.still){t.still=d.still;if(hero.items[hero.i]===t){var im=new Image();im.onload=function(){if(hero.items[hero.i]!==t)return;var c=bg[hero.layer];c.src=t.still;c.classList.add('on')};im.src=t.still}}
      if(hero.items[hero.i]===t){$('#hDesc').textContent=t.description||''}
    }).catch(function(){});
  });
}
vHome.addEventListener('click',function(e){
  var cx2=e.target.closest('.cwx');if(cx2){e.preventDefault();var sl=cx2.getAttribute('data-slug');LS.set('cp:cw',cwList().filter(function(x){return x.slug!==sl}));var sec=cx2.closest('.rsec');cx2.closest('.card').remove();if(!$('.card',sec)){unlazy(sec);sec.remove()}toast('Removed from Continue Watching');return}
  var d=e.target.closest('#hDots button');if(d){setHero(+d.getAttribute('data-i'));return}
  var ar=e.target.closest('.arrow');if(ar){var r=$('.rail',ar.parentNode);r.scrollBy({left:(+ar.getAttribute('data-dir'))*r.clientWidth*.86,behavior:'smooth'})}
});

/* ═════════ Browse / Search grids ═════════ */
var B={on:false};
function browseCleanup(){if(B.obs){B.obs.disconnect()}B={on:false}}
var TITLES={movies:'Movies','tv-series':'TV Series',animation:'Anime'};
function viewBrowse(p){
  var type=TITLES[p.type]?p.type:'movies',sort=p.sort||'foryou';
  vMain.innerHTML='<div class="view">'+skGrid(18)+'</div>';
  var head='<div class="pagehead"><div><h1>'+TITLES[type]+'</h1></div><div class="seg lg" id="sortSeg">'+[['foryou','For You'],['hottest','Hottest'],['latest','Latest'],['rating','Top Rated']].map(function(s){return'<button data-s="'+s[0]+'" class="'+(s[0]===sort?'on':'')+'">'+s[1]+'</button>'}).join('')+'</div></div>';
  startGrid(head,function(page){return{action:'feed',kind:type,page:page,sort:sort}},true);
}
function viewSearch(q){
  if(!q){go({action:'home'},true);return}
  vMain.innerHTML='<div class="view">'+skGrid(12)+'</div>';
  startGrid('<div class="pagehead"><div><h1>Results</h1><p>for “'+esc(q)+'”</p></div></div>',function(page){return page>1?null:{action:'feed',kind:'search',q:q}},false);
}
function startGrid(head,paramFn,paged){
  var seen={},page=1,busy=false,done=false,first=true;
  B={on:true};var tok=B;
  function load(){
    if(busy||done||B!==tok)return;var pr=paramFn(page);if(!pr){done=true;return}busy=true;
    api(pr,90000).then(function(res){
      if(B!==tok)return;var items=normList(res.items||res.data||res.list).filter(function(x){if(seen[x.slug])return false;seen[x.slug]=1;return true});
      if(first){first=false;
        vMain.innerHTML='<div class="view">'+head+'<div class="grid" id="grid"></div><div class="sentinel" id="sent"></div></div>';
        $('#sortSeg')&&$('#sortSeg').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var p=parseQ();p.sort=b.getAttribute('data-s');go(p,true)});
        glass($('#sortSeg'));
        if(paged){B.obs=new IntersectionObserver(function(es){if(es[0].isIntersecting)load()},{rootMargin:'1400px'});B.obs.observe($('#sent'))}
      }
      if(!items.length){done=true;$('#sent')&&($('#sent').textContent='');
        if(page===1)$('#grid').outerHTML='<div class="empty"><div class="ring lg">'+icon('film')+'</div><h3>No titles found</h3><p>Try a different search or category.</p></div>';return}
      var g=$('#grid'),n0=g.children.length;g.insertAdjacentHTML('beforeend',items.map(function(it){return card(it)}).join(''));for(var q=n0;q<g.children.length;q++)lazy(g.children[q]);
      page++;busy=false;if(!paged){done=true;$('#sent').textContent=''}
      if(paged&&B.obs){B.obs.unobserve($('#sent'));B.obs.observe($('#sent'))}
    }).catch(function(err){
      busy=false;if(B!==tok)return;
      if(first){vMain.innerHTML='<div class="view"><div class="empty"><h3>Couldn\'t load</h3><p>'+esc(err.message)+'</p><button class="btn lg press" id="rg">Retry</button></div></div>';$('#rg').onclick=function(){route(parseQ(),true)}}
      else{var s=$('#sent');if(s){s.innerHTML='<button class="btn sm lg press" id="rg2">Retry</button>';$('#rg2').onclick=function(){s.textContent='';load()}}}
    });
  }
  load();
}
/* Search overlay */
var sEl=$('#search'),sIn=$('#sIn'),sSug=$('#sSug'),sT=0,sIdx=-1;
function openSearch(){sEl.classList.add('on');glass($('.sbar',sEl));glass(sSug,true);sIn.value='';sSug.classList.remove('on');setTimeout(function(){sIn.focus()},60)}
function closeSearch(){sEl.classList.remove('on');sIn.blur()}
function doSearch(q){q=q.trim();if(q){closeSearch();go({action:'search',q:q})}}
$('#btnSearch').addEventListener('click',openSearch);$('#sX').addEventListener('click',closeSearch);
sEl.addEventListener('click',function(e){if(e.target===sEl)closeSearch()});
document.addEventListener('keydown',function(e){
  if(e.key==='/'&&!/INPUT|TEXTAREA/.test((e.target.tagName||''))&&!playerOpen){e.preventDefault();openSearch()}
  else if(e.key==='Escape'&&sEl.classList.contains('on'))closeSearch();
});
sIn.addEventListener('keydown',function(e){
  var bs=$$('button',sSug);
  if(e.key==='Enter'){e.preventDefault();doSearch(sIdx>=0&&bs[sIdx]?bs[sIdx].textContent:sIn.value)}
  else if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(!bs.length)return;sIdx=(sIdx+(e.key==='ArrowDown'?1:-1)+bs.length)%bs.length;bs.forEach(function(b,i){b.classList.toggle('on',i===sIdx)})}
});
sIn.addEventListener('input',function(){
  clearTimeout(sT);var q=sIn.value.trim();sIdx=-1;if(!q){sSug.classList.remove('on');return}
  sT=setTimeout(function(){api({action:'suggest',q:q},60000).then(function(r){
    if(sIn.value.trim()!==q)return;var a=(r&&(r.suggestions||r.data||r.items))||[];
    var list=a.map(function(s){return typeof s==='string'?s:(s&&(s.text||s.keyword||s.title||s.name||s.word))||''}).filter(Boolean).slice(0,7);
    if(!list.length){sSug.classList.remove('on');return}
    sSug.innerHTML=list.map(function(s){return'<button>'+icon('search')+'<span>'+esc(s)+'</span></button>'}).join('');sSug.classList.add('on');
  }).catch(function(){})},200);
});
sSug.addEventListener('click',function(e){var b=e.target.closest('button');if(b)doSearch(b.textContent)});

/* ═════════ Library ═════════ */
function viewLibrary(){
  var list=LS.get('cp:mylist',[]);
  if(!list.length){vMain.innerHTML='<div class="view"><div class="pagehead"><div><h1>My List</h1></div></div><div class="empty"><div class="ring lg">'+icon('list')+'</div><h3>Your list is empty</h3><p>Tap “My List” on any title to keep it here.</p></div></div>';glass($('.ring',vMain));return}
  vMain.innerHTML='<div class="view"><div class="pagehead"><div><h1>My List</h1><p>'+list.length+' title'+(list.length>1?'s':'')+'</p></div></div><div class="grid">'+list.map(function(it){return card(it)}).join('')+'</div></div>';lazy(vMain);
}
function inList(slug){return LS.get('cp:mylist',[]).some(function(x){return x.slug===slug})}
function toggleList(d){
  var l=LS.get('cp:mylist',[]);
  if(inList(d.slug)){l=l.filter(function(x){return x.slug!==d.slug});toast('Removed from My List')}
  else{l.unshift({slug:d.slug,title:d.title,poster:d.poster,year:d.year,rating:d.rating,type:d.type});if(l.length>250)l.length=250;toast('Added to My List')}
  LS.set('cp:mylist',l);
}

/* ═════════ Detail ═════════ */
var D={d:null,se:1};
function posKey(slug,se,ep){return'cp:pos:'+slug+(se?':'+se+'x'+ep:'')}
function lastWatched(d){var c=cwList().filter(function(x){return x.slug===d.slug})[0];return c||null}
function viewDetail(p){
  var slug=p.slug;if(!slug){go({action:'home'},true);return}
  vMain.innerHTML='<div class="view">'+skDetail()+'</div>';
  detail(slug).then(function(d){if(parseQ().slug!==slug||parseQ().action!=='detail')return;drawDetail(d,false)}).catch(function(err){
    vMain.innerHTML='<div class="view"><div class="empty"><h3>Couldn\'t open this title</h3><p>'+esc(err.message)+'</p><button class="btn lg press" id="rd">Retry</button></div></div>';$('#rd').onclick=function(){viewDetail(p)}});
}
function epLabel(d,c){return d.series?'S'+c.se+' E'+c.ep:''}
function drawDetail(d,quiet){
  D.d=d;var lw=lastWatched(d);D.se=lw&&lw.se?lw.se:(d.seasons[0]?d.seasons[0].se:1);
  var tags=[d.rating?'<span class="chip">'+starIcon()+esc(d.rating)+'</span>':'',d.year?'<span>'+esc(d.year)+'</span>':'',d.type==='tv'?'<span>Series</span>':'<span>Movie</span>',d.genre?'<span>'+esc(String(d.genre).split(',').slice(0,3).join(' · '))+'</span>':'',d.country?'<span>'+esc(d.country)+'</span>':''].join('');
  var playLbl=lw&&lw.t>10?('Resume'+(d.series?' '+epLabel(d,lw):'')):(lw&&d.series?'Play '+epLabel(d,lw):'Play');
  var h='<div class="view"><div class="backdrop"><img alt="" data-src="'+esc(d.still||d.poster)+'"></div><div class="dbody"><h1>'+esc(d.title)+'</h1><div class="meta">'+tags+'</div><p class="ov">'+esc(d.description)+'</p>'+
    '<div class="acts"><a class="btn lg-solid press" id="dPlay" href="?" data-r="watch" data-slug="'+esc(d.slug)+'">'+icon('play','f')+'<span>'+playLbl+'</span></a>'+
    '<button class="btn lg press" id="dDl">'+icon('dl')+'<span>Download</span></button>'+
    '<button class="btn lg press" id="dList">'+icon(inList(d.slug)?'check':'plus')+'<span>My List</span></button></div></div>';
  if(d.series&&d.seasons.length){h+='<div class="dsec"><h2>Episodes</h2>'+(d.seasons.length>1?'<div class="seg lg" id="seaSeg">'+d.seasons.map(function(s){return'<button data-se="'+s.se+'" class="'+(s.se===D.se?'on':'')+'">Season '+s.se+'</button>'}).join('')+'</div>':'')+'<div class="eps" id="eps"></div><div id="epMore"></div></div>'}
  if(d.stars&&d.stars.length){
    var sortedStars=d.stars.slice().sort(function(a,b){return (b.avatar||b.avatarUrl||b.photo?1:0)-(a.avatar||a.avatarUrl||a.photo?1:0)}).slice(0,24);
    h+='<div class="dsec"><h2>Cast</h2><div class="cast">'+sortedStars.map(function(s){
      var nm=s.name||'',av=s.avatar||s.avatarUrl||s.photo||'',ini=nm.split(/\s+/).filter(Boolean).slice(0,2).map(function(w){return w.charAt(0)}).join('').toUpperCase();
      return'<div><div class="av"><span>'+esc(ini)+'</span>'+(av?'<img alt="" referrerpolicy="no-referrer" loading="lazy" src="'+esc(av)+'" onload="this.classList.add(\'ld\')" onerror="this.remove()">':'')+'</div><p>'+esc(nm)+(s.role?'<em>'+esc(s.role)+'</em>':'')+'</p></div>';
    }).join('')+'</div></div>';
  }
  h+='<div class="dsec dend"></div></div>';
  unlazy(vMain);vMain.innerHTML=h;lazy(vMain);
  document.title=d.title+' · CinePulse';
  glass($('#dList'));glass($('#dDl'));glass($('#seaSeg'));
  if(d.series)drawEps(30);
  $('#dList').onclick=function(){toggleList(d);this.innerHTML=icon(inList(d.slug)?'check':'plus')+'<span>My List</span>'};
  $('#dDl').onclick=openAppSheet;
  if($('#seaSeg'))$('#seaSeg').onclick=function(e){var b=e.target.closest('button');if(!b)return;D.se=+b.getAttribute('data-se');$$('#seaSeg button').forEach(function(x){x.classList.toggle('on',x===b)});drawEps(30)};
}
function drawEps(limit){
  var d=D.d,s=d.seasons.filter(function(x){return x.se===D.se})[0];if(!s)return;var box=$('#eps'),n=Math.min(s.count,limit),h='';
  for(var e=1;e<=n;e++){var r=LS.get(posKey(d.slug,D.se,e),null),pr=r&&r.d?Math.min(1,r.t/r.d):0;
    h+='<div class="ep"><a class="go" href="?" data-r="watch" data-slug="'+esc(d.slug)+'" data-se="'+D.se+'" data-ep="'+e+'"><b>Episode '+e+'</b><span>'+(pr>.9?'Watched':pr>0?Math.round(pr*100)+'% watched':'S'+D.se+' · E'+e)+'</span></a>'+(pr>0?'<div class="pbar"><i data-p="'+pr+'"></i></div>':'')+'</div>'}
  box.innerHTML=h;$$('.pbar i',box).forEach(function(i){set(i,i.getAttribute('data-p'))});
  $('#epMore').innerHTML=s.count>n?'<button class="btn sm lg press more" id="moreEp">Show all '+s.count+' episodes</button>':'';
  if($('#moreEp')){glass($('#moreEp'));$('#moreEp').onclick=function(){drawEps(s.count)}}
}
vMain.addEventListener('click',function(e){
  var g=e.target.closest('a.go[data-ep]');if(g){e.preventDefault();go({action:'watch',slug:g.getAttribute('data-slug'),se:g.getAttribute('data-se'),ep:g.getAttribute('data-ep')},playerOpen)}
});

/* ═════════ Sheets ═════════ */
var shW=$('#sheetWrap'),sh=$('#sheet'),shLock=false;
function showSheet(html,lock){sh.innerHTML=html;shLock=!!lock;shW.classList.add('on');glass(sh,true)}
function hideSheet(force){if(shLock&&!force)return;shLock=false;shW.classList.remove('on')}
shW.addEventListener('click',function(e){if(e.target===shW)hideSheet()});
sh.addEventListener('click',function(e){if(e.target.closest('[data-x]'))hideSheet(true)});
var LOGO='<div class="mk"><img src="/faviconandlogo.jpg" alt="" width="64" height="64"></div>';
/* Downloads live in the open-source Android app */
function openAppSheet(){
  showSheet(LOGO+'<h3>Downloads are in the Android app</h3><p>Offline downloads are only available in the open-source CinePulse Android app. Grab the latest APK from GitHub.</p><div class="row"><a class="btn lg-solid press" href="'+APK+'" target="_blank" rel="noopener noreferrer">'+icon('dl')+'Get the Android app</a><button class="btn lg press" data-x="1">Not now</button></div>');
}

/* ═════════ Settings ═════════ */
function viewSettings(){
  var q=LS.get('cp:q','');
  var rows=function(list){return list.map(function(r){
    return'<div class="srow"><div><b>'+r.t+'</b>'+(r.h?'<small>'+r.h+'</small>':'')+'</div><div class="seg flat" data-k="'+r.k+'">'+r.o.map(function(o){return'<button data-v="'+o[0]+'" class="'+(String(r.v)===o[0]?'on':'')+'">'+o[1]+'</button>'}).join('')+'</div></div>'}).join('')};
  var h='<div class="view"><div class="pagehead"><div><h1>Settings</h1><p>Make CinePulse yours.</p></div></div><div class="sgroup">'+
   '<h2>Appearance</h2><div class="panel">'+rows([
     {t:'Glass effects',h:'Auto picks the lightest mode your device handles. Lite uses the least memory.',k:'glass',v:pref,o:[['auto','Auto'],['full','Full'],['lite','Lite']]},
     {t:'Banner slideshow',h:'Automatically rotate the featured titles on Home.',k:'heroauto',v:SET.heroauto?'1':'0',o:[['1','On'],['0','Off']]}])+'</div>'+
   '<h2>Playback</h2><div class="panel">'+rows([
     {t:'Autoplay next episode',h:'Continue straight into the next episode.',k:'autonext',v:SET.autonext?'1':'0',o:[['1','On'],['0','Off']]},
     {t:'Subtitle size',h:'Applies to the subtitles shown on the video.',k:'cue',v:SET.cue,o:[['.8','Small'],['1','Medium'],['1.3','Large']]},
     {t:'Default quality',h:'Used whenever a stream starts. You can still switch inside the player.',k:'quality',v:q,o:[['','Auto'],['1080p','1080p'],['720p','720p'],['480p','480p']]}])+'</div>'+
   '<h2>Install</h2><div class="panel app"><div class="mk2">'+icon('plus')+'</div><div><b>Add CinePulse to your device</b><small>'+(standalone?'You\'re using the installed app.':'Install it like an app — its own window, no browser bars, one tap to open.')+'</small></div>'+(standalone?'':'<button class="btn sm lg-solid press" id="setInstall">Install</button>')+'</div>'+
   '<h2>Your data &amp; Cache</h2><div class="panel">'+
     '<div class="srow"><div><b>Get Started banner</b><small>Display the welcome &amp; quick-start banner on Home again.</small></div><button class="sbtn" data-do="reset-gs">Show banner</button></div>'+
     '<div class="srow"><div><b>Home cache</b><small>Clear locally cached homepage feed for instant refresh.</small></div><button class="sbtn" data-do="cache">Clear cache</button></div>'+
     '<div class="srow"><div><b>Watch history</b><small>Clears Continue Watching and saved positions on this device.</small></div><button class="sbtn" data-do="hist">Clear</button></div>'+
     '<div class="srow"><div><b>My List</b><small>Removes every saved title from your list.</small></div><button class="sbtn" data-do="list">Clear</button></div>'+
     '<div class="srow"><div><b>Reset settings</b><small>Back to the defaults.</small></div><button class="sbtn" data-do="reset">Reset</button></div></div>'+
   '<h2>Downloads</h2><div class="panel app"><div class="mk2">'+icon('dl')+'</div><div><b>Android app &amp; Offline Player</b><small>Full offline downloads and media manager operate in the CinePulse Android app.</small></div><a class="btn sm lg-solid press" href="'+APK+'" target="_blank" rel="noopener noreferrer">Get the app</a></div>'+
   '<h2>About &amp; Developer Credits</h2><div class="panel">'+
     '<div class="srow"><div><b>Architecture &amp; Development</b><small>Designed &amp; built by <a class="link" href="https://ahmedishtiaquezarir.pro.bd/" target="_blank" rel="noopener noreferrer">ImAhmedZarir (Ahmed Ishtiaque Zarir)</a></small></div><button class="sbtn" data-do="credit">Full credits</button></div>'+
     '<div class="srow"><div><b>Liquid Glass Engine</b><small>Android Glassmorphism components by QWEA0</small></div></div>'+
     '<div class="srow"><div><b>Solid Glass Tokens</b><small>Physical edge &amp; refraction specification by deeplethe</small></div></div>'+
     '<div class="srow"><div><b>Media Engine &amp; Pipeline</b><small>Google ExoPlayer / Media3 &amp; Coil image loader</small></div></div></div>'+
   '<h2>Support Developer &amp; Donations</h2><div class="panel">'+
     '<div class="srow"><div><b>Donate &amp; Inquiries</b><small>Help upgrade from free hosting to dedicated cloud servers: <a class="link" href="mailto:ahmedishtiaquezarir@gmail.com">ahmedishtiaquezarir@gmail.com</a></small></div></div>'+
     '<div class="srow"><div><b>Telegram Contact</b><small><a class="link" href="https://t.me/@imahmedzarir" target="_blank" rel="noopener noreferrer">https://t.me/@imahmedzarir</a></small></div></div></div>'+
   '<h2>Privacy, Legal Terms &amp; Hosting</h2><div class="panel"><div class="srow"><div><b>Non-Hosting Policy</b><small>CinePulse and author do NOT host or stream copyrighted media. The API exclusively indexes public metadata; streams resolve client-side.</small></div></div>'+
     '<div class="srow"><div><b>Free Cloud Hosting &amp; Regional DNS</b><small>Hosted on free community tier. If blocked by regional ISPs, use custom DNS (Cloudflare 1.1.1.1, Google 8.8.8.8) or a VPN.</small></div></div>'+
     '<div class="srow"><div><b>Zero Tracking</b><small>No user tracking, no telemetry, and no watch history sent to external servers.</small></div></div>'+
     '<div class="srow"><div><b>Non-Commercial Use</b><small>CinePulse is free to use strictly for educational non-commercial purposes.</small></div></div>'+
     '<div class="srow"><div><b>Client Security Notice</b><small>Client source code is private and client connections cannot be cryptographically secured on untrusted environments.</small></div></div></div>'+
   '<h2>Keyboard shortcuts</h2><div class="panel"><div class="srow"><small class="keys"><b>Space / K</b> play · <b>&larr; &rarr;</b> &plusmn;5s · <b>J L</b> &plusmn;10s · <b>&uarr; &darr;</b> volume · <b>M</b> mute · <b>F</b> fullscreen · <b>C</b> subtitles · <b>N</b> next episode · <b>/</b> search</small></div></div></div></div>';
  vMain.innerHTML=h;var si=$('#setInstall');if(si)si.onclick=installApp;
}
vMain.addEventListener('click',function(e){
  var b=e.target.closest('.seg.flat button');
  if(b){var k=b.parentNode.getAttribute('data-k'),v=b.getAttribute('data-v');
    $$('button',b.parentNode).forEach(function(x){x.classList.toggle('on',x===b)});
    if(k==='glass'){LS.set('cp:glass',v);location.reload()}
    else if(k==='quality'){if(v)LS.set('cp:q',v);else LS.del('cp:q')}
    else if(k==='cue'){SET.cue=v;saveSet();applyCue()}
    else{SET[k]=v==='1';saveSet();if(k==='heroauto'&&SET.heroauto)heroTimer()}
    return}
  var d=e.target.closest('[data-do]');if(!d)return;var a=d.getAttribute('data-do');
  if(a==='hist'){try{Object.keys(localStorage).forEach(function(k){if(k.indexOf('cp:pos:')===0)localStorage.removeItem(k)})}catch(_){}LS.del('cp:cw');toast('Watch history cleared')}
  else if(a==='list'){LS.del('cp:mylist');toast('My List cleared')}
  else if(a==='reset'){['cp:set','cp:glass','cp:q','cp:cc','cp:vol'].forEach(LS.del);location.reload()}
  else if(a==='reset-gs'){LS.del('cp:getstarted_dismissed');toast('Get Started banner will show on Home')}
  else if(a==='cache'){LS.del('cp:home');toast('Home cache cleared')}
  else if(a==='credit'){credit(true)}
});

/* ═════════ Player ═════════ */
var pEl=$('#player'),vid=$('#vid'),pUI=$('#pUI'),pMenu=$('#pMenu'),pSeek=$('#pSeek'),pFill=$('#pFill'),pBuf=$('#pBuf'),pThumb=$('#pThumb'),pTip=$('#pTip'),pCur=$('#pCur'),pRem=$('#pRem'),pSpin=$('#pSpin');
var P={d:null,se:1,ep:1,srcs:[],qi:0,caps:[],cc:-1,tok:0,seekTo:0,auto:false,fails:0,pfs:false},uiT=0,uiOn=false,menu='',drag=false,sw=1,lastSave=0;
vid.volume=LS.get('cp:vol',1);vid.muted=false;$('#pVolR').value=vid.volume;
if(!document.pictureInPictureEnabled)$('#pPip').classList.add('hide');
function applyCue(){vid.style.setProperty('--cue',SET.cue||'1')}applyCue();
vid.disableRemotePlayback=true;vid.setAttribute('controlslist','nodownload noremoteplayback');

async function openWatch(p){
  var slug=p.slug;if(!slug){go({action:'home'},true);return}
  playerOpen=true;pEl.classList.add('on');P.pfs=false;
  $$('.lg',pEl).forEach(function(x){if(x.id!=='pFlash')glass(x,x===pMenu||x.id==='pErr')});
  hideErr();spin(true);showUI();setNav('watch');applyDock();P.tok++;var tok=P.tok;P.fails=0;
  try{
    var d=await detail(slug);if(tok!==P.tok)return;P.d=d;
    var se=0,ep=0;
    if(d.series){var lw=lastWatched(d);se=+p.se||(lw&&lw.se)||(d.seasons[0]&&d.seasons[0].se)||1;
      if(d.seasons.length&&!d.seasons.some(function(s){return s.se===se}))se=d.seasons[0].se;
      ep=+p.ep||(lw&&lw.se===se&&lw.ep)||1;
      if(!p.se||!p.ep||p.se!=se||p.ep!=ep){var np=Object.assign({},p,{se:se,ep:ep});history.replaceState(Object.assign({_in:history.state&&history.state._in},np),'','?'+new URLSearchParams(np).toString())}}
    /* make sure a title page exists behind the player (always on phones, where the player is docked above it) */
    var hasPage=!vMain.classList.contains('hide')&&$('.dbody',vMain)&&D.d&&D.d.slug===d.slug;
    if(!hasPage&&(pEl.classList.contains('dock')||!history.state||!history.state._in)){vHome.classList.add('hide');vMain.classList.remove('hide');unlazy(vMain);drawDetail(d,true)}
    setTitle();await startPlay(se,ep);
  }catch(err){if(tok===P.tok)showErr(err.message||'Something went wrong')}
}
function setTitle(){$('#pTitle b').textContent=P.d.title;$('#pTitle span').textContent=P.d.series?'S'+P.se+' · E'+P.ep:'';
  var n=nextEp(),b=$('#pNext');b.classList.toggle('hide',!n);if(n)$('span',b).textContent='Next · S'+n.se+' E'+n.ep}
function nextEp(){var d=P.d;if(!d||!d.series||!d.seasons.length)return null;var i=-1;d.seasons.forEach(function(s,k){if(s.se===P.se)i=k});if(i<0)return null;
  if(P.ep<d.seasons[i].count)return{se:P.se,ep:P.ep+1};var n=d.seasons[i+1];return n?{se:n.se,ep:1}:null}
function detach(){vid.pause();vid.removeAttribute('src');$$('track',vid).forEach(function(t){t.remove()});vid.load()}
async function startPlay(se,ep){
  var tok=++P.tok;P.se=se;P.ep=ep;P.caps=[];P.cc=-1;P.srcs=[];hideErr();spin(true);detach();setTitle();closeMenu();
  try{
    var pr={action:'play',slug:P.d.slug};if(P.d.series){pr.se=se;pr.ep=ep}if(P.d.id)pr.id=P.d.id;
    var res=await api(pr,0);if(tok!==P.tok)return;
    P.srcs=res.sources||[];if(!P.srcs.length)throw new Error(res.note||'No playable stream for this selection.');
    var want=LS.get('cp:q',''),qi=-1;P.srcs.forEach(function(s,i){if(qi<0&&s.resolution===want)qi=i});
    if(qi<0){var cap=LITE?720:1080;P.srcs.forEach(function(s,i){if(qi<0&&(parseInt(s.resolution,10)||0)<=cap)qi=i});if(qi<0)qi=P.srcs.length-1}
    setSource(qi,true);mediaMeta();loadCaps(res,tok);
  }catch(err){if(tok===P.tok)showErr(err.message||'Playback failed')}
}
function resumePos(){
  if(!P.d)return 0;var r=LS.get(posKey(P.d.slug,P.d.series?P.se:0,P.d.series?P.ep:0),null);
  return r&&r.t>10&&(!r.d||r.t<r.d-30)?r.t:0;
}
function setSource(i,fresh){
  var s=P.srcs[i];if(!s)return;P.qi=i;
  var keep=fresh?resumePos():vid.currentTime;P.seekTo=keep;P.auto=fresh?true:!vid.paused;
  spin(true);
  var streamUrl=(P.route==='proxy'||!s.url)?(s.proxy||s.url):s.url;
  vid.src=streamUrl;vid.load();
  if(fresh&&keep>10)toast('Resumed from '+fmtT(keep));
}
vid.addEventListener('loadedmetadata',function(){
  if(P.seekTo>1){try{vid.currentTime=P.seekTo}catch(e){}}P.seekTo=0;
  if(P.auto){var pr=vid.play();if(pr&&pr.catch)pr.catch(function(){spin(false);showUI()})}
  uiPlayState();refreshTime(true);
});
vid.addEventListener('error',function(){
  if(!vid.getAttribute('src')||!playerOpen)return;
  var code=vid.error?vid.error.code:0,t=vid.currentTime||P.seekTo||0;P.fails++;
  var curSrc=P.srcs[P.qi];
  if(P.route!=='proxy'&&curSrc&&curSrc.proxy){
    P.route='proxy';setSource(P.qi,false);if(t>5)P.seekTo=t;return;
  }
  if(P.fails===1){toast('Reconnecting…');startPlay(P.se,P.ep).then(function(){if(t>5)P.seekTo=t})}
  else if(P.srcs.length>1&&P.fails<=P.srcs.length){var n=(P.qi+1)%P.srcs.length;toast('Trying '+(P.srcs[n].resolution||'another quality'));setSource(n,false);P.auto=true;if(t>5)P.seekTo=t}
  else showErr('This stream could not be played (error '+code+'). It may be unavailable right now — try again in a moment.');
});
function showErr(m){spin(false);$('#pErrM').textContent=m;$('#pErr').classList.add('on');showUI()}
function hideErr(){$('#pErr').classList.remove('on')}
$('#pRetry').onclick=function(){if(P.d){P.fails=0;startPlay(P.se,P.ep)}else route(parseQ(),true)};
$('#pErrBack').onclick=function(){backFromPlayer()};
function spin(on){pSpin.classList.toggle('on',on)}
['waiting','seeking','loadstart'].forEach(function(n){vid.addEventListener(n,function(){spin(true)})});
['playing','canplay','seeked','loadeddata'].forEach(function(n){vid.addEventListener(n,function(){spin(false);if(n==='playing')P.fails=0})});

/* controls visibility */
function showUI(){
  uiOn=true;pUI.classList.remove('h');pEl.classList.remove('nocur');document.body.classList.remove('nocur');sw=pSeek.clientWidth||sw;refreshTime(true);
  clearTimeout(uiT);if(!vid.paused&&!menu&&!drag&&!$('#pErr').classList.contains('on'))uiT=setTimeout(hideUI,3300);
}
function hideUI(){if(vid.paused||menu||drag)return;uiOn=false;pUI.classList.add('h');pEl.classList.add('nocur');document.body.classList.add('nocur')}
var mmT=0;pEl.addEventListener('pointermove',function(e){if(e.pointerType==='touch')return;var n=Date.now();if(n-mmT>90){mmT=n;showUI()}});
window.addEventListener('resize',function(){sw=pSeek.clientWidth||sw});

/* time / progress */
function setBar(r){var pct=(Math.max(0,Math.min(1,r))*100).toFixed(2);pFill.style.width=pct+'%';pThumb.style.left=pct+'%'}
function refreshTime(force){
  if(!uiOn&&!force)return;var d=vid.duration||0,t=vid.currentTime||0;
  if(!isFinite(d))d=0;pCur.textContent=fmtT(t);pRem.textContent='-'+fmtT(Math.max(0,d-t));if(!drag)setBar(d?Math.min(1,t/d):0);
}
vid.addEventListener('timeupdate',function(){refreshTime();var n=Date.now();if(n-lastSave>5000){lastSave=n;saveProg()}});
vid.addEventListener('progress',function(){
  var d=vid.duration,t=vid.currentTime,b=vid.buffered,e=0;if(!d||!isFinite(d))return;
  for(var i=0;i<b.length;i++)if(b.start(i)<=t+.5&&b.end(i)>=t){e=b.end(i);break}
  pBuf.style.width=((Math.min(1,e/d))*100).toFixed(2)+'%';
});
function uiPlayState(){var p=vid.paused;$('#pPlay use').setAttribute('href',p?'#i-play':'#i-pause');$('#pPlay').setAttribute('aria-label',p?'Play':'Pause')}
vid.addEventListener('play',function(){uiPlayState();showUI()});
vid.addEventListener('pause',function(){uiPlayState();showUI();saveProg()});
vid.addEventListener('ended',function(){
  saveProg(true);var n=nextEp();
  if(n&&SET.autonext){toast('Next episode…');playEp(n.se,n.ep)}else showUI();
});
function playEp(se,ep){
  var np=Object.assign({},parseQ(),{action:'watch',se:se,ep:ep});history.replaceState(Object.assign({_in:history.state&&history.state._in},np),'','?'+new URLSearchParams(np).toString());
  P.fails=0;startPlay(se,ep);
}
function seekBy(s){var d=vid.duration||0;vid.currentTime=Math.max(0,Math.min(d||1e9,vid.currentTime+s));showUI()}
function togglePlay(){if(vid.paused){var p=vid.play();if(p&&p.catch)p.catch(function(){})}else vid.pause()}

/* progress persistence + Continue Watching */
function saveProg(finished){
  var d=P.d;if(!d)return;var t=vid.currentTime,du=vid.duration;if(!du||!isFinite(du)||t<5)return;
  var s=d.series?P.se:0,e=d.series?P.ep:0,key=posKey(d.slug,s,e),l=cwList().filter(function(x){return x.slug!==d.slug});
  if(finished||t>du-30){LS.del(key);var n=d.series?nextEp():null;
    if(n)l.unshift({slug:d.slug,title:d.title,poster:d.poster,type:d.type,year:d.year,rating:d.rating,se:n.se,ep:n.ep,t:0,d:0})}
  else{LS.set(key,{t:t,d:du});l.unshift({slug:d.slug,title:d.title,poster:d.poster,type:d.type,year:d.year,rating:d.rating,se:s,ep:e,t:t,d:du})}
  if(l.length>20)l.length=20;LS.set('cp:cw',l);
}
window.addEventListener('pagehide',function(){if(playerOpen)saveProg()});

/* seek bar */
function ratio(e){var r=pSeek.getBoundingClientRect();if(!r.width)return 0;return Math.min(1,Math.max(0,(e.clientX-r.left)/r.width))}
function tip(r){
  var d=vid.duration||0,pct=(Math.max(0,Math.min(1,r))*100).toFixed(2);
  pTip.textContent=fmtT(r*d);pTip.style.left=pct+'%';
  var pHov=document.getElementById('pHov');if(pHov)pHov.style.width=pct+'%';
}
pSeek.addEventListener('pointerdown',function(e){drag=true;pSeek.classList.add('d');try{pSeek.setPointerCapture(e.pointerId)}catch(_){}var r=ratio(e);setBar(r);tip(r);showUI()});
pSeek.addEventListener('pointermove',function(e){var r=ratio(e);tip(r);if(drag){setBar(r);var d=vid.duration||0;pCur.textContent=fmtT(r*d);pRem.textContent='-'+fmtT(Math.max(0,d-r*d))}});
function endDrag(e){if(!drag)return;drag=false;pSeek.classList.remove('d');var r=ratio(e),d=vid.duration;if(d&&isFinite(d))vid.currentTime=r*d;showUI()}
pSeek.addEventListener('pointerup',endDrag);pSeek.addEventListener('pointercancel',function(){drag=false;pSeek.classList.remove('d')});
pSeek.addEventListener('keydown',function(e){if(e.key==='ArrowLeft'){seekBy(-5);e.preventDefault()}else if(e.key==='ArrowRight'){seekBy(5);e.preventDefault()}});

/* tap / click gestures on the picture */
var lastTap=0,tapT=0,fl=$('#pFlash'),flT=0,coarse=window.matchMedia&&matchMedia('(pointer:coarse)').matches;
function flash(side){fl.textContent=(side<0?'−':'+')+'10s';fl.style.left=side<0?'12%':'auto';fl.style.right=side>0?'12%':'auto';fl.classList.add('on');clearTimeout(flT);flT=setTimeout(function(){fl.classList.remove('on')},520)}
$('#pTap').addEventListener('click',function(e){
  closeMenu();
  if(!coarse){togglePlay();showUI();return}
  var n=Date.now(),w=pEl.clientWidth,side=e.clientX<w*.33?-1:e.clientX>w*.67?1:0;
  if(n-lastTap<300){clearTimeout(tapT);lastTap=0;if(side){seekBy(side*10);flash(side)}else togglePlay();return}
  lastTap=n;clearTimeout(tapT);tapT=setTimeout(function(){uiOn&&!pUI.classList.contains('h')?(vid.paused?showUI():hideUIForce()):showUI()},300);
});
function hideUIForce(){uiOn=false;pUI.classList.add('h')}
$('#pTap').addEventListener('dblclick',function(){if(!coarse)toggleFs()});

/* buttons */
pUI.addEventListener('click',function(e){var b=e.target.closest('button');if(b&&e.detail>0)b.blur()});
$('#pPlay').onclick=function(){togglePlay()};
$('#pRew').onclick=function(){seekBy(-10)};$('#pFwd').onclick=function(){seekBy(10)};
$('#pBack').onclick=function(){backFromPlayer()};
$('#pNext').onclick=function(){var n=nextEp();if(n)playEp(n.se,n.ep)};
$('#pDl').onclick=function(){openAppSheet()};
$('#pPip').onclick=function(){if(document.pictureInPictureElement)document.exitPictureInPicture();else if(vid.requestPictureInPicture)vid.requestPictureInPicture().catch(function(){})};
$('#pFs').onclick=function(){toggleFs()};
function fsEl(){return document.fullscreenElement||document.webkitFullscreenElement}
function toggleFs(){
  if(fsEl()){
    if(screen.orientation&&screen.orientation.unlock){try{screen.orientation.unlock()}catch(_){}}
    (document.exitFullscreen||document.webkitExitFullscreen).call(document);return;
  }
  if(P.pfs){P.pfs=false;applyDock();return}
  var f=pEl.requestFullscreen||pEl.webkitRequestFullscreen;
  if(f){
    var r=f.call(pEl);
    if(r&&r.then){
      r.then(function(){
        if(screen.orientation&&screen.orientation.lock){try{screen.orientation.lock('landscape').catch(function(){})}catch(_){}}
      }).catch(function(){P.pfs=true;applyDock()});
    }else if(r&&r.catch){r.catch(function(){P.pfs=true;applyDock()})}
  }else{P.pfs=true;applyDock()}
}
var mq=window.matchMedia('(max-width:820px) and (orientation:portrait)');
function isDock(){return playerOpen&&mq.matches&&!fsEl()&&!P.pfs}
function applyDock(){
  var d=isDock(),full=playerOpen&&!d;
  pEl.classList.toggle('dock',d);pEl.classList.toggle('pfs',playerOpen&&P.pfs);
  document.body.classList.toggle('docked',d);document.body.classList.toggle('noscroll',full);document.body.classList.toggle('playing',full);
  $('#pFs use').setAttribute('href',(fsEl()||P.pfs)?'#i-fsx':'#i-fs');
  sw=pSeek.clientWidth||sw;if(playerOpen){refreshTime(true);if(menu)closeMenu()}
}
document.addEventListener('fullscreenchange',function(){
  try{if(fsEl()&&coarse&&screen.orientation&&screen.orientation.lock)screen.orientation.lock('landscape').catch(function(){});else if(!fsEl()&&screen.orientation&&screen.orientation.unlock)screen.orientation.unlock()}catch(e){}
  applyDock();
});
window.addEventListener('resize',function(){if(playerOpen)applyDock()});
if(mq.addEventListener)mq.addEventListener('change',function(){if(playerOpen)applyDock()});
function setVol(v){vid.volume=v;vid.muted=v===0;LS.set('cp:vol',v);uiVol()}
function uiVol(){$('#pVol use').setAttribute('href',vid.muted||vid.volume===0?'#i-mute':'#i-vol');$('#pVolR').value=vid.muted?0:vid.volume}
$('#pVol').onclick=function(){vid.muted=!vid.muted;if(!vid.muted&&vid.volume===0)vid.volume=.6;uiVol()};
$('#pVolR').addEventListener('input',function(){setVol(+this.value)});uiVol();

/* menus (speed / quality / subtitles) */
var SPEEDS=[.5,.75,1,1.25,1.5,2];
function openMenu(k){
  if(menu===k){closeMenu();return}menu=k;var h='';
  if(k==='sp'){h='<h4>Speed</h4>'+SPEEDS.map(function(s){return'<button data-v="'+s+'" class="'+(vid.playbackRate===s?'on':'')+'">'+(s===1?'Normal':s+'×')+'</button>'}).join('')}
  else if(k==='q'){h='<h4>Quality</h4>'+(P.srcs.length?P.srcs.map(function(s,i){return'<button data-v="'+i+'" class="'+(i===P.qi?'on':'')+'">'+esc(s.resolution||'Auto')+'<small>'+esc(/^\d+$/.test(s.size)?fmtB(s.size):s.size||'')+'</small></button>'}).join(''):'<div class="none">Loading…</div>')}
  else{h='<h4>Subtitles</h4><button data-v="-1" class="'+(P.cc<0?'on':'')+'">Off</button>'+P.caps.map(function(c,i){return'<button data-v="'+i+'" class="'+(P.cc===i?'on':'')+'">'+esc(c.name||c.lan||'Subtitle')+'</button>'}).join('')+(P.caps.length?'':'<div class="none">No subtitles for this title.</div>')}
  pMenu.innerHTML=h;pMenu.setAttribute('data-k',k);pMenu.classList.add('on');
  $$('#pTools .ib').forEach(function(b){b.classList.remove('on')});var bt=$(k==='sp'?'#pSp':k==='q'?'#pQ':'#pCc');bt&&bt.classList.add('on');showUI();
}
function closeMenu(){if(!menu)return;menu='';pMenu.classList.remove('on');$$('#pTools .ib').forEach(function(b){b.classList.remove('on')});showUI()}
$('#pSp').onclick=function(){openMenu('sp')};$('#pQ').onclick=function(){openMenu('q')};$('#pCc').onclick=function(){openMenu('cc')};
pMenu.addEventListener('click',function(e){
  var b=e.target.closest('button[data-v]');if(!b)return;var v=+b.getAttribute('data-v'),k=pMenu.getAttribute('data-k');
  if(k==='sp')vid.playbackRate=v;else if(k==='q'){if(v!==P.qi){LS.set('cp:q',P.srcs[v].resolution);setSource(v,false)}}else setCap(v);
  closeMenu();
});
function loadCaps(res,tok){
  var pr={action:'captions',slug:P.d.slug};if(P.d.id)pr.id=P.d.id;if(P.d.series){pr.se=P.se;pr.ep=P.ep}else{pr.used_se=res.used_se||0;pr.used_ep=res.used_ep||0}
  api(pr,0).then(function(r){if(tok!==P.tok)return;P.caps=r.captions||[];var want=LS.get('cp:cc','');if(want){var i=-1;P.caps.forEach(function(c,k){if(i<0&&c.lan===want)i=k});if(i>=0)setCap(i)}}).catch(function(){});
}
function setCap(i){
  $$('track',vid).forEach(function(t){t.remove()});P.cc=i;
  if(i<0){LS.set('cp:cc','');return}
  var c=P.caps[i];if(!c)return;LS.set('cp:cc',c.lan||'');
  var t=document.createElement('track');t.kind='subtitles';t.label=c.name||c.lan||'Subtitle';t.srclang=(c.lan||'en').slice(0,8);t.src=c.url;t.default=true;vid.appendChild(t);
  t.addEventListener('load',function(){t.track.mode='showing'});t.track.mode='showing';
}

/* keyboard */
document.addEventListener('keydown',function(e){
  if(!playerOpen||/INPUT|TEXTAREA/.test(e.target.tagName||'')&&e.target.id!=='pVolR')return;
  var k=e.key;if((k===' '||k==='Enter')&&e.target.tagName==='BUTTON')return;
  if(k===' '||k==='k'){e.preventDefault();togglePlay();showUI()}
  else if(k==='ArrowRight'){e.preventDefault();seekBy(5)}else if(k==='ArrowLeft'){e.preventDefault();seekBy(-5)}
  else if(k==='l'){seekBy(10)}else if(k==='j'){seekBy(-10)}
  else if(k==='ArrowUp'){e.preventDefault();setVol(Math.min(1,(vid.muted?0:vid.volume)+.1));showUI()}else if(k==='ArrowDown'){e.preventDefault();setVol(Math.max(0,vid.volume-.1));showUI()}
  else if(k==='m'){$('#pVol').click()}else if(k==='f'){toggleFs()}else if(k==='c'){openMenu('cc')}
  else if(k==='n'){var n=nextEp();if(n)playEp(n.se,n.ep)}
  else if(k==='Escape'){if(menu)closeMenu();else if(!fsEl())backFromPlayer()}
});

/* leave */
function backFromPlayer(){
  saveProg();var slug=P.d&&P.d.slug;
  if(history.state&&history.state._in)history.back();else go(slug?{action:'detail',slug:slug}:{action:'home'},true);
}
function closePlayer(){
  if(!playerOpen)return;saveProg();playerOpen=false;P.tok++;detach();
  if(fsEl()){try{(document.exitFullscreen||document.webkitExitFullscreen).call(document)}catch(e){}}
  if(document.pictureInPictureElement)document.exitPictureInPicture().catch(function(){});
  P.pfs=false;pEl.classList.remove('on','dock','pfs');document.body.classList.remove('nocur');['noscroll','docked','playing'].forEach(function(c){document.body.classList.remove(c)});clearTimeout(uiT);closeMenu();hideErr();spin(false);
  $$('.lg',pEl).forEach(unglass);P.d=null;
  if('mediaSession'in nv){nv.mediaSession.metadata=null}
}
function mediaMeta(){
  if(!('mediaSession'in nv)||!window.MediaMetadata)return;var d=P.d,ms=nv.mediaSession;
  ms.metadata=new MediaMetadata({title:d.title,artist:d.series?'Season '+P.se+' · Episode '+P.ep:'CinePulse',artwork:d.poster?[{src:d.poster,sizes:'512x768'}]:[]});
  var h=function(a,f){try{ms.setActionHandler(a,f)}catch(e){}};
  h('play',function(){togglePlay()});h('pause',function(){vid.pause()});h('seekbackward',function(){seekBy(-10)});h('seekforward',function(){seekBy(10)});
  h('nexttrack',nextEp()?function(){var n=nextEp();if(n)playEp(n.se,n.ep)}:null);
}

/* ═════════ Developer & Architecture Credits ═════════ */
function credit(force){
  showSheet(LOGO+'<h3>CinePulse Ecosystem</h3>'+
    '<p style="font-size:13px;color:var(--fg2);margin:8px 0 14px">Architected with Solid Glass &amp; Liquid Glass design system.</p>'+
    '<div style="text-align:left;background:rgba(255,255,255,.05);border-radius:18px;padding:14px;border:1px solid rgba(255,255,255,.1);margin-bottom:14px">'+
      '<b style="display:block;color:#fff;font-size:14px">ImAhmedZarir (Ahmed Ishtiaque Zarir)</b><small style="color:var(--fg2);display:block;margin-bottom:8px">Lead Contributor, Core Architecture &amp; API Integration · <a class="link" href="https://ahmedishtiaquezarir.pro.bd/" target="_blank" rel="noopener noreferrer">Portfolio</a></small>'+
      '<b style="display:block;color:#fff;font-size:14px">QWEA0</b><small style="color:var(--fg2);display:block;margin-bottom:8px">Liquid Glass Android Component Library</small>'+
      '<b style="display:block;color:#fff;font-size:14px">deeplethe</b><small style="color:var(--fg2);display:block;margin-bottom:8px">Solid Glass Design Tokens &amp; Physical Edge Specs</small>'+
      '<b style="display:block;color:#fff;font-size:14px">ExoPlayer / Media3 &amp; Coil</b><small style="color:var(--fg2);display:block">Media Engine &amp; Image Pipeline</small>'+
    '</div>'+
    '<div style="text-align:left;font-size:11.5px;color:var(--fg3);line-height:1.45;margin-bottom:16px">'+
      '<p><b>Non-Hosting Policy:</b> CinePulse does NOT host or stream copyrighted media. API exclusively indexes public metadata.</p>'+
      '<p style="margin-top:4px"><b>Free Hosting &amp; DNS:</b> Hosted on free cloud tier. If blocked by your ISP, use custom DNS (1.1.1.1, 8.8.8.8) or a VPN.</p>'+
      '<p style="margin-top:4px"><b>Donations &amp; Support:</b> Donate/contact: <a class="link" href="mailto:ahmedishtiaquezarir@gmail.com">ahmedishtiaquezarir@gmail.com</a> · Telegram: <a class="link" href="https://t.me/@imahmedzarir" target="_blank" rel="noopener noreferrer">https://t.me/@imahmedzarir</a></p>'+
      '<p style="margin-top:4px"><b>Zero Tracking:</b> API collects no logs, no user tracking, and no external storage of watch history.</p>'+
      '<p style="margin-top:4px"><b>Usage Terms:</b> Free to use strictly for non-commercial educational use.</p>'+
      '<p style="margin-top:4px"><b>Client Security:</b> Source code is private and client connections cannot be cryptographically secured on untrusted environments.</p>'+
    '</div>'+
    '<div class="row"><button class="btn lg-solid press" id="cGo" style="width:100%">Close</button></div>',true);
  $('#cGo').onclick=function(){hideSheet(true)};
}

/* ═════════ Boot ═════════ */
if('scrollRestoration'in history)history.scrollRestoration='manual';
['#navPill','#tabbar','#btnSearch'].forEach(function(s){glass($(s))});
history.replaceState(null,'',location.href);
route(parseQ(),false);
lens();

})();
</script>
</body>
</html>