<?php
header('Content-Type: application/json');

function fetchHotStocks() {
    // 密钥列表
    $apiKeys = [
        '44E8202F-1FF0-4D16-9875-ADBB5FFB4661',
        'C08B784D-E5FF-4D8E-9C9E-EE65D3D94284',
        'qsdcb567347iiohgdfd'
    ];
    
    // 记录当前尝试结果
    $lastError = '';
    $response = null;
    
    // 遍历尝试所有密钥
    foreach ($apiKeys as $key) {
        $url = "http://api.biyingapi.com/higg/jlr/$key";
        
        // 初始化CURL
        $ch = curl_init();
        
        // 设置CURL选项
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // 伪装请求头，避免被识别为爬虫
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Referer: http://www.biyingapi.com/',
            'Origin: http://www.biyingapi.com'
        ]);
        
        // 执行请求
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // 关闭CURL
        curl_close($ch);
        
        // 检查请求是否成功
        if (!$error && $httpCode == 200) {
            // 检查响应内容是否有效
            $data = json_decode($response, true);
            if (json_last_error() == JSON_ERROR_NONE && is_array($data) && !empty($data)) {
                // 返回有效响应
                return $response;
            }
            $lastError = '接口返回数据无效';
        } else {
            $lastError = $error ? '请求失败: ' . $error : '服务器返回错误状态码: ' . $httpCode;
        }
        
        // 记录切换密钥日志
        error_log("密钥 $key 请求失败，错误: $lastError，尝试下一个密钥");
    }
    
    // 所有密钥都失败了，返回错误信息
    return json_encode(['error' => '所有API密钥请求均失败，最后错误: ' . $lastError]);
}


// 执行请求并输出结果
echo fetchHotStocks();
?>
