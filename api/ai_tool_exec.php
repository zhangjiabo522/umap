<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

loadEnv(__DIR__ . '/../.env');

define('AMAP_KEY', $_ENV['AMAP_WEB_KEY'] ?? $_ENV['AMAP_KEY'] ?? '');

header('Content-Type: application/json; charset=utf-8');
corsHeaders();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'error' => '不支持的请求方法'], 405); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$tool = $input['tool'] ?? '';
$params = $input['params'] ?? [];

if (!$tool) { jsonResponse(['success' => false, 'error' => '缺少工具名称']); exit; }

try {
    $result = match ($tool) {
        'search_places' => searchPlaces($params),
        'get_nearby' => getNearby($params),
        'get_weather' => getWeather($params),
        'web_search' => webSearch($params),
        'fetch_page' => fetchPage($params),
        'search_hotels' => searchHotels($params),
        default => throw new RuntimeException("未知工具: {$tool}"),
    };
    jsonResponse(['success' => true, 'tool' => $tool, 'result' => $result]);
} catch (RuntimeException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("Tool exec error: {$e->getMessage()}");
    jsonResponse(['success' => false, 'error' => '工具执行失败，请稍后重试']);
}

function amapGet(string $path, array $query): array {
    $query['key'] = AMAP_KEY;
    $url = 'https://restapi.amap.com/v3' . $path . '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $status < 200 || $status >= 300) {
        throw new RuntimeException('AMap API request failed: ' . ($err ?: "HTTP {$status}"));
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || ($data['status'] ?? '0') !== '1') {
        throw new RuntimeException('AMap API error: ' . ($data['info'] ?? 'unknown'));
    }
    return $data;
}

function searchPlaces(array $params): array {
    $keyword = trim($params['keyword'] ?? '');
    $city = trim($params['city'] ?? '');
    if (!$keyword) throw new RuntimeException('缺少搜索关键词');

    $data = amapGet('/place/text', [
        'keywords' => $keyword,
        'city' => $city ?: '全国',
        'citylimit' => $city ? 'true' : 'false',
        'offset' => 10,
        'extensions' => 'all',
    ]);

    $places = [];
    foreach ($data['pois'] ?? [] as $poi) {
        $locParts = explode(',', $poi['location'] ?? '');
        $places[] = [
            'name' => $poi['name'] ?? '',
            'address' => $poi['address'] ?? '',
            'city' => $poi['cityname'] ?? $city,
            'location' => $poi['location'] ?? '',
            'type' => $poi['type'] ?? '',
            'rating' => $poi['biz_ext']['rating'] ?? '',
            'cost' => $poi['biz_ext']['cost'] ?? '',
            'distance' => $poi['distance'] ?? '',
        ];
    }

    return [
        'keyword' => $keyword,
        'city' => $city,
        'count' => count($places),
        'places' => $places,
    ];
}

function getNearby(array $params): array {
    $lng = floatval($params['lng'] ?? 0);
    $lat = floatval($params['lat'] ?? 0);
    $type = trim($params['type'] ?? '');
    $radius = intval($params['radius'] ?? 3000);
    if (!$lng || !$lat) throw new RuntimeException('缺少中心坐标');

    $query = [
        'location' => "{$lng},{$lat}",
        'radius' => min($radius, 10000),
        'offset' => 10,
        'extensions' => 'all',
    ];
    if ($type) $query['keywords'] = $type;

    $data = amapGet('/place/around', $query);

    $places = [];
    foreach ($data['pois'] ?? [] as $poi) {
        $locParts = explode(',', $poi['location'] ?? '');
        $places[] = [
            'name' => $poi['name'] ?? '',
            'address' => $poi['address'] ?? '',
            'location' => $poi['location'] ?? '',
            'type' => $poi['type'] ?? '',
            'rating' => $poi['biz_ext']['rating'] ?? '',
            'cost' => $poi['biz_ext']['cost'] ?? '',
            'distance' => $poi['distance'] ?? '',
        ];
    }

    return [
        'center' => "{$lng},{$lat}",
        'radius' => $radius,
        'type' => $type,
        'count' => count($places),
        'places' => $places,
    ];
}

function getWeather(array $params): array {
    $city = trim($params['city'] ?? '');
    if (!$city) throw new RuntimeException('缺少城市名称');

    $data = amapGet('/weather/weatherInfo', [
        'city' => $city,
        'extensions' => 'all',
    ]);

    $forecasts = $data['forecasts'][0] ?? [];
    $casts = $forecasts['casts'] ?? [];

    return [
        'city' => $forecasts['city'] ?? $city,
        'province' => $forecasts['province'] ?? '',
        'reportTime' => $forecasts['reporttime'] ?? '',
        'forecast' => array_slice(array_map(fn($c) => [
            'date' => $c['date'] ?? '',
            'week' => $c['week'] ?? '',
            'dayWeather' => $c['dayweather'] ?? '',
            'nightWeather' => $c['nightweather'] ?? '',
            'dayTemp' => $c['daytemp'] ?? '',
            'nightTemp' => $c['nighttemp'] ?? '',
            'dayWind' => $c['daywind'] ?? '',
            'nightWind' => $c['nightwind'] ?? '',
            'dayPower' => $c['daypower'] ?? '',
            'nightPower' => $c['nightpower'] ?? '',
        ], $casts), 0, 4),
    ];
}

function fetchPage(array $params): array {
    $url = trim($params['url'] ?? '');
    if (!$url) throw new RuntimeException('缺少网页URL');
    if (!preg_match('#^https?://#', $url)) throw new RuntimeException('URL格式不正确');

    $ch = curl_init('http://127.0.0.1:8889/extract');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $url], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException('网页提取服务请求失败: ' . $err);
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new RuntimeException('网页提取返回异常');

    if (!($data['success'] ?? false)) {
        throw new RuntimeException($data['error'] ?? '网页提取失败');
    }

    return [
        'url' => $data['url'] ?? $url,
        'title' => $data['title'] ?? '',
        'author' => $data['author'] ?? '',
        'date' => $data['date'] ?? '',
        'hostname' => $data['hostname'] ?? '',
        'text' => $data['text'] ?? '',
        'length' => $data['length'] ?? 0,
    ];
}

function searchHotels(array $params): array {
    $city = trim($params['city'] ?? '');
    $area = trim($params['area'] ?? '');
    $checkin = trim($params['checkin'] ?? '');
    $checkout = trim($params['checkout'] ?? '');
    if (!$city && !$area) throw new RuntimeException('缺少城市或区域名称');

    $location = $area ? "{$city}{$area}" : $city;
    $dateHint = ($checkin && $checkout) ? " {$checkin}入住 {$checkout}退房" : '';

    // Build optimized search queries for hotel price comparison
    $queries = [
        "{$location} 酒店 价格 预订{$dateHint}",
        "{$location} 酒店 推荐 性价比 排行",
    ];

    $allResults = [];
    foreach ($queries as $q) {
        $url = 'http://127.0.0.1:8888/search?format=json&categories=general&language=zh-CN&q=' . urlencode($q);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) continue;

        $data = json_decode($resp, true);
        if (!is_array($data)) continue;

        foreach ($data['results'] ?? [] as $r) {
            $title = $r['title'] ?? '';
            $content = $r['content'] ?? '';
            $url = $r['url'] ?? '';
            $engine = implode(',', $r['engines'] ?? []);
            // Skip irrelevant results
            if (stripos($title . $content, '百度百科') !== false) continue;
            if (stripos($title . $content, '维基百科') !== false) continue;
            // Deduplicate by URL
            $key = md5($url);
            if (isset($allResults[$key])) continue;
            $allResults[$key] = [
                'title' => $title,
                'content' => $content,
                'url' => $url,
                'source' => $engine ?: 'web',
            ];
        }
    }

    $results = array_values(array_slice($allResults, 0, 12));
    return [
        'city' => $city,
        'area' => $area,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'count' => count($results),
        'results' => $results,
        'note' => '价格数据来自搜索引擎聚合结果，仅供参考。实际价格请以预订网站为准。',
    ];
}

function webSearch(array $params): array {
    $query = trim($params['query'] ?? '');
    if (!$query) throw new RuntimeException('缺少搜索关键词');

    $url = 'http://127.0.0.1:8888/search?format=json&categories=general&language=zh-CN&q=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $status < 200 || $status >= 300) {
        throw new RuntimeException('搜索请求失败: ' . ($err ?: "HTTP {$status}"));
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new RuntimeException('搜索返回数据异常');

    $results = [];
    foreach ($data['results'] ?? [] as $r) {
        $results[] = [
            'title' => $r['title'] ?? '',
            'url' => $r['url'] ?? '',
            'content' => $r['content'] ?? '',
            'engine' => implode(', ', $r['engines'] ?? []),
        ];
    }

    return [
        'query' => $query,
        'count' => count($results),
        'results' => $results,
    ];
}
