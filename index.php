<?php
declare(strict_types=1);

/**
 * CubeSat Explorer — stateless edition
 * PHP 8.5+
 *
 * No database, cache, logs, cron, API keys, or hard-coded SatNOGS status values.
 * The current API response is the source of truth on every request.
 */

const SATNOGS_API = 'https://db.satnogs.org/api/satellites/';
const SATNOGS_SITE = 'https://db.satnogs.org/';
const SATNOGS_MEDIA = 'https://db-satnogs.freetls.fastly.net/media/';

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function safeUrl(?string $url): ?string {
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true) ? $url : null;
}

function formatDate(mixed $date): string {
    if (!is_string($date) || trim($date) === '') return 'Unknown';
    try { return (new DateTimeImmutable($date))->format('M j, Y'); } catch (Throwable) { return 'Unknown'; }
}

function listText(mixed $value): string {
    if (is_array($value)) {
        $flat = [];
        foreach ($value as $item) {
            if (is_scalar($item)) $text = trim((string)$item);
            elseif (is_array($item)) $text = trim((string)($item['name'] ?? $item['code'] ?? $item['label'] ?? ''));
            else $text = '';
            if ($text !== '') $flat[] = $text;
        }
        return $flat ? implode(', ', array_unique($flat)) : 'Unknown';
    }
    return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : 'Unknown';
}

function satelliteImage(array $sat): string {
    $image = trim((string)($sat['image'] ?? ''));
    if ($image === '') return 'assets/img/satellite-placeholder.svg';
    if (safeUrl($image)) return $image;
    return SATNOGS_MEDIA . ltrim($image, '/');
}

function apiGet(string $url): array {
    $body = false;
    $httpCode = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'CubeSatExplorer/3.0 (+https://cubesat.chrisjones.online)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) $transportError = curl_error($ch);
        curl_close($ch);
    }

    if (($body === false || $httpCode < 200 || $httpCode >= 300) && (bool)filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET', 'timeout' => 30, 'ignore_errors' => true,
                'follow_location' => 1, 'max_redirects' => 5,
                'header' => "Accept: application/json\r\nUser-Agent: CubeSatExplorer/3.0 (+https://cubesat.chrisjones.online)\r\nConnection: close\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);
        $streamBody = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $streamCode = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) $streamCode = (int)$m[1];
        if ($streamBody !== false) { $body = $streamBody; $httpCode = $streamCode ?: 200; }
    }

    if (!is_string($body) || $httpCode < 200 || $httpCode >= 300) {
        $detail = is_string($body) ? trim((string)preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 250)))) : $transportError;
        throw new RuntimeException('SatNOGS returned ' . ($httpCode ? "HTTP {$httpCode}" : 'a connection error') . ($detail ? ": {$detail}" : '.'));
    }

    try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('SatNOGS returned invalid JSON.'); }
    if (!is_array($data)) throw new RuntimeException('SatNOGS returned an unexpected response.');
    return $data;
}

/** Fetch the API as-is and follow only the pagination links supplied by the API. */
function fetchAllSatellites(): array {
    $url = SATNOGS_API;
    $satellites = [];
    $visited = [];

    while ($url !== null) {
        if (isset($visited[$url])) throw new RuntimeException('SatNOGS returned a pagination loop.');
        $visited[$url] = true;
        $data = apiGet($url);

        // Compatible with both plain-list and paginated API responses.
        if (array_is_list($data)) {
            $satellites = array_merge($satellites, $data);
            break;
        }

        $results = $data['results'] ?? null;
        if (!is_array($results)) throw new RuntimeException('SatNOGS changed its satellite response format.');
        $satellites = array_merge($satellites, $results);

        $next = safeUrl(is_string($data['next'] ?? null) ? $data['next'] : null);
        // Follow pagination only on the same API host; never follow arbitrary URLs from JSON.
        $apiHost = parse_url(SATNOGS_API, PHP_URL_HOST);
        $nextHost = $next ? parse_url($next, PHP_URL_HOST) : null;
        $url = ($next && $nextHost === $apiHost) ? $next : null;
    }
    return $satellites;
}

function recordStatus(array $sat): string {
    foreach (['status', 'sat_status', 'orbit_status'] as $key) {
        if (isset($sat[$key]) && is_scalar($sat[$key]) && trim((string)$sat[$key]) !== '') return trim((string)$sat[$key]);
    }
    return 'Unknown';
}

/** Presentation only: data/status values still come from SatNOGS dynamically. */
function statusPresentation(string $status): array {
    $normalized = strtolower(trim($status));
    if (preg_match('/re[ -]?enter|decay|dead|destroy|lost/', $normalized)) return ['Dead', 'dead'];
    if (preg_match('/future|planned|scheduled|prelaunch|pre-launch/', $normalized)) return ['Future', 'future'];
    if (preg_match('/orbit|operat|active|alive/', $normalized)) return ['Operational', 'alive'];
    return [ucwords($status), 'neutral'];
}

function queryUrl(array $changes = []): string {
    $params = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]); else $params[$key] = $value;
    }
    return '?' . http_build_query($params);
}

$error = null;
$allSatellites = [];
try { $allSatellites = fetchAllSatellites(); }
catch (Throwable $ex) { $error = $ex->getMessage(); }

// Statuses are discovered from the live feed. No status enum/mapping lives in this app.
$statusCounts = [];
foreach ($allSatellites as $sat) {
    if (!is_array($sat)) continue;
    $s = recordStatus($sat);
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}
uksort($statusCounts, 'strnatcasecmp');

$requestedStatus = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$activeStatus = ($requestedStatus !== '' && array_key_exists($requestedStatus, $statusCounts)) ? $requestedStatus : '';
$filteredSatellites = $activeStatus === '' ? $allSatellites : array_values(array_filter($allSatellites, static fn($sat) => is_array($sat) && recordStatus($sat) === $activeStatus));

$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if ($query !== '') {
    $needle = mb_strtolower($query, 'UTF-8');
    $filteredSatellites = array_values(array_filter($filteredSatellites, static function ($sat) use ($needle): bool {
        if (!is_array($sat)) return false;
        $haystack = mb_strtolower(implode(' ', [
            (string)($sat['name'] ?? ''), listText($sat['names'] ?? $sat['alt_names'] ?? null),
            (string)($sat['sat_id'] ?? $sat['id'] ?? ''), (string)($sat['norad_cat_id'] ?? $sat['norad'] ?? ''),
            listText($sat['operator'] ?? null), listText($sat['countries'] ?? $sat['country'] ?? null), recordStatus($sat)
        ]), 'UTF-8');
        return str_contains($haystack, $needle);
    }));
}

$perPage = 24;
$totalResults = count($filteredSatellites);
$totalPages = max(1, (int)ceil($totalResults / $perPage));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$page = min($page, $totalPages);
$satellites = array_slice($filteredSatellites, ($page - 1) * $perPage, $perPage);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark"><meta name="theme-color" content="#10141d">
<meta name="description" content="Explore satellites using live public SatNOGS data.">
<title>CubeSat Explorer</title><link rel="icon" href="favicon.ico" sizes="any">
<link rel="stylesheet" href="assets/css/style.css?v=4"><script src="assets/js/app.js?v=4" defer></script>
</head>
<body>
<header class="topbar">
<a class="brand" href="./" aria-label="CubeSat Explorer home"><span class="brand-mark" aria-hidden="true">◈</span><span>CubeSat <b>Explorer</b></span></a>
<a class="source-link" href="<?= e(SATNOGS_SITE) ?>" target="_blank" rel="noopener noreferrer">Data by SatNOGS <span aria-hidden="true">↗</span></a>
</header>
<main>
<section class="hero"><p class="eyebrow">LIVE SATELLITE DIRECTORY</p><h1>Explore what’s orbiting Earth.</h1><p class="hero-copy">Satellite records, categories and metadata are discovered directly from SatNOGS on every request. Nothing is stored locally.</p></section>

<?php if ($error): ?>
<section class="empty-state error-state"><div class="empty-icon">!</div><h2>Couldn’t reach the satellite database</h2><p><?= e($error) ?></p><a class="material-button" href="./">Try again</a></section>
<?php else: ?>
<section class="controls" aria-label="Satellite filters">
<nav class="status-tabs" aria-label="Orbit status">
<a class="status-tab neutral <?= $activeStatus === '' ? 'active' : '' ?>" href="<?= e(queryUrl(['status' => null, 'page' => null])) ?>" <?= $activeStatus === '' ? 'aria-current="page"' : '' ?>><span class="status-dot"></span>All <small><?= count($allSatellites) ?></small></a>
<?php foreach ($statusCounts as $statusName => $count): ?>
<?php [$statusLabel, $statusClass] = statusPresentation($statusName); ?>
<a class="status-tab <?= e($statusClass) ?> <?= $activeStatus === $statusName ? 'active' : '' ?>" href="<?= e(queryUrl(['status' => $statusName, 'page' => null])) ?>" <?= $activeStatus === $statusName ? 'aria-current="page"' : '' ?>><span class="status-dot"></span><?= e($statusLabel) ?> <small><?= $count ?></small></a>
<?php endforeach; ?>
</nav>
<form class="search-box" method="get" action="./" role="search"><span class="search-icon" aria-hidden="true">⌕</span><span class="sr-only">Search satellites</span><?php if ($activeStatus !== ''): ?><input type="hidden" name="status" value="<?= e($activeStatus) ?>"><?php endif; ?><input id="satellite-search" name="q" type="search" value="<?= e($query) ?>" placeholder="Search name, NORAD ID, operator…" autocomplete="off"><button class="search-submit" type="submit">Search</button></form>
</section>
<div class="results-bar"><p><strong id="result-count"><?= $totalResults ?></strong> satellites<?= $activeStatus !== '' ? ' · ' . e(statusPresentation($activeStatus)[0]) : '' ?><?= $query !== '' ? ' · search: “' . e($query) . '”' : '' ?><?php if ($totalResults): ?> · page <?= $page ?> of <?= $totalPages ?><?php endif; ?></p><?php if ($query !== ''): ?><a class="text-button" href="<?= e(queryUrl(['q' => null, 'page' => null])) ?>">Clear search</a><?php endif; ?></div>

<?php if (!$satellites): ?>
<section class="empty-state"><h2>No satellites found</h2><p>The live feed has no records in this category.</p></section>
<?php else: ?>
<section class="satellite-grid" id="satellite-grid" aria-live="polite">
<?php foreach ($satellites as $sat): if (!is_array($sat)) continue;
$id = trim((string)($sat['sat_id'] ?? $sat['id'] ?? ''));
$name = trim((string)($sat['name'] ?? $sat['names'][0] ?? 'Unnamed satellite')) ?: 'Unnamed satellite';
$norad = $sat['norad_cat_id'] ?? $sat['norad'] ?? null;
$operator = listText($sat['operator'] ?? null);
$countries = listText($sat['countries'] ?? $sat['country'] ?? null);
$altNames = listText($sat['names'] ?? $sat['alt_names'] ?? null);
$statusName = recordStatus($sat);
[$statusLabel, $statusClass] = statusPresentation($statusName);
$website = safeUrl(is_string($sat['website'] ?? null) ? $sat['website'] : null);
$profile = safeUrl(is_string($sat['url'] ?? null) ? $sat['url'] : null);
if (!$profile && $id !== '') $profile = SATNOGS_SITE . 'satellite/' . rawurlencode($id) . '/';
$searchText = strtolower(implode(' ', [$name,$id,(string)$norad,$operator,$countries,$altNames,$statusName]));
?>
<article class="sat-card" data-search="<?= e($searchText) ?>">
<div class="image-wrap"><img src="<?= e(satelliteImage($sat)) ?>" alt="<?= e($name) ?>" loading="lazy" decoding="async" data-fallback="assets/img/satellite-placeholder.svg"><span class="card-status <?= e($statusClass) ?>"><span></span><?= e($statusLabel) ?></span></div>
<div class="card-body"><div class="title-row"><div><p class="sat-id"><?= $norad ? 'NORAD ' . e($norad) : ($id !== '' ? 'SAT ' . e($id) : 'SATELLITE') ?></p><h2><?= e($name) ?></h2></div></div>
<dl class="details">
<div><dt>Launch</dt><dd><?= e(formatDate($sat['launched'] ?? $sat['launch_date'] ?? null)) ?></dd></div>
<div><dt>Operator</dt><dd title="<?= e($operator) ?>"><?= e($operator) ?></dd></div>
<div><dt>Country</dt><dd title="<?= e($countries) ?>"><?= e($countries) ?></dd></div>
<div><dt>Status</dt><dd><?= e($statusLabel) ?></dd></div>
<?php if ($id !== ''): ?><div><dt>SatNOGS ID</dt><dd class="mono" title="<?= e($id) ?>"><?= e($id) ?></dd></div><?php endif; ?>
</dl>
<?php if ($altNames !== 'Unknown' && strcasecmp($altNames, $name) !== 0): ?><p class="aliases"><span>Also known as</span> <?= e($altNames) ?></p><?php endif; ?>
<div class="card-actions"><?php if ($profile): ?><a class="material-button" href="<?= e($profile) ?>" target="_blank" rel="noopener noreferrer">SatNOGS details <span aria-hidden="true">↗</span></a><?php endif; ?><?php if ($website): ?><a class="icon-button" href="<?= e($website) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open <?= e($name) ?> mission website" title="Mission website">◎</a><?php endif; ?></div>
</div></article>
<?php endforeach; ?>
</section>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Satellite pages">
<?php if ($page > 1): ?><a class="page-button" href="<?= e(queryUrl(['page' => $page - 1])) ?>" rel="prev">← Previous</a><?php else: ?><span class="page-button disabled">← Previous</span><?php endif; ?>
<span class="page-info">Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong></span>
<?php if ($page < $totalPages): ?><a class="page-button" href="<?= e(queryUrl(['page' => $page + 1])) ?>" rel="next">Next →</a><?php else: ?><span class="page-button disabled">Next →</span><?php endif; ?>
</nav>
<?php endif; ?>
<section class="empty-state" id="search-empty" hidden><div class="empty-icon">⌕</div><h2>No matching satellites</h2><p>Try a different name, NORAD ID, operator, country or status.</p></section>
<?php endif; endif; ?>
</main>
<footer><p>Live satellite data provided by <a href="<?= e(SATNOGS_SITE) ?>" target="_blank" rel="noopener noreferrer">SatNOGS DB</a>, a Libre Space Foundation project. No satellite data is stored by this site.</p></footer>
</body></html>
