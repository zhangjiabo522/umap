<?php
require_once __DIR__ . '/db.php';

try {
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS `travel_tags` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `category` VARCHAR(20) NOT NULL,
            `name` VARCHAR(50) NOT NULL,
            `opposite_id` INT UNSIGNED DEFAULT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `tier` TINYINT NOT NULL DEFAULT 3 COMMENT '1=quick 2=medium 3=full',
            UNIQUE KEY `uk_name` (`name`),
            KEY `idx_category` (`category`),
            KEY `idx_tier` (`tier`),
            CONSTRAINT `fk_tt_opposite` FOREIGN KEY (`opposite_id`) REFERENCES `travel_tags`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_preferences` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `tag_id` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_user_tag` (`user_id`, `tag_id`),
            CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_up_tag` FOREIGN KEY (`tag_id`) REFERENCES `travel_tags`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_custom_tags` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(50) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_user_id` (`user_id`),
            CONSTRAINT `fk_uct_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Tables created.\n";

    // Seed tags
    $count = $db->query("SELECT COUNT(*) FROM travel_tags")->fetchColumn();
    if ($count > 0) {
        echo "Tags already seeded ($count tags).\n";
        exit;
    }

    // Categories and their tags with opposites (paired by position for logic)
    $categories = [
        '旅行方式' => [
            ['独立自由行', '跟团游', 1],
            ['深度慢游', '打卡快游', 1],
            ['自驾探索', '公共交通', 1],
            ['说走就走', '精心规划', 1],
            ['网红地打卡', '小众秘境', 1],
        ],
        '目的地偏好' => [
            ['海滨海岛', '山地高原', 2],
            ['繁华都市', '宁静乡村', 2],
            ['热带地区', '寒带地区', 2],
            ['国内游', '出境游', 2],
            ['自然景观', '人文古迹', 2],
            ['热门旅游城市', '冷门小众地点', 2],
            ['东部沿海', '西部内陆', 2],
        ],
        '活动偏好' => [
            ['徒步登山', '潜水冲浪', 3],
            ['滑雪滑冰', '沙滩日光浴', 3],
            ['骑行运动', '自驾兜风', 3],
            ['摄影采风', '美食探店', 3],
            ['博物馆美术馆', '主题乐园', 3],
            ['温泉SPA', '极限运动', 3],
            ['观鸟赏花', '野生动物', 3],
            ['露营野炊', '豪华度假', 3],
            ['文化节庆', '音乐演出', 3],
            ['夜市逛街', '早市探访', 3],
        ],
        '住宿选择' => [
            ['精品酒店', '民宿客栈', 3],
            ['青年旅舍', '度假村', 3],
            ['露营帐篷', '房车旅行', 3],
            ['树屋船屋', '城堡庄园', 3],
            ['胶囊旅馆', '温泉酒店', 3],
        ],
        '预算水平' => [
            ['穷游背包客', '奢华享受型', 2],
            ['性价比优先', '体验至上', 2],
            ['精打细算', '随心消费', 2],
        ],
        '出行同伴' => [
            ['独自旅行', '结伴同行', 1],
            ['家庭亲子', '情侣浪漫', 1],
            ['朋友聚会', '独自冒险', 1],
            ['带宠物出行', '不带宠物', 1],
            ['长者同游', '孩童友好', 1],
        ],
        '季节偏好' => [
            ['春季赏花', '秋季赏叶', 2],
            ['夏日避暑', '冬日暖阳', 2],
            ['四季皆宜', '特定季节', 2],
            ['雨季出行', '旱季出行', 2],
            ['节假日出行', '错峰出行', 2],
        ],
        '交通方式' => [
            ['飞机直达', '火车慢旅', 2],
            ['高铁出行', '普通列车', 2],
            ['自驾游', '包车游', 2],
            ['邮轮旅行', '房车旅行', 2],
            ['骑行旅行', '摩托车旅行', 2],
        ],
        '美食偏好' => [
            ['地道小吃', '米其林餐厅', 2],
            ['街头美食', '高级料理', 2],
            ['素食主义', '无肉不欢', 2],
            ['甜品爱好者', '咸味控', 2],
            ['咖啡文化', '茶文化', 2],
            ['海鲜盛宴', '山珍野味', 2],
            ['自己烹饪', '到店品尝', 2],
        ],
        '旅行节奏' => [
            ['早起型', '夜猫子型', 2],
            ['慢节奏闲逛', '高效率覆盖', 2],
            ['随遇而安', '行程紧凑', 2],
            ['休息为主', '探索为主', 2],
            ['灵活变动', '严格按计划', 2],
        ],
        '文化兴趣' => [
            ['历史遗迹', '现代建筑', 2],
            ['宗教寺庙', '民俗风情', 2],
            ['世界遗产', '当地生活', 2],
            ['考古遗址', '科技展览', 2],
            ['老街古镇', 'CBD商区', 2],
            ['美术馆画廊', '街头艺术', 2],
        ],
        '自然偏好' => [
            ['高山草甸', '原始森林', 3],
            ['沙漠戈壁', '湿地沼泽', 3],
            ['峡谷瀑布', '溶洞探险', 3],
            ['雪山冰川', '火山地热', 3],
            ['草原牧场', '田园风光', 3],
            ['湖泊河流', '海岸线', 3],
            ['国家公园', '自然保护区', 3],
        ],
        '冒险程度' => [
            ['舒适安全', '冒险刺激', 2],
            ['低强度', '高强度', 2],
            ['无风险', '敢挑战', 2],
            ['成熟路线', '无人区', 2],
            ['轻装简行', '重装远征', 2],
        ],
        '特殊兴趣' => [
            ['星空观测', '日出日落', 3],
            ['温泉泡汤', '滑雪运动', 3],
            ['葡萄酒庄', '啤酒厂', 3],
            ['动漫圣地', '电影取景', 3],
            ['手工艺体验', '农事体验', 3],
            ['观鸟圣地', '赏鲸之旅', 3],
            ['热气球', '滑翔伞', 3],
            ['深海潜水', '漂流探险', 3],
        ],
    ];

    $db->beginTransaction();

    // Collect all unique tag names from pairs
    $allTags = []; // name => [category, tier]
    foreach ($categories as $category => $tags) {
        foreach ($tags as $tag) {
            if (!isset($allTags[$tag[0]])) $allTags[$tag[0]] = [$category, $tag[2]];
            if (!isset($allTags[$tag[1]])) $allTags[$tag[1]] = [$category, $tag[2]];
        }
    }

    // Insert all unique tags
    $insertStmt = $db->prepare("INSERT INTO travel_tags (category, name, sort_order, tier) VALUES (?, ?, ?, ?)");
    $nameToId = [];
    $order = 0;
    foreach ($allTags as $name => $info) {
        $order++;
        $insertStmt->execute([$info[0], $name, $order, $info[1]]);
        $nameToId[$name] = $db->lastInsertId();
    }

    // Set opposite_id links based on pair definitions
    $updateStmt = $db->prepare("UPDATE travel_tags SET opposite_id = ? WHERE id = ?");
    $linked = [];
    foreach ($categories as $category => $tags) {
        foreach ($tags as $tag) {
            $a = $tag[0]; $b = $tag[1];
            if (isset($nameToId[$a], $nameToId[$b]) && !isset($linked[$a])) {
                $updateStmt->execute([$nameToId[$b], $nameToId[$a]]);
                $updateStmt->execute([$nameToId[$a], $nameToId[$b]]);
                $linked[$a] = true; $linked[$b] = true;
            }
        }
    }

    $db->commit();
    $total = $db->query("SELECT COUNT(*) FROM travel_tags")->fetchColumn();
    echo "Seeded $total travel tags.\n";

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    die("Error: " . $e->getMessage() . "\n");
}
