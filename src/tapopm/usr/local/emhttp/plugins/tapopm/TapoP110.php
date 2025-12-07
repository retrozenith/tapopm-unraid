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
                "requestTimeMils" => 0
            ]
        ];

        $response = $this->request($url, $payload);
        if (!isset($response['result']['key'])) {
            throw new Exception("Handshake failed: No key in response");
        }

        $encryptedKey = $response['result']['key'];
        $decodedKey = "";
        
        // Decrypt the key using our private key
        // Python used PKCS1_v1_5
        if (!openssl_private_decrypt(base64_decode($encryptedKey), $decodedKey, $this->privateKey, OPENSSL_PKCS1_PADDING)) {
             throw new Exception("Handshake decryption failed");
        }

        // First 16 bytes = AES Key, Next 16 bytes = AES IV
        $this->aesKey = substr($decodedKey, 0, 16);
        $this->aesIv = substr($decodedKey, 16, 16);
        
        // Cookie is already set in the request method logic if using stream context, but here we need to capture it?
        // Actually, my simple request method might not capture headers. I need to implement a robust request method.
        // For now, let's assume valid handshake.
    }

    public function login() {
        $url = "http://{$this->ip}/app";
        
        // Encode credentials
        $encodedPassword = base64_encode($this->password);
        $encodedEmail = base64_encode(sha1($this->email)); // Check if this matches Python logic: hex string or raw binary sha1?
        // Python: hashlib.sha1(b_arr).digest() then hex string. THEN base64.
        $encodedEmail = base64_encode($this->sha1_hex($this->email));

        $payload = [
            "method" => "login_device",
            "params" => [
                "username" => $encodedEmail,
                "password" => $encodedPassword
            ],
            "requestTimeMils" => 0
        ];

        $resp = $this->secureRequest($url, $payload);
        if (isset($resp['result']['token'])) {
            $this->token = $resp['result']['token'];
        } else {
             throw new Exception("Login failed");
        }
    }

    public function getEnergyUsage() {
        if (!$this->token) $this->login();
        
        $url = "http://{$this->ip}/app?token={$this->token}";
        $payload = [
            "method" => "get_energy_usage",
            "requestTimeMils" => 0,
        ];

        // Try standard call
        $data = $this->secureRequest($url, $payload);
        if (isset($data['error_code']) && $data['error_code'] != 0) {
             throw new Exception("Error getting energy: " . $data['error_code']);
        }
        return $data['result'];
    }

    private function sha1_hex($str) {
        return sha1($str);
    }

    private function secureRequest($url, $data) {
        $jsonPayload = json_encode($data);
        $encryptedPayload = $this->aesEncrypt($jsonPayload);
        
        $wrapper = [
            "method" => "securePassthrough",
            "params" => [
                "request" => $encryptedPayload
            ]
        ];

        $response = $this->request($url, $wrapper);
        
        if (isset($response['result']['response'])) {
            $decrypted = $this->aesDecrypt($response['result']['response']);
            return json_decode($decrypted, true);
        }
        return $response;
    }

    private function aesEncrypt($data) {
        // defined in Python: AES.MODE_CBC
        // Padding: PKCS7 (default in openssl_encrypt)
        return base64_encode(openssl_encrypt($data, 'aes-128-cbc', $this->aesKey, OPENSSL_RAW_DATA, $this->aesIv));
    }

    private function aesDecrypt($data) {
        return openssl_decrypt(base64_decode($data), 'aes-128-cbc', $this->aesKey, OPENSSL_RAW_DATA, $this->aesIv);
    }
    
    private function request($url, $payload) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        if ($this->cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }

        // Capture headers to get Cookie
        curl_setopt($ch, CURLOPT_HEADER, 1);
        
        $response = curl_exec($ch);
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
        
        return json_decode($body, true);
    }
}
?>
