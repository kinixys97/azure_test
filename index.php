<?php
session_start();

// 업비트 마켓 전체 목록 불러오기
function get_all_krw_markets() {
    $url = "https://api.upbit.com/v1/market/all?isDetails=false";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $markets = json_decode($response, true);
    $krw_markets = array_filter($markets, function ($item) {
        return strpos($item['market'], 'KRW-') === 0;
    });

    $market_map = [];
    foreach ($krw_markets as $item) {
        $market_map[$item['market']] = $item['korean_name'];
    }
    return $market_map;
}

$market_names = get_all_krw_markets();
$upbit_api_url = 'https://api.upbit.com/v1/candles/days';

if (!isset($_SESSION['markets'])) {
    $_SESSION['markets'] = ['KRW-BTC', 'KRW-ETH', 'KRW-XRP'];
}

if (isset($_POST['add_market'])) {
    $new_coin = $_POST['add_market'];
    if (!in_array($new_coin, $_SESSION['markets']) && isset($market_names[$new_coin])) {
        $_SESSION['markets'][] = $new_coin;
    }
    echo json_encode(['status' => 'success', 'message' => '코인 추가 성공']);
    exit;
}

if (isset($_POST['remove_market'])) {
    $coin_to_remove = $_POST['remove_market'];
    if (($key = array_search($coin_to_remove, $_SESSION['markets'])) !== false) {
        unset($_SESSION['markets'][$key]);
        $_SESSION['markets'] = array_values($_SESSION['markets']);
    }
    echo json_encode(['status' => 'success', 'message' => '코인 삭제 성공']);
    exit;
}

function get_upbit_data($market, $count = 20) {
    global $upbit_api_url;
    $url = $upbit_api_url . '?market=' . $market . '&count=' . $count;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$data = [];
foreach ($_SESSION['markets'] as $market) {
    $coin_data = get_upbit_data($market);
    $korean_name = $market_names[$market] ?? $market;
    if ($coin_data) {
        $latest_day = $coin_data[0];
        $data[] = [
            'market' => $market,
            'korean_name' => $korean_name,
            'current_price' => number_format($latest_day['trade_price']),
            'high_price' => number_format($latest_day['high_price']),
        ];
    } else {
        $data[] = [
            'market' => $market,
            'korean_name' => $korean_name,
            'current_price' => 'N/A',
            'high_price' => 'N/A',
        ];
    }
}

if (isset($_GET['ajax'])) {
    echo json_encode($data);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <title>업비트 KRW 정보</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background: #f9f9f9; margin: 0; padding: 0; }
    .header { text-align: center; padding: 30px; font-size: 28px; background-color: #80e0a7; color: white; margin-bottom: 30px; font-weight: 600; }
    .coin-actions { text-align: center; margin-bottom: 25px; }
    #coinInput { padding: 10px; font-size: 16px; width: 220px; margin-right: 8px; border-radius: 5px; border: 1px solid #ddd; }
    .button { padding: 10px 18px; font-size: 15px; background-color: #80e0a7; color: white; border: none; border-radius: 5px; cursor: pointer; }
    .container { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; padding: 0 20px 40px; }
    .coin { background-color: white; border-radius: 12px; border: 1px solid #ddd; padding: 18px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); position: relative; }
    .coin h2 { font-size: 18px; margin-bottom: 10px; }
    .coin p { font-size: 15px; margin: 6px 0; }
    .current-price { font-size: 17px; font-weight: 600; padding: 4px 8px; border-radius: 5px; display: inline-block; }
    .price-up { color: #28a745; background-color: rgba(40,167,69,0.1); }
    .price-down { color: #e74c3c; background-color: rgba(231,76,60,0.1); }
    .flash { animation: flash 0.3s ease-in-out; }
    @keyframes flash { from { background-color: yellow; } to { background-color: inherit; } }
    .delete-button { position: absolute; right: 15px; bottom: 15px; background-color: #e74c3c; color: white; border: none; padding: 7px 12px; border-radius: 5px; cursor: pointer; font-size: 13px; }
    .notification { display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background-color: #333; color: white; padding: 12px; border-radius: 8px; font-size: 14px; z-index: 1000; }
    .notification.success { background-color: #28a745; }
    .notification.error { background-color: #e74c3c; }
  </style>
</head>
<body>

<div class="header">KRW_Coin Info</div>

<div class="coin-actions">
    <input type="text" id="coinInput" placeholder="코인 입력 (예: KRW-LTC)">
    <button class="button" id="addCoinButton">Add Coin</button>
    <button class="button toggle" id="toggleUpdate">Update : On</button>
</div>

<div class="container" id="coinContainer"></div>
<div id="notification" class="notification"></div>

<script>
let autoUpdate = true;
let intervalId = setInterval(updateCoinList, 1000);

document.getElementById('toggleUpdate').addEventListener('click', () => {
    autoUpdate = !autoUpdate;
    const toggleBtn = document.getElementById('toggleUpdate');
    toggleBtn.textContent = `Update : ${autoUpdate ? 'On' : 'Off'}`;
    autoUpdate ? intervalId = setInterval(updateCoinList, 1000) : clearInterval(intervalId);
});

function showNotification(message, type = 'success') {
    const noti = document.getElementById('notification');
    noti.textContent = message;
    noti.className = `notification ${type}`;
    noti.style.display = 'block';
    setTimeout(() => { noti.style.display = 'none'; }, 3000);
}

document.getElementById('addCoinButton').addEventListener('click', () => {
    const coin = document.getElementById('coinInput').value.trim();
    if (coin) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `add_market=${coin}`
        }).then(res => res.json()).then(data => {
            showNotification(data.message);
            updateCoinList();
        }).catch(err => {
            showNotification('추가 실패: ' + err.message, 'error');
        });
    }
});

function removeCoin(market) {
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `remove_market=${market}`
    }).then(res => res.json()).then(data => {
        showNotification(data.message);
        updateCoinList();
    }).catch(err => {
        showNotification('삭제 실패: ' + err.message, 'error');
    });
}

const prevPrices = {};

function updateCoinList() {
    fetch('?ajax=true')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('coinContainer');
            container.innerHTML = '';
            data.forEach(coin => {
                const market = coin.market;
                const korean = coin.korean_name;
                const current = parseFloat(coin.current_price.replace(',', ''));
                const prev = prevPrices[market] || current;
                const priceClass = current > prev ? 'price-up' : (current < prev ? 'price-down' : '');
                prevPrices[market] = current;

                const coinEl = document.createElement('div');
                coinEl.className = 'coin';
                coinEl.innerHTML = `
                    <h2>${korean} (${market})</h2>
                    <p>20일 최고가: ${coin.high_price}원</p>
                    <p class="current-price ${priceClass}">${coin.current_price}원</p>
                    <button class="delete-button" onclick="removeCoin('${market}')">삭제</button>
                `;

                const priceEl = coinEl.querySelector('.current-price');
                if (current !== prev) {
                    priceEl.classList.add('flash');
                    setTimeout(() => priceEl.classList.remove('flash'), 300);
                }

                container.appendChild(coinEl);
            });
        });
}
</script>

</body>
</html>
