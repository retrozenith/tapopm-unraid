package tapo

// Generic Request Structure
type Request struct {
	Method          string      `json:"method"`
	Params          interface{} `json:"params,omitempty"`
	RequestTimeMils int64       `json:"requestTimeMils,omitempty"`
	TerminalUUID    string      `json:"terminalUUID,omitempty"`
}

// Handshake Params
type HandshakeParams struct {
	Key string `json:"key"`
    RequestTimeMils int64 `json:"requestTimeMils"`
}

// Handshake Response
type HandshakeResponse struct {
	ErrorCode int `json:"error_code"`
	Result    struct {
		Key string `json:"key"` // Encrypted AES Key + IV
	} `json:"result"`
}

// Secure Passthrough
type SecureRequest struct {
	Method string `json:"method"`
	Params struct {
		Request string `json:"request"` // Encrypted payload
	} `json:"params"`
}

type SecureResponse struct {
    ErrorCode int `json:"error_code"`
    Result struct {
        Response string `json:"response"` // Encrypted response
    } `json:"result"`
}
