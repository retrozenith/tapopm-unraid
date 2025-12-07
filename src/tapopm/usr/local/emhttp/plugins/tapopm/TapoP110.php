<?php

class TapoP110 {
    private $ip;
    private $email;
    private $password;
    private $cookie;
    private $token;
    private $aesKey;
    private $aesIv;
    private $privateKey;
    private $publicKey;

    public function __construct($ip, $email, $password) {
        $this->ip = $ip;
        $this->email = $email;
        $this->password = $password;
        $this->generateKeyPair();
    }

    private function generateKeyPair() {
        $config = array(
            "digest_alg" => "sha512",
            "private_key_bits" => 1024,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );
        $res = openssl_pkey_new($config);
        if (!$res) {
            throw new Exception("OpenSSL pkey generation failed: " . openssl_error_string());
        }
        openssl_pkey_export($res, $this->privateKey);
        $details = openssl_pkey_get_details($res);
        $this->publicKey = $details['key'];
    }

    public function handshake() {
        $url = "http://{$this->ip}/app";
        $payload = [
            "method" => "handshake",
            "params" => [
                "key" => $this->publicKey,
                "requestTimeMils" => (int)(microtime(true) * 1000)
            ]
        ];

        $response = $this->request($url, $payload);
        if (!isset($response['result']['key'])) {
            throw new Exception("Handshake failed: No key in response. Raw Response: " . json_encode($response));
        }

        $encryptedKey = $response['result']['key'];
        // ... (rest of function)
    }

    // ...

    private function request($url, $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // Timeout needed?
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }

        // Capture headers to get Cookie
        curl_setopt($ch, CURLOPT_HEADER, 1);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
             $err = curl_error($ch);
             curl_close($ch);
             throw new Exception("Curl Error connecting to $url: $err");
        }

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        
        // Extract Cookie
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
        foreach($matches[1] as $item) {
            if (strpos($item, "TP_SESSIONID") !== false) {
                $this->cookie = $item;
            }
        }
        
        curl_close($ch);
        
        $decoded = json_decode($body, true);
        if ($decoded === null) {
            throw new Exception("Invalid JSON response from Device. Raw Body: " . $body);
        }
        
        return $decoded;
    }
}
?>
