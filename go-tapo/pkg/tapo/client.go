package tapo

import (
	"bytes"
	"crypto/rsa"
	"crypto/sha1"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/cookiejar"
	"strings"
	"time"
    "github.com/google/uuid"
)

type Client struct {
	IP       string
	Email    string
	Password string
    Debug    bool

	client     *http.Client
	privateKey *rsa.PrivateKey
	publicKey  []byte // PEM
	aesKey     []byte
	aesIv      []byte
	token      string
    terminalUUID string
}

func NewClient(ip, email, password string) (*Client, error) {
	jar, _ := cookiejar.New(nil)
	return &Client{
		IP:       ip,
		Email:    email,
		Password: password,
        terminalUUID: uuid.New().String(),
		client: &http.Client{
			Timeout: 10 * time.Second,
			Jar:     jar,
		},
	}, nil
}

func (c *Client) Handshake() error {
	keys, err := GenerateKeyPair()
	if err != nil {
		return err
	}
	c.privateKey = keys.PrivateKey
	c.publicKey = keys.PublicKey

    // Strip newlines for compatibility with Tapo Firmware expectations
    cleanKey := strings.ReplaceAll(string(c.publicKey), "\n", "")
    cleanKey = strings.ReplaceAll(cleanKey, "\r", "")

	ts := time.Now().UnixMilli()
	req := Request{
		Method: "handshake",
		Params: HandshakeParams{
			Key:             cleanKey,
            RequestTimeMils: ts,
		},
        RequestTimeMils: ts,
	}

	var resp HandshakeResponse
	if err := c.doRequest(c.url("app"), req, &resp); err != nil {
		return err
	}

	if resp.ErrorCode != 0 {
		return fmt.Errorf("handshake failed with code %d", resp.ErrorCode)
	}

	decodedKey, err := base64.StdEncoding.DecodeString(resp.Result.Key)
	if err != nil {
		return err
	}

	decrypted, err := DecryptRSA(c.privateKey, decodedKey)
	if err != nil {
		return err
	}

    if len(decrypted) != 32 {
        return fmt.Errorf("invalid handshake key length: %d", len(decrypted))
    }

	c.aesKey = decrypted[:16]
	c.aesIv = decrypted[16:]

	return nil
}

func (c *Client) Login() error {
    // Email Hash: Base64(Hex(SHA1(Email)))
    h := sha1.New()
	h.Write([]byte(c.Email))
	hash := h.Sum(nil)
    hexEmail := fmt.Sprintf("%x", hash)
    encodedEmail := base64.StdEncoding.EncodeToString([]byte(hexEmail))
    
    encodedPass := base64.StdEncoding.EncodeToString([]byte(c.Password))

	payload := map[string]string{
		"username": encodedEmail,
		"password": encodedPass,
	}
    
    resp, err := c.SecureRequest("login_device", payload)
    if err != nil {
        return err
    }
    
    if token, ok := resp["token"].(string); ok {
        c.token = token
        return nil
    }
    
    return fmt.Errorf("login failed, no token in response: %v", resp)
}

func (c *Client) GetEnergyUsage() (map[string]interface{}, error) {
    if c.token == "" {
        if err := c.Login(); err != nil {
            return nil, err
        }
    }
    return c.SecureRequest("get_energy_usage", nil)
}

func (c *Client) SecureRequest(method string, params interface{}) (map[string]interface{}, error) {
    innerPayload := Request{
        Method: method,
        Params: params,
        RequestTimeMils: time.Now().UnixMilli(),
    }
    
    jsonBytes, err := json.Marshal(innerPayload)
    if err != nil {
        return nil, err
    }
    
    encrypted, err := AesEncrypt(c.aesKey, c.aesIv, jsonBytes)
    if err != nil {
        return nil, err
    }
    
    wrapper := Request{
        Method: "securePassthrough",
        Params: map[string]string{
            "request": base64.StdEncoding.EncodeToString(encrypted),
        },
        RequestTimeMils: time.Now().UnixMilli(),
    }
    
    url := c.url("app")
    if c.token != "" {
        url += "?token=" + c.token
    }
    
    var resp SecureResponse
    if err := c.doRequest(url, wrapper, &resp); err != nil {
        return nil, err
    }
    
    if resp.ErrorCode != 0 {
        return nil, fmt.Errorf("secure request failed code %d", resp.ErrorCode)
    }
    
    decryptedResp, err := base64.StdEncoding.DecodeString(resp.Result.Response)
    if err != nil {
        return nil, err
    }
    
    decryptedData, err := AesDecrypt(c.aesKey, c.aesIv, decryptedResp)
    if err != nil {
        return nil, err
    }
    
    var finalResult map[string]interface{}
    if err := json.Unmarshal(decryptedData, &finalResult); err != nil {
        return nil, err
    }
    
    if errCode, ok := finalResult["error_code"].(float64); ok && errCode != 0 {
         return nil, fmt.Errorf("api error %d", int(errCode))
    }
    
    if result, ok := finalResult["result"].(map[string]interface{}); ok {
        return result, nil
    }
    
    return finalResult, nil 
}

func (c *Client) doRequest(url string, req interface{}, resp interface{}) error {
	data, err := json.Marshal(req)
	if err != nil {
		return err
	}

    if c.Debug {
        fmt.Printf("[DEBUG] Request to %s: %s\n", url, string(data))
    }

	r, err := http.NewRequest("POST", url, bytes.NewBuffer(data))
	if err != nil {
		return err
	}
	r.Header.Set("Content-Type", "application/json")

	res, err := c.client.Do(r)
	if err != nil {
		return err
	}
	defer res.Body.Close()

    body, _ := io.ReadAll(res.Body)
    
    if c.Debug {
        fmt.Printf("[DEBUG] Response: %s\n", string(body))
    }
    
	if err := json.Unmarshal(body, resp); err != nil {
		return fmt.Errorf("json parse error: %v, body: %s", err, string(body))
	}
	return nil
}

func (c *Client) url(path string) string {
	return fmt.Sprintf("http://%s/%s", c.IP, path)
}
