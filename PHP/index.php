<?php
session_start();

/**
 * 获取客户端IP地址
 * @return string 客户端IP地址
 */
if (!function_exists('getClientIP')) {
    function getClientIP(): string {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                   'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $ip_list = explode(',', $_SERVER[$key]);
                foreach ($ip_list as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

/**
 * 发送API请求
 * @param string $url API地址
 * @param array $context 上下文选项
 * @param int $retry 重试次数
 * @return string|false API响应内容
 */
function sendApiRequest(string $url, array $context = [], int $retry = 2) {
    $context = array_merge([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'IP Query Tool/2.0'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ], $context);
    
    $context = stream_context_create($context);
    
    for ($i = 0; $i <= $retry; $i++) {
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            return $response;
        }
        // 重试前等待一段时间
        if ($i < $retry) {
            usleep(500000); // 500ms
        }
    }
    
    return false;
}

/**
 * 从位置字符串解析国家、地区和城市
 * @param string $location 位置字符串
 * @return array 包含国家、地区和城市的数组
 */
function parseLocation(string $location): array {
    $parts = explode(' ', $location);
    return [
        'country' => $parts[0] ?? '未知',
        'regionName' => $parts[1] ?? '未知',
        'city' => $parts[2] ?? '未知'
    ];
}

/**
 * 快速获取IP归属地信息（仅国家）
 * @param string $ip IP地址
 * @param array $context 上下文选项
 * @return string IP归属地信息
 */
function getIpLocationQuick(string $ip, array $context = []): string {
    $api_url = "https://apikey.net/api/?ipk=" . urlencode($ip);
    $response = sendApiRequest($api_url, $context, 1); // 只重试1次，快速响应
    
    if ($response !== false) {
        // 去除可能的首尾空格
        $location = trim($response);
        if (!empty($location)) {
            return $location;
        }
    }
    
    return '未知';
}

/**
 * 获取IP信息
 * @param string $ip IP地址
 * @param array $context 上下文选项
 * @param bool $quick_mode 是否使用快速模式（仅获取归属地）
 * @return array IP信息数组
 */
function getIpInfo(string $ip, array $context = [], bool $quick_mode = false): array {
    if ($quick_mode) {
        // 使用快速API获取仅归属地信息
        $location = getIpLocationQuick($ip, $context);
        return [
            'ipAddress' => $ip,
            'ipLong' => '未知',
            'ipLocation' => $location,
            'ipLocation2' => [
                'country' => $location,
                'regionName' => '未知',
                'city' => '未知',
                'lat' => '未知',
                'lon' => '未知',
                'isp' => '未知',
                'org' => '未知'
            ]
        ];
    }
    
    // 完整API查询
    $api_url = "https://apikey.net/api/?ip=" . urlencode($ip);
    $response = sendApiRequest($api_url, $context);
    
    if ($response === false) {
        // 如果完整API失败，回退到快速API
        $location = getIpLocationQuick($ip, $context);
        return [
            'ipAddress' => $ip,
            'ipLong' => '未知',
            'ipLocation' => $location,
            'ipLocation2' => [
                'country' => $location,
                'regionName' => '未知',
                'city' => '未知',
                'lat' => '未知',
                'lon' => '未知',
                'isp' => '未知',
                'org' => '未知'
            ]
        ];
    }
    
    $api_data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($api_data['code']) || $api_data['code'] != 200) {
        // 如果解析失败，回退到快速API
        $location = getIpLocationQuick($ip, $context);
        return [
            'ipAddress' => $ip,
            'ipLong' => '未知',
            'ipLocation' => $location,
            'ipLocation2' => [
                'country' => $location,
                'regionName' => '未知',
                'city' => '未知',
                'lat' => '未知',
                'lon' => '未知',
                'isp' => '未知',
                'org' => '未知'
            ]
        ];
    }
    
    $location = parseLocation($api_data['data']['location'] ?? '');
    
    return [
        'ipAddress' => $api_data['data']['ip'] ?? $ip,
        'ipLong' => $api_data['data']['ip_long'] ?? '未知',
        'ipLocation' => $api_data['data']['location'] ?? '未知',
        'ipLocation2' => array_merge($location, [
            'lat' => '未知',
            'lon' => '未知',
            'isp' => '未知',
            'org' => '未知'
        ])
    ];
}

/**
 * 调用IP查询API
 * @param string $input IP地址或域名
 * @return array API响应结果
 */
function callIPAPI(string $input = ''): array {
    static $cache = [];
    
    if (empty($input)) {
        $input = getClientIP();
    }
    
    // 检查缓存
    if (isset($cache[$input])) {
        return $cache[$input];
    }
    
    // 验证输入
    if (!filter_var($input, FILTER_VALIDATE_IP) && !filter_var($input, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return ['error' => '无效的IP地址或域名', 'input' => $input];
    }
    
    $api_url = "https://apikey.net/api/?ip=" . urlencode($input);
    $response = sendApiRequest($api_url);
    
    if ($response === false) {
        return ['error' => 'API请求失败', 'input' => $input];
    }
    
    $api_data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($api_data['code']) || $api_data['code'] != 200) {
        return ['error' => 'API返回异常', 'input' => $input];
    }
    
    // 转换API响应格式
    $result = [
        'code' => $api_data['code'],
        'msg' => $api_data['message'],
        'greeting' => $api_data['greeting'],
        'week' => $api_data['weekday'],
        'serverTime' => $api_data['server_time'],
        'input' => $input
    ];
    
    // 处理IP查询响应
    if (isset($api_data['data']['ip']) && !isset($api_data['data']['records'])) {
        $location = parseLocation($api_data['data']['location'] ?? '');
        
        $result['ipAddress'] = $api_data['data']['ip'];
        $result['ipLong'] = $api_data['data']['ip_long'];
        $result['ipLocation'] = $api_data['data']['location'];
        $result['ipLocation2'] = array_merge($location, [
            'lat' => '未知',
            'lon' => '未知',
            'isp' => '未知',
            'org' => '未知'
        ]);
    }
    // 处理域名查询响应
    elseif (isset($api_data['data']['records'])) {
        $result['domain'] = $api_data['data']['domain'];
        $result['resolvedIPs'] = [];
        
        // 对于域名查询，使用快速API模式获取IP归属地信息
        // 这样可以减少API调用次数，提高性能
        $result['resolvedIPs'] = array_map(function($record) {
            return getIpInfo($record['ip'], [], true); // 使用快速模式
        }, $api_data['data']['records']);
    }
    
    // 缓存结果
    $cache[$input] = $result;
    
    return $result;
}

// 初始化查询变量
$query_result = [];
$query_input = '';
$is_domain_query = false;
$resolved_ips = [];
$domain = '';

// 初始化查询历史
if (!isset($_SESSION['query_history'])) {
    $_SESSION['query_history'] = [];
}

// 统一处理POST和GET请求
$input_value = trim($_POST['ip'] ?? $_GET['ip'] ?? '');
if (!empty($input_value)) {
    $query_input = $input_value;
    $api_response = callIPAPI($query_input);
    
    // 处理API响应
    if (isset($api_response['error'])) {
        $query_result = $api_response;
    } elseif (isset($api_response['code']) && $api_response['code'] == 200) {
        $query_result = $api_response;
        
        // 检查是否为域名查询
        if (isset($api_response['resolvedIPs']) && is_array($api_response['resolvedIPs'])) {
            $is_domain_query = true;
            $resolved_ips = $api_response['resolvedIPs'];
            $domain = $api_response['domain'] ?? $query_input;
        }
    } else {
        $query_result = [
            'error' => 'API返回异常: ' . ($api_response['msg'] ?? '未知错误'),
            'input' => $query_input
        ];
    }
    
    // 更新查询历史（去重）
    $query_history = array_filter($_SESSION['query_history'], function($item) use ($query_input) {
        return $item !== $query_input;
    });
    array_unshift($query_history, $query_input);
    // 保持历史记录不超过10条
    $_SESSION['query_history'] = array_slice($query_history, 0, 10);
}

// 获取客户端IP和信息
$client_ip = getClientIP();
$client_api_response = callIPAPI($client_ip);

// 处理客户端数据
$client_data = [
    'ipLocation' => '未知位置',
    'greeting' => '您好',
    'week' => '未知日期',
    'serverTime' => date('Y年m月d日 H:i:s'),
    'ipAddress' => $client_ip,
    'ipLong' => '未知',
    'ipLocation2' => [
        'country' => '未知',
        'regionName' => '未知',
        'city' => '未知',
        'lat' => '未知',
        'lon' => '未知',
        'isp' => '未知',
        'org' => '未知'
    ]
];

// 如果API调用成功，更新客户端数据
if (isset($client_api_response['code']) && $client_api_response['code'] == 200) {
    // 合并数据，保留默认值作为后备
    $client_data = array_merge($client_data, $client_api_response);
    // 确保ipLocation2结构完整
    $client_data['ipLocation2'] = array_merge(
        $client_data['ipLocation2'], 
        $client_api_response['ipLocation2'] ?? []
    );
}

// 生成欢迎信息
$week = sprintf(
    ' 来自 %s的朋友，%s今天是 %s',
    $client_data['ipLocation'],
    $client_data['greeting'],
    $client_data['week']
);

// 获取服务器时间
$server_time = $client_data['serverTime'];

/**
 * 安全获取数组值并进行HTML转义
 * @param array $data 数据源数组
 * @param string $key 键名
 * @param string $default 默认值
 * @return string 处理后的值
 */
function getValue(array $data, string $key, string $default = ''): string {
    return isset($data[$key]) && !empty($data[$key]) ? htmlspecialchars($data[$key]) : $default;
}

/**
 * 从ipLocation2中提取详细位置信息
 * @param array $data 数据源数组
 * @param string $field 字段名
 * @return string 位置信息
 */
function getLocationDetail(array $data, string $field): string {
    return getValue($data['ipLocation2'] ?? [], $field, '');
}
?>
<!DOCTYPE html>
<html>
<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🌍 ipk.pw | 互联网IP地址库 - 免费互联网IP查询工具 | curl ipk.pw</title>
    <meta name="description" content="免费、开源、精准、无限制的全球IP地址与域名地理位置查询服务。支持命令行curl快速查询：curl ipk.pw 或 curl ipk.pw/?ip=119.29.29.29 或 ipk.pw/?ip=baidu.com、支持在操作系统的命令行工具（如Windows的CMD/PowerShell，或Mac/Linux的终端）中，使用 curl 命令来查询IP信息。">
    <meta name="keywords" content="互联网IP地址库,全球IP地址库,IP查询,地理位置查询,命令行IP查询,curl ipk.pw,免费IP库,开源IP库、Github.com/GitFn/ipk.pw、Gitee.com/GitFn/ipk.pw">
    <meta name="author" content="互联网IP地址库">
    
    <!-- Open Graph / Social Media -->
    <meta property="og:title" content="🌍 互联网IP地址库 - 专注提供实时、免费的客户端IP地理位置查询工具及API接口 | IPK: The Keystone & Core Key to Client IP">
    <meta property="og:description" content="互联网IP地址库免费、开源、精准、无限制的全球IP地址与域名地理位置查询服务">
    <meta property="og:url" content="https://ipk.pw">
    <meta property="og:type" content="website">
    
    <!-- Canonical URL -->
    <link rel="canonical" href="https://ipk.pw">
    <link rel="shortcut icon" type="image/x-icon" href="https://ssl.apikey.net/APIkey/favicons.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4e54c8;
            --secondary-color: #8f94fb;
            --glass-bg: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.2);
            --text-color: #fff;
        }
        
        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-x: hidden;
            position: relative;
            background: linear-gradient(270deg, #4e54c8, #8f94fb, #4e54c8, #8f94fb);
            background-size: 400% 400%;
            animation: GradientBackground 15s ease infinite;
        }
        
        .container {
            max-width: 1200px;
            width: 100%;
            margin-top: 30px;
            z-index: 1;
        }
        
        .glass-container {
            backdrop-filter: blur(12px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--glass-border);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .glass-card {
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 15px;
            color: var(--text-color);
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        
        .glass-card:hover {
            transform: translateY(-5px);
        }
        
        .query-hero {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .query-hero h1 {
            font-size: 3rem;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .query-form-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .query-input-group {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .query-input {
            flex: 1;
            text-align: center;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-color);
            padding: 15px 20px;
            font-size: 1.1rem;
        }
        
        .query-button {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            color: white;
            padding: 15px 30px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .query-button:hover {
            background: linear-gradient(45deg, var(--secondary-color), var(--primary-color));
            transform: translateY(-2px);
        }
        
        /* 突出显示查询结果 */
        .result-container {
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            border-left: 5px solid #ff8484;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.4);
            display: <?= $query_result ? 'block' : 'none' ?>;
        }
        
        .result-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .result-title {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .query-type-badge {
            background: linear-gradient(45deg, #4e54c8, #8f94fb);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .domain-badge {
            background: linear-gradient(45deg, #ff6b6b, #ff8e8e);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-around;
            padding: 15px 0;
            border-bottom: 1px solid #fff;
            align-items: center;
        }
        
        .label {
            font-weight: 700;
            color: #e9ecef;
            font-size: 1.1rem;
        }
        
        .value {
            color: #f8f9fa;
            text-align: right;
            max-width: 60%;
            word-break: break-word;
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        .ip-location {
            font-size: 1.3rem;
            font-weight: 700;
            color: #fff;
            padding: 10px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }
        
        .ip-coordinates {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 15px 0;
        }
        
        .coordinate-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 12px 20px;
            border-radius: 10px;
            text-align: center;
            min-width: 120px;
        }
        
        .coordinate-value {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        /* 多IP展示样式 */
        .ip-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin: 20px 0;
        }
        
        .ip-item {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            transition: all 0.3s;
        }
        
        .ip-item-header {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .ip-address {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .ip-location-short {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .ip-toggle {
            background: none;
            border: none;
            color: #fff;
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .ip-details {
            display: none;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .ip-details.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        .history-container {
            margin-top: 30px;
            text-align: center;
            display: <?= !empty($_SESSION['query_history']) ? 'block' : 'none' ?>;
        }
        
        .history-item {
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 4px 20px;
            margin: 5px;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .history-item:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        
        @keyframes GradientBackground {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .glass-container {
                padding: 20px;
            }
            
            .query-hero h1 {
                font-size: 2.2rem;
            }
            
            .query-input-group {
                flex-direction: column;
            }
            
            .query-button {
                width: 100%;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .result-title {
                font-size: 1.5rem;
            }
            
            .ip-coordinates {
                flex-direction: column;
                gap: 10px;
            }
            
            .ip-item-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        .white-link {
            text-decoration: none;
            color: white !important; /* !important 确保优先级最高 */
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="query-hero">
            <h2><i class="fas fa-globe-americas"></i> <a href="https://ipk.pw/chat/" class="white-link" title="欢迎使用互联网IP地址库智能AI助手！" target="_blank">互联网IP地址库 - https://ipk.pw</a>  <a class="white-link" style="font-size: 39px;" href="https://ipk.pw/chat/" target="_blank">🧠</a></h2>
            <p><i class="fas fa-info-circle"></i> <a href="https://ipk.pw/chat/" class="white-link" title="欢迎使用互联网IP地址库智能AI助手！" target="_blank">互联网IP地址库长期提供安全、稳定、免费、敏捷查询全球互联网IP地址和域名与位置的服务。</a></p>
            <p><i class="fas fa-info-circle"></i> <a href="https://ipk.pw/chat/" class="white-link" title="欢迎使用互联网IP地址库智能AI助手！" target="_blank">全面支持CLI命令行界面使用：curl https://ipk.pw/?ip=baidu.com 或 curl ipk.pw/?ip=119.29.29.29 或 curl ipk.pw</a></p>
        </div>
        
        <div class="glass-container">
            <div class="query-form-container">
                <form method="POST" action="">
                    <div class="query-input-group">
                        <input type="text" class="query-input" name="ip" 
                               placeholder="输入IP地址或域名，例如: 8.8.8.8 或 baidu.com" required
                               value="<?= htmlspecialchars($query_input) ?>">
                        <button type="submit" class="query-button">
                            <i class="fas fa-search me-2"></i>立即查询
                        </button>
                    </div>
                </form>
                
                <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap; margin: 15px 0;">
                    <a href="?ip=baidu.com" class="history-item">baidu.com</a>
                    <a href="?ip=114.114.114.114" class="history-item">114.114.114.114</a>
					<a href="?ip=119.29.29.29" class="history-item">119.29.29.29</a>
					<a href="?ip=223.5.5.5" class="history-item">223.5.5.5</a>
                    <a href="?ip=8.8.8.8" class="history-item">8.8.8.8</a>
                    <a href="?ip=google.com" class="history-item">Google.com</a>
                </div>
            </div>
            
            <?php if ($query_result !== ''): ?>
            <div class="result-container">
                <div class="result-header">
                    <div class="result-title">
                        <i class="fas fa-globe-americas"></i>查询结果
                        <span class="query-type-badge <?= $is_domain_query ? 'domain-badge' : '' ?>">
                            <?= $is_domain_query ? '域名' : 'IP地址' ?>
                        </span>
                    </div>
                </div>
                
                <?php if (isset($query_result['error'])): ?>
                    <div style="color: #ff6b6b; text-align:center; padding:12px; background:rgba(255,255,255,0.1); border-radius:8px;">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($query_result['error']) ?>
                    </div>
                <?php else: ?>
                    <!-- 显示API消息 -->
                    <div style="margin-bottom: 20px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 10px;">
                        <p style="margin: 0; font-style: italic; text-align: center;"><?= getValue($query_result, 'msg') ?></p>
                    </div>
                    
                    <?php if ($is_domain_query): ?>
                    <p style="text-align: center; margin-bottom: 20px; font-size: 1.2rem;">
                        域名 <strong><?= htmlspecialchars($domain) ?></strong> 解析到 <?= count($resolved_ips) ?> 个IP地址:
                    </p>
                    
                    <div class="ip-list">
                        <?php foreach ($resolved_ips as $index => $ip_info): ?>
                        <div class="ip-item">
                            <div class="ip-item-header">
                                <div>
                                    <div class="ip-address">
                                        <i class="fas fa-server"></i>
                                        IP <?= $index + 1 ?>: <?= getValue($ip_info, 'ipAddress') ?>
                                    </div>
                                    <div class="ip-location-short">
                                        <?= getValue($ip_info, 'ipLocation') ?>
                                    </div>
                                </div>
                                <button class="ip-toggle" onclick="toggleIpDetails(this)">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            
                            <div class="ip-details">
                                <div class="ip-location">
                                    <?= getValue($ip_info, 'ipLocation') ?>
                                </div>
                                
                                <div class="ip-coordinates">
                                    <div class="coordinate-item">
                                        <div>纬度</div>
                                        <div class="coordinate-value"><?= getLocationDetail($ip_info, 'lat') ?></div>
                                    </div>
                                    <div class="coordinate-item">
                                        <div>经度</div>
                                        <div class="coordinate-value"><?= getLocationDetail($ip_info, 'lon') ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="label">IP地址:</span>
                                        <span class="value"><?= getValue($ip_info, 'ipAddress') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">数字IP:</span>
                                        <span class="value"><?= getValue($ip_info, 'ipLong') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">国家:</span>
                                        <span class="value"><?= getLocationDetail($ip_info, 'country') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">地区:</span>
                                        <span class="value"><?= getLocationDetail($ip_info, 'regionName') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">城市:</span>
                                        <span class="value"><?= getLocationDetail($ip_info, 'city') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">ISP:</span>
                                        <span class="value"><?= getLocationDetail($ip_info, 'isp') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">组织:</span>
                                        <span class="value"><?= getLocationDetail($ip_info, 'org') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">时区:</span>
                                        <span class="value"><?= getLocationDetail($ip_info, 'timezone') ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="ip-location">
                        <?= getValue($query_result, 'ipLocation') ?>
                    </div>
                    
                    <div class="ip-coordinates">
                        <div class="coordinate-item">
                            <div>纬度</div>
                            <div class="coordinate-value"><?= getLocationDetail($query_result, 'lat') ?></div>
                        </div>
                        <div class="coordinate-item">
                            <div>经度</div>
                            <div class="coordinate-value"><?= getLocationDetail($query_result, 'lon') ?></div>
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="label">IP地址:</span>
                            <span class="value"><?= getValue($query_result, 'ipAddress', $query_input) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">数字IP:</span>
                            <span class="value"><?= getValue($query_result, 'ipLong') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">国家:</span>
                            <span class="value"><?= getLocationDetail($query_result, 'country') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">地区:</span>
                            <span class="value"><?= getLocationDetail($query_result, 'regionName') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">城市:</span>
                            <span class="value"><?= getLocationDetail($query_result, 'city') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">ISP:</span>
                            <span class="value"><?= getLocationDetail($query_result, 'isp') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">组织:</span>
                            <span class="value"><?= getLocationDetail($query_result, 'org') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">时区:</span>
                            <span class="value"><?= getLocationDetail($query_result, 'timezone') ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- 查询历史记录 -->
            <div class="history-container">
                <h5><i class="fas fa-history"></i> 查询历史记录（支持通过API查询IP或域名信息：https://ipk.pw/?ip=baidu.com 或 https://ipk.pw/?ip=119.29.29.29）</h5>
                <div style="text-align: center;margin-top: 10px;">
                    <?php if (!empty($_SESSION['query_history'])): ?>
                        <?php foreach ($_SESSION['query_history'] as $history_item): ?>
                            <a href="?ip=<?= urlencode($history_item) ?>" class="history-item"><?= htmlspecialchars($history_item) ?></a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align: center; opacity: 0.7;">暂无查询历史记录</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 40px;">
                <div class="glass-card">
                    <div style="text-align: center;border-bottom:1px solid var(--glass-border); font-weight:600; padding:15px 20px; border-radius:15px 15px 0 0;">
                        <h5><i class="fas fa-info-circle"></i> <?= $week.'，现在北京时间：' ?><span id="currentTime"><?= $server_time ?></span></h5>
                    </div>
                    <div style="padding: 20px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                            <div style="display: flex; justify-content: space-around; padding: 10px 0; border-bottom: 1px solid #fff;">
                                <span style="font-weight:600; color:#e9ecef;">IP地址:</span>
                                <span style="color:#f8f9fa;"><?= getValue($client_data, 'ipAddress', $client_ip) ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-around; padding: 10px 0; border-bottom: 1px solid #fff;">
                                <span style="font-weight:600; color:#e9ecef;">归属地:</span>
                                <span style="color:#f8f9fa;"><?= getValue($client_data, 'ipLocation') ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-around; padding: 10px 0; border-bottom: 1px solid #fff;">
                                <span style="font-weight:600; color:#e9ecef;">数字IP:</span>
                                <span style="color:#f8f9fa;"><?= getValue($client_data, 'ipLong') ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-around; padding: 10px 0; border-bottom: 1px solid #fff;">
                                <span style="font-weight:600; color:#e9ecef;">城市:</span>
                                <span style="color:#f8f9fa;"><?= getLocationDetail($client_data, 'city') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer style="text-align:center; padding:25px; width:100%;  backdrop-filter:blur(10px); border-radius:15px;">
        <p>© <?= date('Y') ?> 互联网IP地址库 - https://ipk.pw  <a href="https://github.com/GitFn/ipk.pw" class="history-item" title="本项目已开源至Github和Gitee" target="_blank">本项目已开源至Github和Gitee</a>  <a href="https://beian.miit.gov.cn" class="history-item" target="_blank">京ICP备2025153036号-1</a>  北京时间: <span id="fullTime"><?= $server_time ?></span></p>
    </footer>

    <script>
        // 更新时间
        function updateClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('zh-CN');
            const fullTimeStr = now.getFullYear() + '年' + 
                               String(now.getMonth() + 1).padStart(2, '0') + '月' + 
                               String(now.getDate()).padStart(2, '0') + '日 ' + timeStr;
            
            document.getElementById('currentTime').textContent = fullTimeStr;
            document.getElementById('fullTime').textContent = fullTimeStr;
        }
        
        updateClock();
        setInterval(updateClock, 1000);
        
        // 切换IP详情显示
        function toggleIpDetails(button) {
            const ipItem = button.closest('.ip-item');
            const details = ipItem.querySelector('.ip-details');
            const icon = button.querySelector('i');
            
            details.classList.toggle('show');
            
            if (details.classList.contains('show')) {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }
        
        // 页面加载时自动展开第一个IP的详情
        document.addEventListener('DOMContentLoaded', function() {
            const firstIpItem = document.querySelector('.ip-item');
            if (firstIpItem) {
                const details = firstIpItem.querySelector('.ip-details');
                const button = firstIpItem.querySelector('.ip-toggle');
                const icon = button.querySelector('i');
                
                details.classList.add('show');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
        });
    </script>
</body>
</html>
