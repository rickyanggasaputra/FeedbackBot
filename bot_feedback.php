<?php
$BOT_TOKEN  = "9201575539:AAGD7zfDARgaIreieXbFXn9z9O46j4n11a4";
$CHANNEL_ID = "@xChannelFairlyx";

function mask_user_id($user_id) {
    $s = (string)$user_id;
    $len = strlen($s);
    if ($len <= 4) {
        return str_repeat("*", $len);
    }
    return substr($s, 0, 2) . str_repeat("*", $len - 4) . substr($s, -2);
}

function tgRequest($method, $params = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot" . $BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        file_put_contents(__DIR__ . "/webhook_error.log", date("Y-m-d H:i:s") . " CURL ERR ($method): $curlErr\n", FILE_APPEND);
        return ["ok" => false, "description" => $curlErr];
    }

    $decoded = json_decode($res, true);
    if (!isset($decoded["ok"]) || !$decoded["ok"]) {
        file_put_contents(__DIR__ . "/webhook_error.log", date("Y-m-d H:i:s") . " API ERR ($method): " . $res . "\n", FILE_APPEND);
    }
    return $decoded;
}

$rawInput = file_get_contents("php://input");

// Jika dibuka di browser (GET) -> daftarkan webhook 1x saja & cek status
if (empty($rawInput)) {
    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "https";
    $webhookUrl = $scheme . "://" . $_SERVER["HTTP_HOST"] . strtok($_SERVER["REQUEST_URI"], "?");
    $setRes  = tgRequest("setWebhook", ["url" => $webhookUrl, "drop_pending_updates" => true]);
    $infoRes = tgRequest("getWebhookInfo");

    header("Content-Type: text/plain; charset=utf-8");
    echo "=== STATUS BOT FEEDBACK (PHP WEBHOOK) ===\n";
    echo "Target URL   : " . $webhookUrl . "\n";
    echo "Set Webhook  : " . ($setRes["description"] ?? json_encode($setRes)) . "\n";
    echo "URL Terdaftar: " . ($infoRes["result"]["url"] ?? "-") . "\n";
    echo "Pending Msg  : " . ($infoRes["result"]["pending_update_count"] ?? 0) . "\n";
    echo "Last Error   : " . ($infoRes["result"]["last_error_message"] ?? "No error (Active 24/7)") . "\n";
    exit;
}

// Proses pesan masuk dari Telegram (POST)
$update = json_decode($rawInput, true);
if (!$update || !isset($update["message"])) {
    http_response_code(200);
    exit("OK");
}

$message = $update["message"];
$chat_id = $message["chat"]["id"];

if (isset($message["text"]) && strpos($message["text"], "/start") === 0) {
    tgRequest("sendMessage", [
        "chat_id" => $chat_id,
        "text"    => "Hello! Please send your feedback image, and it will be automatically forwarded to " . $CHANNEL_ID . "."
    ]);
    http_response_code(200);
    exit("OK");
}

if (isset($message["photo"])) {
    $from = $message["from"] ?? [];
    $first_name = $from["first_name"] ?? "";
    $last_name  = $from["last_name"] ?? "";
    $full_name  = trim($first_name . " " . $last_name);
    if ($full_name === "") {
        $full_name = "User";
    }
    $username = (isset($from["username"]) && $from["username"] !== "") ? "@" . $from["username"] : "no_username";
    $user_id  = $from["id"] ?? 0;
    $masked_id = mask_user_id($user_id);
    $caption_text = (isset($message["caption"]) && $message["caption"] !== "") ? $message["caption"] : "(No caption)";

    $sender_info = "📩 New Feedback Image\n"
                 . "From: " . $full_name . " (" . $username . ")\n"
                 . "User ID: " . $masked_id . "\n"
                 . "Note: " . $caption_text;

    $photos = $message["photo"];
    $best_photo = end($photos);
    $photo_file_id = $best_photo["file_id"];

    $sendRes = tgRequest("sendPhoto", [
        "chat_id" => $CHANNEL_ID,
        "photo"   => $photo_file_id,
        "caption" => $sender_info
    ]);

    if (isset($sendRes["ok"]) && $sendRes["ok"] === true) {
        tgRequest("sendMessage", [
            "chat_id" => $chat_id,
            "text"    => "Thank you! Your feedback image has been forwarded."
        ]);
    } else {
        $errDesc = $sendRes["description"] ?? "Failed to forward to channel.";
        tgRequest("sendMessage", [
            "chat_id" => $chat_id,
            "text"    => "Failed to forward image to channel: " . $errDesc
        ]);
    }
}

http_response_code(200);
echo "OK";
?>
