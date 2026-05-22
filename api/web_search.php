<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['success' => false, 'message' => 'Method not allowed'], 405); }

session_start();
if (empty($_SESSION['user_id'])) { json_response(['success' => false, 'message' => '未登录'], 401); }

$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');
if ($query === '' || mb_strlen($query) > 200) {
    json_response(['success' => false, 'message' => '搜索词无效'], 400);
}

$results = bing_search($query);
json_response(['success' => true, 'query' => $query, 'results' => $results]);

function bing_search(string $query): array {
    $url = 'https://www.bing.com/search?q=' . urlencode($query) . '&setlang=zh-CN&count=8';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            'Accept-Language: zh-CN,zh;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return [];

    $results = [];
    // Extract search result blocks
    if (preg_match_all('/<li class="b_algo"[^>]*>(.*?)<\/li>/s', $html, $matches)) {
        foreach ($matches[1] as $block) {
            $title = '';
            $snippet = '';
            $link = '';

            if (preg_match('/<a[^>]*href="(https?:\/\/[^"]+)"[^>]*>(.*?)<\/a>/s', $block, $m)) {
                $link = $m[1];
                $title = strip_tags($m[2]);
            }
            if (preg_match('/<p[^>]*>(.*?)<\/p>/s', $block, $m)) {
                $snippet = trim(strip_tags($m[1]));
            }

            $title = trim(preg_replace('/\s+/', ' ', $title));
            $snippet = trim(preg_replace('/\s+/', ' ', $snippet));

            if ($title !== '' && $snippet !== '') {
                $results[] = [
                    'title' => mb_substr($title, 0, 80),
                    'snippet' => mb_substr($snippet, 0, 300),
                    'url' => $link,
                ];
            }
        }
    }

    return array_slice($results, 0, 6);
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
